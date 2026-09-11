<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
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

        $diff = $this->diff->diff($directory, $this->defaultLocale);
        $diff['directory'] = $diff['directory'] === null ? null : $context->project->relative($diff['directory']);

        $context->writeJson('translations.json', $diff);

        return StepResult::ok($this->step(), [
            'locales' => $diff['locales'],
            'missing' => array_sum(array_column($diff['missing'], 'count')),
            'file' => 'translations.json',
        ]);
    }
}
