<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Support;

use Illuminate\Support\Arr;

/**
 * The audit config, read once. Every value has exactly one default: the
 * keys people change are in config/audit.php, everything else is in
 * DEFAULTS below. Both are merged deeply under the project's config, so a
 * published config/audit.php only needs the keys it changes and never
 * loses keys a newer package version adds. Lists replace, they never merge.
 */
final readonly class AuditConfig
{
    /** Settings that rarely change. Any of them can still be set in config/audit.php. */
    public const array DEFAULTS = [
        'agent' => [
            'verify' => ['min_severity' => 'medium'],
            'drivers' => [
                'codex' => ['config' => []],   // extra -c overrides, e.g. 'model_provider="ollama"'
            ],
            // Read-only Boost MCP tools the agents may use. Tinker, database
            // queries, log readers and get-config are never allowed.
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

        // The preflight steps each audit needs: about, stack, loc, pint,
        // routes, translations, composer, tests, browser, phpstan.
        'audits' => [
            'tests' => ['enabled' => true, 'preflight' => ['about', 'stack', 'loc', 'routes', 'tests', 'browser', 'phpstan']],
            'frontend' => ['enabled' => true, 'preflight' => ['about', 'stack', 'loc', 'routes', 'translations', 'tests', 'browser']],
            'backend' => ['enabled' => true, 'preflight' => ['about', 'stack', 'loc', 'pint', 'routes', 'tests', 'phpstan']],
            'dependencies' => ['enabled' => true, 'preflight' => ['about', 'stack', 'composer']],
        ],

        'preflight' => [
            // Tests only run when their database is provably not the app database.
            'allow_shared_database' => false,
            'tests' => [
                'command' => null,          // null = php artisan test with JUnit (+ Clover)
                'timeout' => 1800,
            ],
            'browser' => [
                'command' => null,
                'testsuite' => 'Browser',       // excluded from the regular test step
                'path' => 'tests/Browser',      // fallback if the suite does not exist
                'build_assets' => 'if-missing', // if-missing | always | never
                'timeout' => 1200,
                'max_artifacts' => 10,
            ],
            'phpstan' => [
                'level' => null,        // null = level from phpstan.neon
                'stub_level' => 5,      // only for a phpstan.neon created by audit:install
                'memory_limit' => '2G',
                'timeout' => 1800,
                'parallel' => true,     // runs next to the tests; false = after them
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

        'max_findings_per_task' => 5,   // raised automatically for tasks with more required findings
        'max_files_per_task' => 25,
        'snapshot_rows' => 15,          // rows per preflight snapshot in the prompt
        'summary' => 'generated',       // generated | agent
        'exclude_paths' => ['vendor', 'node_modules', 'storage', 'public/build', 'bootstrap/cache'],

        // Experimental, no grade: sum of weight × confidence factor.
        'risk_points' => [
            'weights' => ['critical' => 20, 'high' => 10, 'medium' => 4, 'low' => 1, 'info' => 0],
            'confidence' => ['confirmed' => 1.0, 'likely' => 0.7, 'suspected' => 0.3],
        ],
    ];

    /** Keys that are no longer read => the key that replaces them. */
    public const array LEGACY_KEYS = [
        'agent.binary' => 'agent.drivers.claude_code.binary',
        'agent.effort' => 'agent.drivers.claude_code.effort',
        'agent.max_budget_usd' => 'agent.drivers.claude_code.max_budget_usd',
        'agent.drivers.claude_code.model' => 'agent.model',
        'agent.drivers.opencode.model' => 'agent.model',
        'agent.drivers.codex.model' => 'agent.model',
    ];

    /**
     * @param  array<string, mixed>  $values  the merged config
     * @param  array<string, string>  $legacy  legacy key => replacement, for keys the project still sets
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
            fn (string $new, string $old): bool => Arr::get($config, $old) !== null,
            ARRAY_FILTER_USE_BOTH,
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

    /** Null for a missing or empty value. */
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

    /** @return array<string, string> legacy key => the key that replaces it */
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
        foreach (array_keys((array) ($config['audits'] ?? [])) as $audit) {
            if (is_bool($config['audits'][$audit])) {
                $config['audits'][$audit] = ['enabled' => $config['audits'][$audit]];
            }
        }

        if (is_bool($config['agent']['verify'] ?? null)) {
            $config['agent']['verify'] = ['enabled' => $config['agent']['verify']];
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
            $base[$key] = is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null) && ! array_is_list($base[$key])
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
