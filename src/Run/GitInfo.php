<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Illuminate\Support\Facades\Process;

final readonly class GitInfo
{
    public function __construct(
        public ?string $branch = null,
        public ?string $commit = null,
        public ?bool $dirty = null,
    ) {}

    public static function read(string $basePath): self
    {
        if (! is_dir($basePath.'/.git')) {
            return new self;
        }

        $git = fn (array $arguments): ?string => ($result = Process::path($basePath)->timeout(10)->run(['git', ...$arguments]))->successful()
            ? trim($result->output())
            : null;

        $status = $git(['status', '--porcelain']);

        return new self(
            branch: $git(['rev-parse', '--abbrev-ref', 'HEAD']),
            commit: $git(['rev-parse', '--short', 'HEAD']),
            dirty: $status === null ? null : $status !== '',
        );
    }
}
