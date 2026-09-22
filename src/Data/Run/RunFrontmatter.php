<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Run;

use Astro2006\LaravelAudit\Support\Read;
use Astro2006\LaravelAudit\Support\Regex;

final readonly class RunFrontmatter
{
    public function __construct(
        public string $runId = '',
        public string $status = '',
        public string $startedAt = '',
        public bool $preflightComplete = false,
        public ?string $preflightFrom = null,
        public ?string $model = null,
        public ?string $gitCommit = null,
        public bool $gitDirty = false,
        public string $audits = '',
    ) {}

    public static function read(string $path): ?self
    {
        if (! is_file($path)) {
            return null;
        }

        $block = Regex::groups('/^---\n(.*?)\n---/s', (string) file_get_contents($path));

        if ($block === null) {
            return null;
        }

        [$body] = $block;
        $data = [];

        foreach (explode("\n", $body) as $line) {
            $pair = Regex::groups('/^([a-z_]+):\s*(.*?)\s*(?:#.*)?$/', $line);

            if ($pair !== null) {
                [$key, $value] = $pair;
                $data = [...$data, $key => $value];
            }
        }

        return new self(
            runId: Read::string($data, 'run_id'),
            status: Read::string($data, 'status'),
            startedAt: Read::string($data, 'started_at'),
            preflightComplete: Read::string($data, 'preflight') === 'complete',
            preflightFrom: Read::nullableString($data, 'preflight_from'),
            model: Read::nullableString($data, 'model'),
            gitCommit: Read::nullableString($data, 'git_commit'),
            gitDirty: Read::string($data, 'git_dirty') === 'true',
            audits: Read::string($data, 'audits'),
        );
    }

    public function codeState(): string
    {
        return ($this->gitCommit ?? '').($this->gitDirty ? '+dirty' : '');
    }
}
