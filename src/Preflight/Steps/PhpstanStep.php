<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\AsyncStep;
use Astro2006\LaravelAudit\Data\Phpstan\PhpstanMessage;
use Astro2006\LaravelAudit\Data\Steps\PhpstanDTO;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\Condensers\PhpstanConfig;
use Astro2006\LaravelAudit\Preflight\Condensers\PhpstanGrouper;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;

final class PhpstanStep implements AsyncStep
{
    public function __construct(private readonly PhpstanGrouper $grouper = new PhpstanGrouper) {}

    public function step(): PreflightStep
    {
        return PreflightStep::Phpstan;
    }

    public function run(PreflightContext $context): StepResult
    {
        $started = $this->start($context);

        return $started instanceof StepResult ? $started : $this->finish($context, $started->wait());
    }

    public function start(PreflightContext $context): InvokedProcess|StepResult
    {
        $project = $context->project;

        if ($context->selection->skips(OptionalStep::Phpstan)) {
            return StepResult::skipped($this->step(), SkipReason::Without);
        }

        if (! $project->hasVendorBinary('phpstan')) {
            return StepResult::skipped($this->step(), SkipReason::NotInstalled);
        }

        $configPath = $project->phpstanConfigPath();

        if ($configPath === null) {
            return StepResult::skipped($this->step(), SkipReason::NoConfig);
        }

        $override = $context->config->nullableString('preflight.phpstan.level');
        $arguments = [
            'analyse',
            '--error-format=json',
            '--no-progress',
            '--no-interaction',
            '--memory-limit='.$context->config->string('preflight.phpstan.memory_limit'),
        ];

        if ($override !== null) {
            $arguments[] = '--level='.$override;
        }

        return $context->process()
            ->timeout($context->config->int('preflight.phpstan.timeout'))
            ->start($context->vendorBinary('phpstan', $arguments));
    }

    public function finish(PreflightContext $context, ProcessResult $result): StepResult
    {
        $project = $context->project;
        $configPath = (string) $project->phpstanConfigPath();
        $override = $context->config->nullableString('preflight.phpstan.level');

        file_put_contents($context->logPath('phpstan.log'), $result->errorOutput());

        $json = Json::decode($result->output());

        if (data_get($json, 'totals') === null) {
            return StepResult::error($this->step(), 'PHPStan produced no JSON (out of memory?): '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 8));
        }

        $context->writeJson('phpstan/phpstan.json', $json);

        $config = new PhpstanConfig($configPath);
        $level = $override ?? $this->dumpedLevel($context) ?? $config->level() ?? 'unknown';

        $report = $this->grouper->group(
            $level,
            PhpstanMessage::listFromJson($json, $project->basePath),
            $config->baselineEntries(),
            Read::strings($json, 'errors'),
        );

        $context->writeJson('phpstan/phpstan-grouped.json', $report);

        return StepResult::ok($this->step(), new PhpstanDTO(
            level: $level,
            levelSource: $override !== null ? 'config/audit.php' : basename($configPath),
            errors: $report->errors,
            baselined: $report->baselined,
            identifiers: count($report->identifiers),
        ));
    }

    private function dumpedLevel(PreflightContext $context): ?string
    {
        $result = $context->process()
            ->timeout(120)
            ->run($context->vendorBinary('phpstan', ['dump-parameters', '--json', '--no-interaction']));

        return Read::nullableString(Json::decode($result->output()), 'level');
    }
}
