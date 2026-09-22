<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Data\Steps\PintDTO;
use Astro2006\LaravelAudit\Enums\OptionalStep;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Collection;

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

        $result = $context->process()
            ->timeout(300)
            ->run($context->vendorBinary('pint', ['--test', '--format=json']));

        $report = Json::decode($result->output());

        if (data_get($report, 'files') === null && data_get($report, 'result') === null) {
            return StepResult::error($this->step(), 'Pint produced no JSON: '.PreflightContext::tail($result->errorOutput() ?: $result->output(), 5));
        }

        $context->writeJson('pint.json', $report);

        // One entry per (rule, file), then grouped by rule.
        $pairs = new Collection;

        foreach (Read::arrays($report, 'files') as $file) {
            $path = Read::string($file, 'path', Read::string($file, 'name'));
            $fixers = Read::strings($file, 'fixers') ?: Read::strings($file, 'appliedFixers');

            foreach ($fixers as $fixer) {
                $pairs->push(['rule' => $fixer, 'file' => $path]);
            }
        }

        $rules = $pairs
            ->groupBy('rule')
            ->map(fn (Collection $group, int|string $rule): array => [
                'rule' => (string) $rule,
                'files' => $group->count(),
                'examples' => $group->pluck('file')->take(5)->values()->all(),
            ])
            ->sortByDesc('files')
            ->values()
            ->all();

        $filesWithIssues = count(Read::arrays($report, 'files'));
        $context->writeJson('pint-grouped.json', ['files_with_issues' => $filesWithIssues, 'rules' => $rules]);

        return StepResult::ok($this->step(), new PintDTO($filesWithIssues, count($rules)));
    }
}
