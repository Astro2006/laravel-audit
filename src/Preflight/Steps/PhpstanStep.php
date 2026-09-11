<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\Condensers\PhpstanConfig;
use Astro2006\LaravelAudit\Preflight\Condensers\PhpstanGrouper;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Json;
use Illuminate\Support\Facades\Process;

/**
 * One PHPStan run with the project's own config and level. Baseline
 * entries are read from the config instead of a second run.
 */
final class PhpstanStep implements Step
{
    public function __construct(private readonly PhpstanGrouper $grouper = new PhpstanGrouper) {}

    public function step(): PreflightStep
    {
        return PreflightStep::Phpstan;
    }

    public function run(PreflightContext $context): StepResult
    {
        $project = $context->project;

        if ($context->selection->skips(OptionalStep::Phpstan)) {
            return StepResult::skipped($this->step(), SkipReason::Without);
        }

        if (! $context->config('preflight.phpstan.enabled', true)) {
            return StepResult::skipped($this->step(), SkipReason::Disabled);
        }

        if (! $project->hasVendorBinary('phpstan')) {
            return StepResult::skipped($this->step(), SkipReason::NotInstalled);
        }

        $configPath = $project->phpstanConfigPath();

        if ($configPath === null) {
            return StepResult::skipped($this->step(), SkipReason::NoConfig);
        }

        $override = $context->config('preflight.phpstan.level');
        $arguments = [
            'analyse',
            '--error-format=json',
            '--no-progress',
            '--no-interaction',
            '--memory-limit='.$context->config('preflight.phpstan.memory_limit', '2G'),
        ];

        if ($override !== null && $override !== '') {
            $arguments[] = '--level='.$override;
        }

        $result = Process::path($project->basePath)
            ->timeout((int) $context->config('preflight.phpstan.timeout', 1800))
            ->run($context->vendorBinary('phpstan', $arguments));

        file_put_contents($context->logPath('phpstan.log'), $result->errorOutput());

        $report = Json::decode($result->output());

        if (! isset($report['totals'])) {
            return StepResult::error($this->step(), 'PHPStan produced no JSON (out of memory?): '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 8));
        }

        $context->writeJson('phpstan/phpstan.json', $report);

        $config = new PhpstanConfig($configPath);
        $level = $override !== null && $override !== ''
            ? (string) $override
            : ($this->dumpedLevel($context) ?? $config->level() ?? 'unknown');

        $grouped = $this->grouper->group($report, $config->baselineEntries(), $project->basePath);
        $context->writeJson('phpstan/phpstan-grouped.json', ['level' => $level, ...$grouped]);

        return StepResult::ok($this->step(), [
            'level' => $level,
            'level_source' => $override !== null && $override !== '' ? 'config/audit.php' : basename($configPath),
            'errors' => $grouped['errors'],
            'baselined' => $grouped['baselined'],
            'identifiers' => count($grouped['identifiers']),
            'file' => 'phpstan/phpstan-grouped.json',
        ]);
    }

    /** PHPStan resolves includes and "max" itself; ask it first. */
    private function dumpedLevel(PreflightContext $context): ?string
    {
        $result = Process::path($context->project->basePath)
            ->timeout(120)
            ->run($context->vendorBinary('phpstan', ['dump-parameters', '--json', '--no-interaction']));

        $level = Json::decode($result->output())['level'] ?? null;

        return $level === null || $level === '' ? null : (string) $level;
    }
}
