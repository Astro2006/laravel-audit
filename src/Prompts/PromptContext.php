<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Prompts;

/**
 * Run-specific values the prompt placeholders are filled with.
 */
final readonly class PromptContext
{
    /**
     * @param  list<string>  $excludePaths
     * @param  array<string, list<string>>  $customChecks  audit key => rules
     */
    public function __construct(
        public string $projectName,
        public string $laravelVersion,
        public string $phpVersion,
        public string $testFramework,
        public string $frontendStack,
        public string $phpstanLevel,
        public string $browserTests,
        public string $runId,
        public string $outputDir,
        public string $preflightDir,
        public string $reportLanguage,
        public array $excludePaths,
        public array $customChecks,
        public int $maxFindings,
        public bool $boostAvailable,
    ) {}
}
