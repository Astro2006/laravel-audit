<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum PackageManager: string
{
    case Npm = 'npm';
    case Pnpm = 'pnpm';
    case Yarn = 'yarn';
    case Bun = 'bun';

    public static function detect(string $basePath): self
    {
        return match (true) {
            is_file($basePath.'/pnpm-lock.yaml') => self::Pnpm,
            is_file($basePath.'/yarn.lock') => self::Yarn,
            is_file($basePath.'/bun.lock'), is_file($basePath.'/bun.lockb') => self::Bun,
            default => self::Npm,
        };
    }

    /**
     * @param  list<string>  $packages
     * @return list<string>
     */
    public function installDevCommand(array $packages): array
    {
        return match ($this) {
            self::Npm => ['npm', 'install', '--save-dev', ...$packages],
            self::Pnpm => ['pnpm', 'add', '--save-dev', ...$packages],
            self::Yarn => ['yarn', 'add', '--dev', ...$packages],
            self::Bun => ['bun', 'add', '--dev', ...$packages],
        };
    }

    /** @return list<string> */
    public function buildCommand(): array
    {
        return match ($this) {
            self::Npm => ['npm', 'run', 'build'],
            self::Pnpm => ['pnpm', 'run', 'build'],
            self::Yarn => ['yarn', 'build'],
            self::Bun => ['bun', 'run', 'build'],
        };
    }

    /** @return list<string> */
    public function execCommand(string $binary): array
    {
        return match ($this) {
            self::Npm => ['npx', $binary],
            self::Pnpm => ['pnpm', 'exec', $binary],
            self::Yarn => ['yarn', $binary],
            self::Bun => ['bunx', $binary],
        };
    }
}
