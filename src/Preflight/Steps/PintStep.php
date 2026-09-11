<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Json;
use Illuminate\Support\Facades\Process;

final class PintStep implements Step
{
    public function step(): PreflightStep
    {
        return PreflightStep::Pint;
    }

    public function run(PreflightContext $context): StepResult
    {
        if ($context->selection->skips(OptionalStep::Pint)) {
            return StepResult::skipped($this->step(), SkipReason::Without);
        }

        if (! $context->config('preflight.pint', true)) {
            return StepResult::skipped($this->step(), SkipReason::Disabled);
        }

        if (! $context->project->hasVendorBinary('pint')) {
            return StepResult::skipped($this->step(), SkipReason::NotInstalled);
        }

        $result = Process::path($context->project->basePath)
            ->timeout(300)
            ->run($context->vendorBinary('pint', ['--test', '--format=json']));

        $report = Json::decode($result->output());

        if (! isset($report['files']) && ! isset($report['result'])) {
            return StepResult::error($this->step(), 'Pint produced no JSON: '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 5));
        }

        $context->writeJson('pint.json', $report);

        $rules = [];

        foreach ((array) ($report['files'] ?? []) as $file) {
            $path = (string) ($file['path'] ?? $file['name'] ?? '');

            foreach ((array) ($file['fixers'] ?? $file['appliedFixers'] ?? []) as $fixer) {
                $rules[$fixer] ??= ['rule' => $fixer, 'files' => 0, 'examples' => []];
                $rules[$fixer]['files']++;

                if (count($rules[$fixer]['examples']) < 5) {
                    $rules[$fixer]['examples'][] = $path;
                }
            }
        }

        usort($rules, fn (array $a, array $b): int => $b['files'] <=> $a['files']);

        $grouped = ['files_with_issues' => count((array) ($report['files'] ?? [])), 'rules' => $rules];
        $context->writeJson('pint-grouped.json', $grouped);

        return StepResult::ok($this->step(), [
            'files_with_issues' => $grouped['files_with_issues'],
            'rules' => count($rules),
            'file' => 'pint-grouped.json',
        ]);
    }
}
