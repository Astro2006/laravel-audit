<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration\Tools;

use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Orchestration\OrchestratedRun;
use Astro2006\LaravelAudit\Support\Read;

final class TaskResult
{
    /** @return array<string, mixed> */
    public function handle(OrchestratedRun $run, string $key): array
    {
        $record = $run->store->record($key);

        if ($record === null) {
            return ['error' => sprintf('Task "%s" has no result yet.', $key), 'hint' => 'Start it with `start '.$key.'`, then `wait`.'];
        }

        $verify = $run->store->record($key.TaskOutcome::VERIFY_SUFFIX);

        return array_filter([
            'task' => $key,
            'status' => Read::string($record, 'status'),
            'reason' => Read::nullableString($record, 'reason'),
            'findings' => Read::arrays($record, 'findings'),
            'dropped' => Read::arrays($record, 'rejected'),
            'errors' => Read::strings($record, 'errors'),
            'verify' => $verify === null ? null : array_filter([
                'status' => Read::string($verify, 'status'),
                'reason' => Read::nullableString($verify, 'reason'),
            ]),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
