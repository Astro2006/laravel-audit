<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use DateTimeImmutable;

final readonly class AuditRun
{
    public function __construct(
        public string $id,
        public string $outputRoot,
        public DateTimeImmutable $startedAt,
    ) {}

    public static function create(string $outputRoot, ?DateTimeImmutable $now = null): self
    {
        $now ??= new DateTimeImmutable;
        $outputRoot = rtrim($outputRoot, '/');

        self::ensureOutputRoot($outputRoot);

        $id = $now->format('Y-m-d_H-i-s');
        $suffix = 1;

        while (is_dir($outputRoot.'/'.$id)) {
            $id = $now->format('Y-m-d_H-i-s').'-'.++$suffix;
        }

        $run = new self($id, $outputRoot, $now);

        mkdir($run->preflightDir(), 0777, true);
        mkdir($run->logsDir(), 0777, true);

        return $run;
    }

    public static function open(string $outputRoot, string $id): ?self
    {
        $outputRoot = rtrim($outputRoot, '/');

        if ($id === '' || str_contains($id, '/') || ! is_dir($outputRoot.'/'.$id)) {
            return null;
        }

        $startedAt = DateTimeImmutable::createFromFormat('Y-m-d_H-i-s', substr($id, 0, 19)) ?: new DateTimeImmutable('@'.filemtime($outputRoot.'/'.$id));

        return new self($id, $outputRoot, $startedAt);
    }

    public static function ensureOutputRoot(string $outputRoot): void
    {
        if (! is_dir($outputRoot)) {
            mkdir($outputRoot, 0777, true);
        }

        if (! is_file($outputRoot.'/.gitignore')) {
            file_put_contents($outputRoot.'/.gitignore', "*\n!.gitignore\n");
        }
    }

    public function dir(): string
    {
        return $this->outputRoot.'/'.$this->id;
    }

    public function preflightDir(): string
    {
        return $this->dir().'/preflight';
    }

    public function logsDir(): string
    {
        return $this->dir().'/logs';
    }

    public function reportPath(string $audit): string
    {
        return $this->dir().'/'.$audit.'.md';
    }

    public function manifestPath(): string
    {
        return $this->dir().'/run.md';
    }
}
