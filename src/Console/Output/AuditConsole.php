<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console\Output;

use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\CheckStatus;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Preflight\StepResults;
use Astro2006\LaravelAudit\Requirements\Check;
use Astro2006\LaravelAudit\Run\RunManifest;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\Messages;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Arr;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Terminal;

final class AuditConsole
{
    public readonly LiveOutput $live;

    private readonly StepFormatter $formatter;

    private readonly Factory $components;

    /** @var array<string, true> preflight steps that started and have no result yet */
    private array $runningSteps = [];

    public function __construct(
        private readonly OutputStyle $output,
        public readonly Messages $messages,
        public readonly bool $interactive,
    ) {
        $this->live = new LiveOutput($output->getOutput());
        $this->formatter = new StepFormatter($messages);
        $this->components = new Factory($output);
    }

    /** @return self a console that prints nowhere, for callers that report in their own way */
    public static function quiet(): self
    {
        return new self(new OutputStyle(new ArrayInput([]), new BufferedOutput), Messages::fromConfig(), false);
    }

    public function line(string $line): void
    {
        $this->output->writeln($line);
    }

    public function error(string $message): void
    {
        $this->components->error($message);
    }

    public function closing(string $line): void
    {
        $this->output->newLine();
        $this->line(' '.$line);
        $this->output->newLine();
    }

    public function section(string $key): void
    {
        $this->live->release();
        $this->output->newLine();
        $this->line(' <options=bold>'.$this->messages->get('sections.'.$key).'</>');
    }

    public function header(string $version, AgentDriver $driver, string $mode, RunSelection $selection): void
    {
        $model = $driver->model();

        $this->output->newLine();
        $this->line(' <options=bold>'.$this->messages->get('header', [
            'version' => $version,
            'agent' => $driver->displayName().($model !== null ? ' ('.$model.')' : ''),
            'mode' => $mode,
        ]).'</>');

        if ($selection->without !== [] || count($selection->audits) < count(AuditType::cases())) {
            $without = $selection->without === [] ? '' : ' · '.$this->messages->get('without', ['items' => implode(', ', $selection->withoutValues())]);
            $this->line(' <fg=gray>'.implode(', ', $selection->auditValues()).$without.'</>');
        }

        $this->section('requirements');
    }

    /** @param list<Check> $checks */
    public function checks(array $checks): void
    {
        foreach ($checks as $check) {
            $color = match ($check->status) {
                CheckStatus::Ok => 'green',
                CheckStatus::Info => 'blue',
                CheckStatus::Warning => 'yellow',
                CheckStatus::Failed => 'red',
            };

            $this->line(sprintf('  <fg=%s>%s</> %s', $color, $check->status->symbol(), $check->message));
        }
    }

    public function stepStarted(PreflightStep $step): void
    {
        $this->runningSteps[$step->value] = true;
        $this->showRunningSteps();
    }

    public function stepFinished(StepResult $result): void
    {
        Arr::forget($this->runningSteps, $result->step->value);
        $this->showRunningSteps();

        $line = $this->formatter->format($result);
        $this->live->log(Columns::detail(sprintf('<fg=%s>%s</> %s', $line->color, $line->symbol, $line->label), $line->detail, (new Terminal)->getWidth()));

        foreach ($this->formatter->warnings($result) as $warning) {
            $this->live->log('    <fg=yellow>⚠</> '.$warning);
        }
    }

    public function gateFailures(StepResults $results): void
    {
        $this->live->release();
        $tests = $results->tests();
        $browser = $results->browser();

        if ($tests !== null && $tests->failures() > 0) {
            $this->output->newLine();
            $this->line('  <fg=yellow>'.$this->messages->get('gate.failing', ['count' => $tests->failures()]).'</>');

            foreach ($tests->failing as $name) {
                $this->line('    · '.$name);
            }

            if ($tests->environmentError) {
                $this->line('  <fg=red>✘ '.$this->messages->get('gate.broken', ['reason' => $tests->topFailure ?? '?']).'</>');
            }
        }

        if ($browser !== null && $browser->failures() > 0) {
            $this->output->newLine();
            $this->line('  <fg=yellow>'.$this->messages->get('gate.browser_failing', ['count' => $browser->failures(), 'total' => $browser->total]).'</>');

            if ($browser->environmentError) {
                $this->line('  <fg=red>✘ '.$this->messages->get('gate.browser_broken').'</>');
            }
        }
    }

    public function confirmGate(): bool
    {
        return $this->output->confirm($this->messages->get('gate.question'), false);
    }

    public function confirmWait(): bool
    {
        return $this->output->confirm($this->messages->get('pause.question'), false);
    }

    public function outcome(AuditOutcome $outcome): void
    {
        $name = $outcome->audit->value;

        if ($outcome->status === AuditStatus::Failed) {
            $this->components->twoColumnDetail('<fg=red>✘</> '.$name, $this->messages->get('agents.failed', ['reason' => mb_strimwidth((string) $outcome->reason, 0, 90, '…')]));

            return;
        }

        $t = $outcome->telemetry;
        $seconds = intdiv($t->durationMs, 1000);
        $detail = $this->messages->get('agents.finished', [
            'findings' => count($outcome->findings),
            'turns' => $t->turns,
            'input' => self::tokens($t->inputTokens + $t->cacheReadTokens),
            'output' => self::tokens($t->outputTokens),
            'duration' => sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60),
        ]).($outcome->repaired ? ' · '.$this->messages->get('agents.repaired') : '');

        if ($outcome->status === AuditStatus::Partial) {
            $this->components->twoColumnDetail('<fg=yellow>⚠</> '.$name, $detail.' · '.mb_strimwidth((string) $outcome->reason, 0, 60, '…'));

            return;
        }

        $this->components->twoColumnDetail('<fg=green>✔</> '.$name, $detail);
    }

    /** @param list<AuditOutcome> $outcomes */
    public function summary(RunManifest $manifest, array $outcomes, string $reportPath): void
    {
        $findings = [];
        $failed = [];

        foreach ($outcomes as $outcome) {
            array_push($findings, ...$outcome->findings);

            if (! $outcome->completed()) {
                $failed[] = $outcome->audit->value.($outcome->status === AuditStatus::Partial ? ' ('.$outcome->status->value.')' : '');
            }
        }

        $this->output->newLine();
        $this->line(' '.$this->messages->get('summary.findings', RunManifest::severityCounts($findings))
            .($manifest->overlaps === [] ? '' : ' · '.$this->messages->get('summary.duplicates', ['count' => count($manifest->overlaps)])));

        if ($failed !== []) {
            $this->line(' <fg=red>'.$this->messages->get('summary.failed_audits', ['audits' => implode(', ', $failed)]).'</>');
        }

        $this->line(' '.$this->messages->get('summary.report', ['path' => $reportPath]));
        $this->output->newLine();
    }

    public function newLine(): void
    {
        $this->output->newLine();
    }

    private function showRunningSteps(): void
    {
        $width = (new Terminal)->getWidth();

        $this->live->show(array_map(
            fn (string $step): string => Columns::detail('<fg=yellow>●</> '.$this->formatter->label(PreflightStep::from($step)), '<fg=yellow>'.$this->messages->get('agents.running').'</>', $width),
            array_keys($this->runningSteps),
        ));
    }

    private static function tokens(int $tokens): string
    {
        return match (true) {
            $tokens >= 1_000_000 => number_format($tokens / 1_000_000, 1).' M',
            $tokens >= 1_000 => number_format($tokens / 1_000).' k',
            default => (string) $tokens,
        };
    }
}
