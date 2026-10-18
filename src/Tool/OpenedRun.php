<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Tool;

use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\Phases\PlanRun;
use Astro2006\LaravelAudit\Run\RunPlan;
use Astro2006\LaravelAudit\Support\AuditConfig;

final readonly class OpenedRun
{
    public const string DIRECTORY = 'tool';

    public function __construct(
        public AuditRun $run,
        public RunState $state,
        public RunPlan $plan,
        public TaskStore $store,
        public WorkerLock $lock,
        public Attempts $attempts,
    ) {}

    /** @throws InvalidSelection when the run does not exist or was not opened for audit:tool */
    public static function open(AuditConfig $config, string $runId): self
    {
        $run = AuditRun::open($config->string('output_path'), $runId)
            ?? throw new InvalidSelection(sprintf('Run "%s" not found.', $runId));
        $state = RunState::read(self::directory($run))
            ?? throw new InvalidSelection(sprintf('Run "%s" was not opened for audit:tool.', $runId));

        return self::for($run, $state, (new PlanRun($config))->handle($state->options()));
    }

    public static function for(AuditRun $run, RunState $state, RunPlan $plan): self
    {
        $directory = self::directory($run);

        return new self($run, $state, $plan, new TaskStore($directory.'/tasks'), new WorkerLock($directory.'/worker.lock'), new Attempts($directory.'/attempts'));
    }

    public static function directory(AuditRun $run): string
    {
        return $run->dir().'/'.self::DIRECTORY;
    }

    public function preflight(): ?PreflightSummary
    {
        return is_file($this->run->preflightDir().'/summary.json') ? PreflightSummary::fromDirectory($this->run->preflightDir()) : null;
    }
}
