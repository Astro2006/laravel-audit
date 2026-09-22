<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console;

use Astro2006\LaravelAudit\Data\Steps\AboutDTO;
use Astro2006\LaravelAudit\Data\Steps\BrowserDTO;
use Astro2006\LaravelAudit\Data\Steps\ComposerDTO;
use Astro2006\LaravelAudit\Data\Steps\LinesOfCodeDTO;
use Astro2006\LaravelAudit\Data\Steps\MigrationsDTO;
use Astro2006\LaravelAudit\Data\Steps\PhpstanDTO;
use Astro2006\LaravelAudit\Data\Steps\PintDTO;
use Astro2006\LaravelAudit\Data\Steps\RoutesDTO;
use Astro2006\LaravelAudit\Data\Steps\StackDTO;
use Astro2006\LaravelAudit\Data\Steps\TestsDTO;
use Astro2006\LaravelAudit\Data\Steps\TranslationsDTO;
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
        $level = $data instanceof PhpstanDTO ? $data->level : null;
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

        $failed = ($data instanceof TestsDTO || $data instanceof BrowserDTO) && $data->failures() > 0;

        return new StepLine($failed ? '✘' : '✔', $failed ? 'red' : 'green', $label, $this->detail($result));
    }

    /** The label of a step that has not finished yet. */
    public function label(PreflightStep $step): string
    {
        return $step === PreflightStep::Phpstan
            ? $this->messages->get('steps.phpstan_plain')
            : $this->messages->get('steps.'.$step->value, ['level' => '?']);
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
            $data instanceof AboutDTO => $m->get('details.about', ['laravel' => $data->laravel ?? '?', 'php' => $data->php ?? '?']),
            $data instanceof StackDTO => $m->get('details.stack', ['stack' => implode(', ', array_filter([...$data->frontend, $data->testFramework]))]),
            $data instanceof LinesOfCodeDTO => $m->get('details.loc', ['loc' => number_format($data->loc, 0, ',', '.')]),
            $data instanceof PintDTO => $m->get('details.pint', ['files' => $data->filesWithIssues, 'rules' => $data->rules]),
            $data instanceof RoutesDTO => $m->get('details.routes', ['count' => $data->count, 'flagged' => $data->flagged]),
            $data instanceof TranslationsDTO => $data->locales === []
                ? $m->get('details.translations_none')
                : $m->get('details.translations', ['locales' => implode(', ', $data->locales), 'missing' => $data->missing]),
            $data instanceof MigrationsDTO => $m->get('details.migrations', ['tables' => $data->tables, 'migrations' => $data->migrations, 'flagged' => $data->flagged]),
            $data instanceof ComposerDTO => $m->get('details.composer', ['advisories' => $data->advisories, 'abandoned' => $data->abandoned, 'outdated' => $data->outdatedMajor]),
            $data instanceof TestsDTO => $this->testDetail('tests', $data->failures(), $data->total)
                .($data->coveragePct !== null ? ' · '.$m->get('details.coverage', ['pct' => $data->coveragePct]) : ''),
            $data instanceof BrowserDTO => $this->testDetail('browser', $data->failures(), $data->total)
                .($data->artifacts > 0 ? ' · '.$m->get('details.artifacts', ['count' => $data->artifacts]) : ''),
            $data instanceof PhpstanDTO => $m->get('details.phpstan', ['errors' => $data->errors, 'baselined' => $data->baselined]),
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
