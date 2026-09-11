<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console;

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\StepStatus;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\TestGate;
use Astro2006\LaravelAudit\Support\Messages;

/**
 * One console line per preflight step.
 */
final class StepFormatter
{
    public function __construct(private readonly Messages $messages) {}

    /** @return array{symbol: string, color: string, label: string, detail: string} */
    public function format(StepResult $result): array
    {
        $label = $this->messages->get('steps.'.$result->step->value, ['level' => (string) $result->get('level', '?')]);

        if ($result->status === StepStatus::Skipped) {
            return ['symbol' => '–', 'color' => 'gray', 'label' => $label, 'detail' => $this->messages->get('skip.'.$result->reason?->value)];
        }

        if ($result->status === StepStatus::Error) {
            $error = strtok((string) $result->error, "\n") ?: '';

            return ['symbol' => '✘', 'color' => 'red', 'label' => $label, 'detail' => mb_strimwidth($error, 0, 110, '…')];
        }

        $failed = in_array($result->step, [PreflightStep::Tests, PreflightStep::Browser], true) && TestGate::failures($result) > 0;

        return [
            'symbol' => $failed ? '✘' : '✔',
            'color' => $failed ? 'red' : 'green',
            'label' => $label,
            'detail' => $this->detail($result),
        ];
    }

    /** @return list<string> */
    public function warnings(StepResult $result): array
    {
        return array_map(fn (string $code): string => $this->messages->get('warnings.'.$code), $result->warnings);
    }

    private function detail(StepResult $result): string
    {
        $m = $this->messages;

        return match ($result->step) {
            PreflightStep::About => $m->get('details.about', ['laravel' => $result->get('laravel', '?'), 'php' => $result->get('php', '?')]),
            PreflightStep::Stack => $m->get('details.stack', ['stack' => implode(', ', array_filter([...(array) $result->get('frontend', []), $result->get('test_framework')]))]),
            PreflightStep::LinesOfCode => $m->get('details.loc', ['loc' => number_format((int) $result->get('loc', 0), 0, ',', '.')]),
            PreflightStep::Pint => $m->get('details.pint', ['files' => $result->get('files_with_issues', 0), 'rules' => $result->get('rules', 0)]),
            PreflightStep::Routes => $m->get('details.routes', ['count' => $result->get('count', 0), 'flagged' => $result->get('flagged', 0)]),
            PreflightStep::Translations => $m->get('details.translations', ['locales' => implode(', ', (array) $result->get('locales', [])) ?: '–', 'missing' => $result->get('missing', 0)]),
            PreflightStep::Composer => $m->get('details.composer', ['advisories' => $result->get('advisories', 0), 'abandoned' => $result->get('abandoned', 0), 'outdated' => $result->get('outdated_major', 0)]),
            PreflightStep::Tests => $this->testDetail($result, 'tests')
                .($result->get('coverage_pct') !== null ? ' · '.$m->get('details.coverage', ['pct' => $result->get('coverage_pct')]) : ''),
            PreflightStep::Browser => $this->testDetail($result, 'browser')
                .((int) $result->get('artifacts', 0) > 0 ? ' · '.$m->get('details.artifacts', ['count' => $result->get('artifacts')]) : ''),
            PreflightStep::Phpstan => $m->get('details.phpstan', ['errors' => $result->get('errors', 0), 'baselined' => $result->get('baselined', 0)]),
        };
    }

    private function testDetail(StepResult $result, string $key): string
    {
        $failures = TestGate::failures($result);

        return $failures > 0
            ? $this->messages->get('details.'.$key, ['failed' => $failures, 'total' => $result->get('total', 0)])
            : $this->messages->get('details.'.$key.'_green', ['total' => $result->get('total', 0)]);
    }
}
