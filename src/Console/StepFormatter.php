<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console;

use Astro2006\LaravelAudit\Data\Steps\AboutData;
use Astro2006\LaravelAudit\Data\Steps\BrowserData;
use Astro2006\LaravelAudit\Data\Steps\ComposerData;
use Astro2006\LaravelAudit\Data\Steps\LinesOfCodeData;
use Astro2006\LaravelAudit\Data\Steps\PhpstanData;
use Astro2006\LaravelAudit\Data\Steps\PintData;
use Astro2006\LaravelAudit\Data\Steps\RoutesData;
use Astro2006\LaravelAudit\Data\Steps\StackData;
use Astro2006\LaravelAudit\Data\Steps\TestsData;
use Astro2006\LaravelAudit\Data\Steps\TranslationsData;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\StepStatus;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Messages;

/**
 * One console line per preflight step.
 */
final class StepFormatter
{
    public function __construct(private readonly Messages $messages) {}

    public function format(StepResult $result): StepLine
    {
        $data = $result->data;
        $level = $data instanceof PhpstanData ? $data->level : null;
        $label = $result->step === PreflightStep::Phpstan && $level === null
            ? $this->messages->get('steps.phpstan_plain')
            : $this->messages->get('steps.'.$result->step->value, ['level' => $level ?? '?']);

        if ($result->status === StepStatus::Skipped) {
            return new StepLine('–', 'gray', $label, $this->messages->get('skip.'.$result->reason?->value));
        }

        if ($result->status === StepStatus::Error) {
            $error = strtok((string) $result->error, "\n") ?: '';

            return new StepLine('✘', 'red', $label, mb_strimwidth($error, 0, 110, '…'));
        }

        $failed = ($data instanceof TestsData || $data instanceof BrowserData) && $data->failures() > 0;

        return new StepLine($failed ? '✘' : '✔', $failed ? 'red' : 'green', $label, $this->detail($result));
    }

    /** @return list<string> */
    public function warnings(StepResult $result): array
    {
        return array_map(fn (string $code): string => $this->messages->get('warnings.'.$code), $result->warnings);
    }

    private function detail(StepResult $result): string
    {
        $m = $this->messages;
        $data = $result->data;

        return match (true) {
            $data instanceof AboutData => $m->get('details.about', ['laravel' => $data->laravel ?? '?', 'php' => $data->php ?? '?']),
            $data instanceof StackData => $m->get('details.stack', ['stack' => implode(', ', array_filter([...$data->frontend, $data->testFramework]))]),
            $data instanceof LinesOfCodeData => $m->get('details.loc', ['loc' => number_format($data->loc, 0, ',', '.')]),
            $data instanceof PintData => $m->get('details.pint', ['files' => $data->filesWithIssues, 'rules' => $data->rules]),
            $data instanceof RoutesData => $m->get('details.routes', ['count' => $data->count, 'flagged' => $data->flagged]),
            $data instanceof TranslationsData => $data->locales === []
                ? $m->get('details.translations_none')
                : $m->get('details.translations', ['locales' => implode(', ', $data->locales), 'missing' => $data->missing]),
            $data instanceof ComposerData => $m->get('details.composer', ['advisories' => $data->advisories, 'abandoned' => $data->abandoned, 'outdated' => $data->outdatedMajor]),
            $data instanceof TestsData => $this->testDetail('tests', $data->failures(), $data->total)
                .($data->coveragePct !== null ? ' · '.$m->get('details.coverage', ['pct' => $data->coveragePct]) : ''),
            $data instanceof BrowserData => $this->testDetail('browser', $data->failures(), $data->total)
                .($data->artifacts > 0 ? ' · '.$m->get('details.artifacts', ['count' => $data->artifacts]) : ''),
            $data instanceof PhpstanData => $m->get('details.phpstan', ['errors' => $data->errors, 'baselined' => $data->baselined]),
            default => '',
        };
    }

    private function testDetail(string $key, int $failures, int $total): string
    {
        return $failures > 0
            ? $this->messages->get('details.'.$key, ['failed' => $failures, 'total' => $total])
            : $this->messages->get('details.'.$key.'_green', ['total' => $total]);
    }
}
