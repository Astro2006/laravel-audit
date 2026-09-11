<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Enums\AssetBuildMode;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\Condensers\JunitCondenser;
use Astro2006\LaravelAudit\Preflight\DatabaseGuard;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Illuminate\Support\Facades\Process;

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
            return StepResult::skipped($this->step(), SkipReason::PluginMissing, ['path' => $location['path']]);
        }

        $database = $context->result(PreflightStep::Tests)?->get('database') ?? $this->guard->check()->toArray();

        if (! ($database['safe'] ?? false) && ! $context->config('preflight.allow_shared_database', false)) {
            return StepResult::skipped($this->step(), SkipReason::SharedDatabase, ['database' => $database]);
        }

        $warnings = $this->prepareAssets($context);

        $junit = $context->path('tests/browser-junit.xml');
        @unlink($junit);
        $startedAt = time();

        $custom = $context->config('preflight.browser.command');
        $target = $location['type'] === 'suite' ? '--testsuite='.$location['value'] : $location['value'];
        $command = is_string($custom) && $custom !== ''
            ? $custom.' '.escapeshellarg('--log-junit='.$junit)
            : $context->artisan(['test', $target, '--log-junit='.$junit]);

        $result = Process::path($project->basePath)
            ->timeout((int) $context->config('preflight.browser.timeout', 1200))
            ->run($command);

        file_put_contents($context->logPath('browser.log'), $result->output()."\n".$result->errorOutput());

        $condensed = $this->junit->condense($junit, $project->basePath);

        if ($condensed === null) {
            return StepResult::error(
                $this->step(),
                'The browser test run produced no JUnit report (see logs/browser.log): '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 8),
                ['path' => $location['path']],
            );
        }

        $artifacts = $this->collectArtifacts($context, $location['path'], $startedAt);

        $context->writeJson('tests/browser-condensed.json', [
            ...$condensed,
            'path' => $location['path'],
            'visited' => $this->visitedUris($context, $location['path']),
            'artifacts' => $artifacts,
        ]);

        return StepResult::ok($this->step(), [
            'total' => $condensed['total'],
            'passed' => $condensed['passed'],
            'failed' => $condensed['failed'],
            'errors' => $condensed['errors'],
            'skipped' => $condensed['skipped'],
            'environment_error' => $condensed['environment_error'],
            'path' => $location['path'],
            'mode' => $location['type'],
            'artifacts' => count($artifacts),
            'file' => 'tests/browser-condensed.json',
        ], $warnings);
    }

    /** @return list<string> warnings */
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
            $result = Process::path($project->basePath)->timeout(600)->run($project->packageManager()->buildCommand());
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
            $path = $context->project->path($dir);

            if (! is_dir($path)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo) {
                    $newest = max($newest, $file->getMTime());
                }
            }
        }

        return $newest;
    }

    /** @return list<string> copied files, relative to the preflight directory */
    private function collectArtifacts(PreflightContext $context, string $testPath, int $since): array
    {
        $source = $context->project->path(rtrim($testPath, '/').'/Screenshots');
        $max = (int) $context->config('preflight.browser.max_artifacts', 10);

        if (! is_dir($source) || $max <= 0) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getMTime() >= $since) {
                $files[$file->getPathname()] = $file->getMTime();
            }
        }

        arsort($files);
        $copied = [];

        foreach (array_slice(array_keys($files), 0, $max) as $file) {
            $target = 'tests/browser-artifacts/'.basename($file);
            copy($file, $context->path($target));
            $copied[] = $target;
        }

        return $copied;
    }

    /** @return array<string, list<string>> test file => URIs passed to visit() */
    private function visitedUris(PreflightContext $context, string $testPath): array
    {
        $visited = [];

        foreach ($context->project->phpFiles($testPath) as $file) {
            preg_match_all('/visit\(\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($context->project->path($file)), $matches);

            if ($matches[1] !== []) {
                $visited[$file] = array_values(array_unique($matches[1]));
            }
        }

        return $visited;
    }
}
