<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

final class FindingRejector
{
    /** @var array<string, string> path => content; agents cannot write, so files do not change during a run */
    private array $files = [];

    public function __construct(
        private readonly string $basePath,
        private readonly EvidenceChecker $evidence = new EvidenceChecker,
    ) {}

    public function reason(Finding $finding, ReportSpec $spec): ?string
    {
        $contents = [];

        foreach ($finding->locations as $location) {
            if ($location->isMissing()) {
                if (file_exists($this->basePath.'/'.$location->missing)) {
                    return sprintf('`%s` is reported as missing, but it exists.', $location->missing);
                }

                continue;
            }

            if (! $location->isFile()) {
                continue;
            }

            $path = $this->basePath.'/'.$location->file;

            if (! is_file($path)) {
                return sprintf('location `%s` does not exist in the project (possibly hallucinated).', $location->file);
            }

            $contents[] = $this->files[$path] ??= (string) file_get_contents($path);
        }

        if ($this->evidence->matches($finding->evidence, $contents) === false) {
            return 'the code in Evidence does not appear in '.implode(', ', array_map(
                fn (Location $location): string => '`'.$location->file.'`',
                array_values(array_filter($finding->locations, fn (Location $location): bool => $location->isFile())),
            )).'.';
        }

        return $spec->guard?->reject($finding);
    }
}
