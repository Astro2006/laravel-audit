<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\SnapshotType;
use Astro2006\LaravelAudit\Enums\Stage;
use Astro2006\LaravelAudit\Prompts\PromptTemplates;
use Astro2006\LaravelAudit\Support\Read;
use RuntimeException;

final readonly class AuditDefinition
{
    public const string PUBLISHED_PATH = PromptTemplates::PUBLISHED_PATH;

    /**
     * @param  list<AuditTask>  $tasks  without custom and summary
     * @param  array<string, string>  $checks  slug => description, in task order
     * @param  list<string>  $sections
     * @param  list<string>  $summarySections
     */
    public function __construct(
        public array $tasks,
        public array $checks,
        public array $sections,
        public array $summarySections,
    ) {}

    public static function for(AuditType $type): self
    {
        $published = self::publishedPath();
        $path = $published !== null && is_file($published.'/'.self::relativePath($type)) ? $published.'/'.self::relativePath($type) : self::packageFile($type);

        return self::fromFile($type, $path);
    }

    public static function fromFile(AuditType $type, string $path): self
    {
        $data = require $path;

        if (! is_array($data)) {
            throw new RuntimeException("Audit definition [{$path}] does not return an array.");
        }

        $tasks = [];
        $checks = [];

        foreach (Read::array($data, 'tasks') as $name => $task) {
            $task = is_array($task) ? $task : [];
            $taskChecks = Read::stringMap($task, 'checks');
            $checks = [...$checks, ...$taskChecks];

            $tasks[] = new AuditTask(
                $type,
                (string) $name,
                Read::string($task, 'title', (string) $name),
                array_map('strval', array_keys($taskChecks)),
                Read::strings($task, 'sections'),
                Read::strings($task, 'depends_on'),
                array_map(
                    fn (string $snapshot): SnapshotType => SnapshotType::tryFrom($snapshot)
                        ?? throw new RuntimeException(sprintf('Unknown snapshot "%s" in task "%s" of [%s].', $snapshot, $name, $path)),
                    Read::strings($task, 'snapshots'),
                ),
                Stage::tryFrom(Read::string($task, 'stage', Stage::Standards->value))
                    ?? throw new RuntimeException(sprintf('Unknown stage "%s" in task "%s" of [%s]. Allowed: %s.', Read::string($task, 'stage'), $name, $path, implode(', ', Stage::values()))),
                Read::nullableInt($task, 'max_turns'),
            );
        }

        return new self($tasks, $checks, Read::strings($data, 'sections'), Read::strings($data, 'summary_sections'));
    }

    /**
     * The turn caps tasks set for themselves, across every audit.
     *
     * @return list<int> distinct, ascending
     */
    public static function turnCaps(): array
    {
        $caps = [];

        foreach (AuditType::cases() as $type) {
            foreach (self::for($type)->tasks as $task) {
                if ($task->maxTurns !== null) {
                    $caps[] = $task->maxTurns;
                }
            }
        }

        $caps = array_values(array_unique($caps));
        sort($caps);

        return $caps;
    }

    /**
     * Checks of the package's own definitions that a published copy does not
     * have, per audit: the copy is older than the package.
     *
     * @return array<string, list<string>> audit => missing check slugs
     */
    public static function outdatedPublished(): array
    {
        $published = self::publishedPath();
        $missing = [];

        foreach (AuditType::cases() as $type) {
            if ($published === null || ! is_file($published.'/'.self::relativePath($type))) {
                continue;
            }

            $own = array_keys(self::fromFile($type, self::packageFile($type))->checks);
            $copy = array_keys(self::fromFile($type, $published.'/'.self::relativePath($type))->checks);
            $gone = array_values(array_diff($own, $copy));

            if ($gone !== []) {
                $missing[$type->value] = $gone;
            }
        }

        return $missing;
    }

    private static function publishedPath(): ?string
    {
        return function_exists('resource_path') ? resource_path(self::PUBLISHED_PATH) : null;
    }

    private static function packageFile(AuditType $type): string
    {
        return PromptTemplates::packagePath().'/'.self::relativePath($type);
    }

    private static function relativePath(AuditType $type): string
    {
        return $type->value.'/audit.php';
    }
}
