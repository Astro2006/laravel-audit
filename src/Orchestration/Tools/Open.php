<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Tools;

use Astro2006\LaravelAudit\Data\AuditOptions;
use Astro2006\LaravelAudit\Enums\CheckStatus;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Orchestration\OrchestratedRun;
use Astro2006\LaravelAudit\Orchestration\OrchestrationState;
use Astro2006\LaravelAudit\Orchestration\Phases\CheckRequirements;
use Astro2006\LaravelAudit\Orchestration\Phases\PlanRun;
use Astro2006\LaravelAudit\Orchestration\Phases\StartRun;
use Astro2006\LaravelAudit\Requirements\Check;
use Astro2006\LaravelAudit\Requirements\RequirementChecker;
use Astro2006\LaravelAudit\Support\Messages;

final class Open
{
    public function __construct(
        private readonly PlanRun $planRun,
        private readonly CheckRequirements $checkRequirements,
        private readonly StartRun $startRun,
    ) {}

    /** @return array<string, mixed> */
    public function handle(AuditOptions $options, Messages $messages): array
    {
        try {
            $plan = $this->planRun->handle($options);
        } catch (InvalidSelection $exception) {
            return ['error' => $exception->getMessage()];
        }

        $checks = $this->checkRequirements->handle($plan, $messages, true);
        $problems = array_values(array_map(
            fn (Check $check): string => $check->status->value.': '.strip_tags($check->message),
            array_filter($checks, fn (Check $check): bool => in_array($check->status, [CheckStatus::Warning, CheckStatus::Failed], true)),
        ));

        if (RequirementChecker::hasFailures($checks)) {
            return ['error' => 'The requirements for this run are not met.', 'problems' => $problems];
        }

        [$run] = $this->startRun->create($plan);
        OrchestrationState::fromOptions($options, $plan)->write(OrchestratedRun::directory($run));

        return array_filter([
            'run' => $run->id,
            'audits' => $plan->selection->auditValues(),
            'agent' => $plan->driver->displayName(),
            'concurrency' => $plan->concurrency,
            'warnings' => $problems,
            'next' => 'php artisan audit:tool catalog --run='.$run->id,
        ], fn (mixed $value): bool => $value !== []);
    }
}
