<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use Illuminate\Support\Arr;

final readonly class AuditConfig
{
    public const array DEFAULTS = [
        'tool' => [
            'wait_timeout' => 100,
        ],

        'agent' => [
            'skills' => 'auto',
            'drivers' => [
                'codex' => ['config' => []],
            ],
            'boost_tools' => [
                'application-info',
                'database-connections',
                'database-schema',
                'get-absolute-url',
                'list-artisan-commands',
                'list-available-config-keys',
                'list-routes',
                'search-docs',
            ],
        ],

        'audits' => [
            'tests' => ['enabled' => true, 'preflight' => ['about', 'stack', 'loc', 'routes', 'tests', 'browser', 'phpstan']],
            'frontend' => ['enabled' => true, 'preflight' => ['about', 'stack', 'loc', 'routes', 'translations', 'candidates', 'tests', 'browser']],
            'backend' => ['enabled' => true, 'preflight' => ['about', 'stack', 'loc', 'pint', 'routes', 'migrations', 'candidates', 'tests', 'phpstan']],
            'dependencies' => ['enabled' => true, 'preflight' => ['about', 'stack', 'composer']],
        ],

        'preflight' => [
            'allow_shared_database' => false,
            'tests' => [
                'command' => null,
                'timeout' => 1800,
            ],
            'browser' => [
                'command' => null,
                'testsuite' => 'Browser',
                'path' => 'tests/Browser',
                'build_assets' => 'if-missing',
                'timeout' => 1200,
                'max_artifacts' => 10,
            ],
            'phpstan' => [
                'level' => null,
                'stub_level' => 5,
                'memory_limit' => '2G',
                'timeout' => 1800,
                'parallel' => true,
            ],
            'composer' => [
                'outdated' => true,
                'licenses' => true,
            ],
            'license_allowlist' => [
                'MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'ISC', 'MPL-2.0',
                'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-3.0-only', 'LGPL-3.0-or-later',
            ],
        ],

        'max_findings_per_task' => 5,
        'max_files_per_task' => 25,
        'skills_per_task' => 2,
        'skill_max_chars' => 2000,
        'snapshot_rows' => 10,
        'summary' => 'generated',
        'exclude_paths' => ['vendor', 'node_modules', 'storage', 'public/build', 'bootstrap/cache'],

        'risk_points' => [
            'weights' => ['critical' => 20, 'high' => 10, 'medium' => 4, 'low' => 1, 'info' => 0],
            'confidence' => ['confirmed' => 1.0, 'likely' => 0.7, 'suspected' => 0.3],
        ],
    ];

    public const array LEGACY_KEYS = [
        'orchestrator' => null,
        'agent.orchestrator.timeout' => null,
        'agent.orchestrator.max_turns' => null,
        'agent.orchestrator.wait_timeout' => 'tool.wait_timeout',
        'agent.mode' => 'agent.max_concurrency',
        'agent.concurrency' => 'agent.max_concurrency',
        'agent.verify.min_severity' => null,
        'agent.binary' => 'agent.drivers.claude_code.binary',
        'agent.effort' => 'agent.drivers.claude_code.effort',
        'agent.max_budget_usd' => 'agent.drivers.claude_code.max_budget_usd',
        'agent.drivers.claude_code.model' => 'agent.model',
        'agent.drivers.opencode.model' => 'agent.model',
        'agent.drivers.codex.model' => 'agent.model',
    ];

    /**
     * @param  array<string, mixed>  $values  the merged config
     * @param  array<string, string|null>  $legacy  legacy key => replacement, for keys the project still sets
     */
    private function __construct(
        private array $values,
        private array $legacy,
    ) {}

    /** @param array<string, mixed> $config config('audit') */
    public static function load(array $config): self
    {
        $package = require dirname(__DIR__, 2).'/config/audit.php';
        $values = self::merge(self::merge(self::normalize(self::DEFAULTS), self::normalize((array) $package)), self::normalize($config));

        $legacy = array_filter(
            self::LEGACY_KEYS,
            fn (string $old): bool => Arr::get($config, $old) !== null,
            ARRAY_FILTER_USE_KEY,
        );

        return new self($values, $legacy);
    }

    public function get(string $key): mixed
    {
        return Arr::get($this->values, $key);
    }

    public function string(string $key): string
    {
        return Read::string($this->values, $key);
    }

    public function nullableString(string $key): ?string
    {
        $value = Read::nullableString($this->values, $key);

        return $value === null || trim($value) === '' ? null : trim($value);
    }

    public function int(string $key): int
    {
        return Read::int($this->values, $key);
    }

    public function nullableFloat(string $key): ?float
    {
        return Read::nullableFloat($this->values, $key);
    }

    public function bool(string $key): bool
    {
        return Read::bool($this->values, $key);
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        return Read::strings($this->values, $key);
    }

    /** @return array<mixed> */
    public function array(string $key): array
    {
        return Read::array($this->values, $key);
    }

    /** @return array<string, string|null> legacy key => the key that replaces it, null = none */
    public function legacyKeys(): array
    {
        return $this->legacy;
    }

    /**
     * Short forms: `'audits' => ['tests' => false]` and
     * `'agent' => ['verify' => false]` stand for their `enabled` key.
     *
     * @param  array<mixed>  $config
     * @return array<mixed>
     */
    private static function normalize(array $config): array
    {
        foreach ((array) Arr::get($config, 'audits', []) as $audit => $enabled) {
            if (is_bool($enabled)) {
                $config['audits'][$audit] = ['enabled' => $enabled];
            }
        }

        $verify = Arr::get($config, 'agent.verify');

        if (is_bool($verify)) {
            $config['agent']['verify'] = ['enabled' => $verify];
        }

        return $config;
    }

    /**
     * @param  array<mixed>  $base
     * @param  array<mixed>  $override
     * @return array<mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $current = Arr::get($base, $key);
            $base[$key] = is_array($value) && ! array_is_list($value) && is_array($current) && ! array_is_list($current)
                ? self::merge($current, $value)
                : $value;
        }

        return $base;
    }
}
