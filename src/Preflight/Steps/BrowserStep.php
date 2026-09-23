<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\AssetBuild;
use Astro2006\LaravelAudit\Preflight\Condensers\JunitCondenser;
use Astro2006\LaravelAudit\Preflight\Data\Steps\BrowserDTO;
use Astro2006\LaravelAudit\Preflight\Data\Steps\TestsDTO;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Files;
use Illuminate\Support\Collection;

final class BrowserStep implements Step
{
    public function __construct(
        private readonly DatabaseGuard $guard,
        private readonly JunitCondenser $junit = new JunitCondenser,
    ) {}

    public function step(): PreflightStep
    {
        return PreflightStep::Browser;
    }

    public function run(PreflightContext $context): StepResult
    {
        $project = $context->project;

        if ($context->selection->skips(OptionalStep::Browser)) {
            return StepResult::skipped($this->step(), SkipReason::Without);
        }

        $location = $project->browserTests(
            $context->config->string('preflight.browser.testsuite'),
            $context->config->string('preflight.browser.path'),
        );

        if ($location === null) {
            return StepResult::skipped($this->step(), SkipReason::NoBrowserTests);
        }

        if (! $project->hasBrowserPlugin() || ! $project->hasNodeModule('playwright')) {
            return StepResult::skipped($this->step(), SkipReason::PluginMissing, new BrowserDTO(path: $location->path));
        }

        $testsData = $context->results->get(PreflightStep::Tests)?->data;
        $database = ($testsData instanceof TestsDTO ? $testsData->database : null) ?? $this->guard->check();

        if (! $database->safe && ! $context->config->bool('preflight.allow_shared_database')) {
            return StepResult::skipped($this->step(), SkipReason::SharedDatabase, new BrowserDTO(path: $location->path, database: $database));
        }

        $warnings = (new AssetBuild)->prepare($context);

        $junit = $context->path('tests/browser-junit.xml');
        @unlink($junit);
        $startedAt = time();

        $custom = $context->config->nullableString('preflight.browser.command');
        $target = $location->isSuite() ? '--testsuite='.$location->value : $location->value;
        $command = $custom !== null
            ? $custom.' '.escapeshellarg('--log-junit='.$junit)
            : $context->artisan(['test', $target, '--log-junit='.$junit]);

        $result = $context->process()
            ->timeout($context->config->int('preflight.browser.timeout'))
            ->run($command);

        file_put_contents($context->logPath('browser.log'), $result->output()."\n".$result->errorOutput());

        $report = $this->junit->condense($junit, $project->basePath);

        if ($report === null) {
            return StepResult::error(
                $this->step(),
                'The browser test run produced no JUnit report (see logs/browser.log): '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 8),
                new BrowserDTO(path: $location->path),
            );
        }

        $artifacts = $this->collectArtifacts($context, $location->path, $startedAt);

        $context->writeJson('tests/browser-condensed.json', [
            ...$report->toArray(),
            'path' => $location->path,
            'visited' => $this->visitedUris($context, $location->path),
            'artifacts' => $artifacts,
        ]);

        return StepResult::ok($this->step(), BrowserDTO::fromReport($report, $location->path, $location->type, count($artifacts)), $warnings);
    }

    /** @return list<string> copied files, relative to the preflight directory */
    private function collectArtifacts(PreflightContext $context, string $testPath, int $since): array
    {
        $max = $context->config->int('preflight.browser.max_artifacts');

        return (new Collection(Files::below($context->project->path(rtrim($testPath, '/').'/Screenshots'))))
            ->filter(fn (\SplFileInfo $file): bool => $file->getMTime() >= $since)
            ->sortByDesc(fn (\SplFileInfo $file): int => $file->getMTime())
            ->take(max(0, $max))
            ->map(function (\SplFileInfo $file) use ($context): string {
                $target = 'tests/browser-artifacts/'.$file->getFilename();
                copy($file->getPathname(), $context->path($target));

                return $target;
            })
            ->values()
            ->all();
    }

    /** @return array<string, list<string>> test file => URIs passed to visit() */
    private function visitedUris(PreflightContext $context, string $testPath): array
    {
        return (new Collection($context->project->phpFiles($testPath)))
            ->mapWithKeys(function (string $file) use ($context): array {
                preg_match_all('/visit\(\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($context->project->path($file)), $matches);

                return [$file => array_values(array_unique(array_map('strval', end($matches) ?: [])))];
            })
            ->filter()
            ->all();
    }
}
