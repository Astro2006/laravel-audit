<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Data\Steps\TranslationsData;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\Condensers\TranslationDiff;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;

final class TranslationsStep implements Step
{
    public function __construct(
        private readonly string $defaultLocale = 'en',
        private readonly TranslationDiff $diff = new TranslationDiff,
    ) {}

    public function step(): PreflightStep
    {
        return PreflightStep::Translations;
    }

    public function run(PreflightContext $context): StepResult
    {
        $directory = is_dir($context->project->path('lang'))
            ? $context->project->path('lang')
            : $context->project->path('resources/lang');

        $report = $this->diff->diff($directory, $this->defaultLocale);
        $report = $report->withDirectory($report->directory === null ? null : $context->project->relative($report->directory));

        $context->writeJson('translations.json', $report);

        return StepResult::ok($this->step(), new TranslationsData($report->locales, $report->missingCount()));
    }
}
