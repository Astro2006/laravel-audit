<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Data\Steps\BrowserData;
use Astro2006\LaravelAudit\Data\Steps\TestsData;
use Astro2006\LaravelAudit\Enums\AssetBuildMode;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\Condensers\JunitCondenser;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Illuminate\Support\Collection;

/**
 * Runs existing Pest browser tests (Playwright). No browser tests → the
 * step is skipped quietly.
 */
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

        if (! $context->config('preflight.browser.enabled', true)) {
            return StepResult::skipped($this->step(), SkipReason::Disabled);
        }

        $location = $project->browserTests(
            (string) $context->config('preflight.browser.testsuite', 'Browser'),
            (string) $context->config('preflight.browser.path', 'tests/Browser'),
        );

        if ($location === null) {
            return StepResult::skipped($this->step(), SkipReason::NoBrowserTests);
        }

        if (! $project->hasBrowserPlugin() || ! $project->hasNodeModule('playwright')) {
            return StepResult::skipped($this->step(), SkipReason::PluginMissing, new BrowserData(path: $location->path));
        }

        $testsData = $context->results->get(PreflightStep::Tests)?->data;
        $database = ($testsData instanceof TestsData ? $testsData->database : null) ?? $this->guard->check();

        if (! $database->safe && ! $context->config('preflight.allow_shared_database', false)) {
            return StepResult::skipped($this->step(), SkipReason::SharedDatabase, new BrowserData(path: $location->path, database: $database));
        }

        $warnings = $this->prepareAssets($context);

        $junit = $context->path('tests/browser-junit.xml');
        @unlink($junit);
        $startedAt = time();

        $custom = $context->config('preflight.browser.command');
        $target = $location->isSuite() ? '--testsuite='.$location->value : $location->value;
        $command = is_string($custom) && $custom !== ''
            ? $custom.' '.escapeshellarg('--log-junit='.$junit)
            : $context->artisan(['test', $target, '--log-junit='.$junit]);

        $result = $context->process()
            ->timeout((int) $context->config('preflight.browser.timeout', 1200))
            ->run($command);

        file_put_contents($context->logPath('browser.log'), $result->output()."\n".$result->errorOutput());

        $report = $this->junit->condense($junit, $project->basePath);

        if ($report === null) {
            return StepResult::error(
                $this->step(),
                'The browser test run produced no JUnit report (see logs/browser.log): '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 8),
                new BrowserData(path: $location->path),
            );
        }

        $artifacts = $this->collectArtifacts($context, $location->path, $startedAt);

        $context->writeJson('tests/browser-condensed.json', [
            ...$report->toArray(),
            'path' => $location->path,
            'visited' => $this->visitedUris($context, $location->path),
            'artifacts' => $artifacts,
        ]);

        return StepResult::ok($this->step(), BrowserData::fromReport($report, $location->path, $location->type, count($artifacts)), $warnings);
    }

    /** @return list<string> warning codes */
    private function prepareAssets(PreflightContext $context): array
    {
        $project = $context->project;
        $manifest = $project->path('public/build/manifest.json');
        $mode = AssetBuildMode::tryFrom((string) $context->config('preflight.browser.build_assets', 'if-missing')) ?? AssetBuildMode::IfMissing;
        $warnings = [];

        $build = match ($mode) {
            AssetBuildMode::Always => true,
            AssetBuildMode::IfMissing => ! is_file($manifest),
            AssetBuildMode::Never => false,
        };

        if ($build && is_file($project->path('package.json'))) {
            $result = $context->process()->timeout(600)->run($project->packageManager()->buildCommand());
            file_put_contents($context->logPath('asset-build.log'), $result->output()."\n".$result->errorOutput());

            if (! $result->successful()) {
                $warnings[] = 'asset-build-failed';
            }
        }

        if (! is_file($manifest)) {
            $warnings[] = 'assets-missing';
        } elseif ($this->newestSource($context) > (int) filemtime($manifest)) {
            $warnings[] = 'assets-stale';
        }

        return $warnings;
    }

    private function newestSource(PreflightContext $context): int
    {
        $newest = 0;

        foreach (['resources/js', 'resources/css'] as $dir) {
            foreach ($this->files($context->project->path($dir)) as $file) {
                $newest = max($newest, $file->getMTime());
            }
        }

        return $newest;
    }

    /** @return list<string> copied files, relative to the preflight directory */
    private function collectArtifacts(PreflightContext $context, string $testPath, int $since): array
    {
        $max = (int) $context->config('preflight.browser.max_artifacts', 10);

        return (new Collection($this->files($context->project->path(rtrim($testPath, '/').'/Screenshots'))))
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

    /** @return list<\SplFileInfo> */
    private function files(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file;
            }
        }

        return $files;
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
