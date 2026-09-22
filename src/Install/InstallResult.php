<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

/**
 * What `audit:install` did, checked against the files afterwards.
 */
final readonly class InstallResult
{
    /**
     * @param  list<string>  $declined  packages the user said no to
     * @param  list<string>  $failed  commands that failed
     * @param  list<string>  $manualCommands  setup commands the user has to run
     * @param  list<string>  $manualKeys  message keys for things only the user can set up
     */
    public function __construct(
        public string $outputPath,
        public int $installed = 0,
        public bool $stubCreated = false,
        public int $stubLevel = 5,
        public array $declined = [],
        public array $failed = [],
        public array $manualCommands = [],
        public array $manualKeys = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->failed === [];
    }
}
