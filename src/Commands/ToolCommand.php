<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Commands;

use Astro2006\LaravelAudit\Console\AuditConsole;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Run\AuditOptions;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\Messages;
use Astro2006\LaravelAudit\Tool\Catalog;
use Astro2006\LaravelAudit\Tool\ContinuePastGate;
use Astro2006\LaravelAudit\Tool\Finish;
use Astro2006\LaravelAudit\Tool\Open;
use Astro2006\LaravelAudit\Tool\OpenedRun;
use Astro2006\LaravelAudit\Tool\StartWork;
use Astro2006\LaravelAudit\Tool\Status;
use Astro2006\LaravelAudit\Tool\TaskResult;
use Astro2006\LaravelAudit\Tool\Wait;
use Astro2006\LaravelAudit\Tool\Work;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Arr;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ToolCommand extends Command
{
    public const array ACTIONS = ['open', 'catalog', 'status', 'preflight', 'continue', 'start', 'wait', 'result', 'finish'];

    protected $signature = 'audit:tool
        {action : open, catalog, status, preflight, continue, start, wait, result or finish}
        {items?* : preflight steps (preflight), task keys (start) or one task key (result)}
        {--run= : Run ID; default: the AUDIT_RUN variable}
        {--audits=* : open: the audits to run (tests, frontend, backend, dependencies); default: the configured ones}
        {--without=* : open: audits and steps to leave out}
        {--agent= : open: agent CLI for the tasks}
        {--model= : open: model for the tasks}
        {--mode= : open: single or parallel}
        {--timeout= : Seconds wait blocks at most; default tool.wait_timeout}
        {--sync : Run preflight or tasks in this process instead of in the background}';

    protected $description = 'Run the audit step by step from an agent session: preflight, tasks, results (prints JSON)';

    public function handle(AuditConfig $config): int
    {
        $action = (string) $this->argument('action');
        $items = array_values(array_filter(array_map('strval', (array) $this->argument('items')), fn (string $item): bool => $item !== ''));

        if ($action === 'open') {
            $result = app(Open::class)->handle($this->openOptions(), Messages::fromConfig());

            return $this->json($result, Arr::has($result, 'error') ? self::FAILURE : self::SUCCESS);
        }

        $runId = $this->stringOption('run') ?? (getenv('AUDIT_RUN') ?: null);

        if ($runId === null) {
            return $this->json(['error' => 'No run. Pass --run=<id> or set AUDIT_RUN.'], self::FAILURE);
        }

        try {
            $run = OpenedRun::open($config, $runId);
        } catch (InvalidSelection $exception) {
            return $this->json(['error' => $exception->getMessage()], self::FAILURE);
        }

        $status = new Status($config);
        $start = new StartWork($config, new Work($config));
        $sync = (bool) $this->option('sync');

        $result = match ($action) {
            'catalog' => (new Catalog($config))->handle($run),
            'status' => $status->handle($run),
            'preflight' => $start->preflight($run, $items, $sync),
            'continue' => (new ContinuePastGate)->handle($run),
            'start' => $start->tasks($run, $items, $sync),
            'wait' => (new Wait($status))->handle($run, is_numeric($this->option('timeout')) ? (int) $this->option('timeout') : $config->int('tool.wait_timeout')),
            'result' => $items === [] ? ['error' => 'Name the task, e.g. `result backend.injection`.'] : (new TaskResult)->handle($run, Arr::first($items)),
            'finish' => app(Finish::class)->handle($run, $this->quietConsole()),
            'work' => $this->work($config, $run),
            default => ['error' => sprintf('Unknown action "%s".', $action), 'allowed' => self::ACTIONS],
        };

        return $this->json($result, Arr::has($result, 'error') ? self::FAILURE : self::SUCCESS);
    }

    /** @return array<string, mixed> */
    private function work(AuditConfig $config, OpenedRun $run): array
    {
        (new Work($config))->handle($run);

        return ['done' => true];
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $exit): int
    {
        $this->output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exit;
    }

    private function openOptions(): AuditOptions
    {
        $list = fn (string $name): array => array_values(array_filter(array_map('trim', explode(',', implode(',', array_map('strval', (array) $this->option($name)))))));

        return new AuditOptions(
            audits: $list('audits'),
            without: $list('without'),
            agent: $this->stringOption('agent'),
            model: $this->stringOption('model'),
            mode: $this->stringOption('mode'),
        );
    }

    private function quietConsole(): AuditConsole
    {
        return new AuditConsole(new OutputStyle(new ArrayInput([]), new BufferedOutput), Messages::fromConfig(), false);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
