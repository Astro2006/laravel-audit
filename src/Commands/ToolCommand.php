<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Commands;

use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Orchestration\OrchestratedRun;
use Astro2006\LaravelAudit\Orchestration\Tools\Catalog;
use Astro2006\LaravelAudit\Orchestration\Tools\StartWork;
use Astro2006\LaravelAudit\Orchestration\Tools\Status;
use Astro2006\LaravelAudit\Orchestration\Tools\TaskResult;
use Astro2006\LaravelAudit\Orchestration\Tools\Wait;
use Astro2006\LaravelAudit\Orchestration\Tools\Work;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class ToolCommand extends Command
{
    public const array ACTIONS = ['catalog', 'status', 'preflight', 'start', 'wait', 'result'];

    protected $signature = 'audit:tool
        {action : catalog, status, preflight, start, wait or result}
        {items?* : preflight steps (preflight), task keys (start) or one task key (result)}
        {--run= : Run ID; default: the AUDIT_RUN variable}
        {--timeout= : Seconds wait blocks at most; default tool.wait_timeout}
        {--sync : Run preflight or tasks in this process instead of in the background}';

    protected $description = 'Run the audit step by step from an agent session: preflight, tasks, results (prints JSON)';

    public function handle(AuditConfig $config): int
    {
        $action = (string) $this->argument('action');
        $items = array_values(array_filter(array_map('strval', (array) $this->argument('items')), fn (string $item): bool => $item !== ''));
        $runId = $this->stringOption('run') ?? (getenv('AUDIT_RUN') ?: null);

        if ($runId === null) {
            return $this->json(['error' => 'No run. Pass --run=<id> or set AUDIT_RUN.'], self::FAILURE);
        }

        try {
            $run = OrchestratedRun::open($config, $runId);
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
            'start' => $start->tasks($run, $items, $sync),
            'wait' => (new Wait($status))->handle($run, is_numeric($this->option('timeout')) ? (int) $this->option('timeout') : $config->int('tool.wait_timeout')),
            'result' => $items === [] ? ['error' => 'Name the task, e.g. `result backend.injection`.'] : (new TaskResult)->handle($run, Arr::first($items)),
            'work' => $this->work($config, $run),
            default => ['error' => sprintf('Unknown action "%s".', $action), 'allowed' => self::ACTIONS],
        };

        return $this->json($result, Arr::has($result, 'error') ? self::FAILURE : self::SUCCESS);
    }

    /** @return array<string, mixed> */
    private function work(AuditConfig $config, OrchestratedRun $run): array
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

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
