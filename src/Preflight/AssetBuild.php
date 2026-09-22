<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Enums\AssetBuildMode;
use Astro2006\LaravelAudit\Support\Files;

/**
 * Builds the frontend assets before the browser tests when they are missing
 * (or always, per config), and warns when they are older than their sources.
 */
final class AssetBuild
{
    /** @return list<string> warning codes */
    public function prepare(PreflightContext $context): array
    {
        $project = $context->project;
        $manifest = $project->path('public/build/manifest.json');
        $mode = AssetBuildMode::tryFrom($context->config->string('preflight.browser.build_assets')) ?? AssetBuildMode::IfMissing;
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
            foreach (Files::below($context->project->path($dir)) as $file) {
                $newest = max($newest, $file->getMTime());
            }
        }

        return $newest;
    }
}
