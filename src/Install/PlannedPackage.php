<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Install;

final readonly class PlannedPackage
{
    /**
     * @param  'composer'|'npm'  $manager
     * @param  string|null  $upgradeReason  set for upgrades; they are never part of "yes, all" or --yes
     * @param  string|null  $requires  another planned package this one depends on
     */
    public function __construct(
        public string $name,
        public string $manager,
        public ?string $upgradeReason = null,
        public ?string $requires = null,
        public ?string $install = null,
    ) {}

    public function isUpgrade(): bool
    {
        return $this->upgradeReason !== null;
    }

    /** What is passed to composer/npm, e.g. "playwright@latest". */
    public function installName(): string
    {
        return $this->install ?? $this->name;
    }
}
