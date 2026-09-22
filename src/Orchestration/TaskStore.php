<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration;

use Astro2006\LaravelAudit\Agents\AgentJob;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Agents\Telemetry;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\Rejection;
use Astro2006\LaravelAudit\Reports\ReportValidator;
use Astro2006\LaravelAudit\Run\JobPlan;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;

final class TaskStore
{
    public function __construct(private readonly string $directory) {}

    public function save(TaskOutcome $outcome): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }

        Json::writeFile($this->path($outcome->key), [
            'key' => $outcome->key,
            'audit' => $outcome->audit?->value,
            'status' => $outcome->status->value,
            'reason' => $outcome->reason,
            'body' => $outcome->body,
            'findings' => array_map(fn (Finding $finding): array => $finding->toArray(), $outcome->findings),
            'errors' => $outcome->errors,
            'warnings' => $outcome->warnings,
            'telemetry' => $outcome->telemetry->toArray(),
            'repaired' => $outcome->repaired,
            'prompt_hash' => $outcome->promptHash,
            'model' => $outcome->model,
            'started_at' => $outcome->startedAt,
            'finished_at' => $outcome->finishedAt,
            'rejected' => array_map(fn (Rejection $rejection): array => $rejection->toArray(), $outcome->rejected),
        ]);
    }

    /** @return list<string> keys of every stored job, verify jobs included */
    public function keys(): array
    {
        $keys = array_map(fn (string $file): string => basename($file, '.json'), glob($this->directory.'/*.json') ?: []);
        sort($keys);

        return $keys;
    }

    /** @return list<string> keys of the stored tasks, without verify jobs */
    public function taskKeys(): array
    {
        return array_values(array_filter($this->keys(), fn (string $key): bool => ! str_ends_with($key, TaskOutcome::VERIFY_SUFFIX)));
    }

    /**
     * The stored job as written, for status output.
     *
     * @return array<mixed>|null
     */
    public function record(string $key): ?array
    {
        $data = Json::readFile($this->path($key));

        return $data === [] ? null : $data;
    }

    /**
     * The stored outcomes of the plan's jobs, with their findings rebuilt
     * against each job's report spec.
     *
     * @param  list<string>  $except  job keys to leave out (they run again)
     * @return array<string, TaskOutcome>
     */
    public function load(JobPlan $plan, ReportValidator $validator, array $except = []): array
    {
        $outcomes = [];

        foreach ($plan->jobs as $job) {
            $data = in_array($job->key, $except, true) ? null : $this->record($job->key);

            if ($data !== null) {
                $outcomes[$job->key] = $this->outcome($job, $data, $validator);
            }
        }

        return $outcomes;
    }

    /** @param array<mixed> $data */
    private function outcome(AgentJob $job, array $data, ReportValidator $validator): TaskOutcome
    {
        $body = Read::nullableString($data, 'body');
        $findings = $body === null ? [] : $validator->validate($body, $job->spec, $job->required)->lint->findings;

        return new TaskOutcome(
            key: $job->key,
            audit: AuditType::tryFrom(Read::string($data, 'audit')) ?? $job->audit,
            status: AuditStatus::tryFrom(Read::string($data, 'status')) ?? AuditStatus::Failed,
            reason: Read::nullableString($data, 'reason'),
            body: $body,
            findings: $findings,
            errors: Read::strings($data, 'errors'),
            warnings: Read::strings($data, 'warnings'),
            telemetry: Telemetry::fromArray(Read::array($data, 'telemetry')),
            repaired: Read::bool($data, 'repaired'),
            promptHash: Read::string($data, 'prompt_hash', $job->promptHash),
            model: Read::nullableString($data, 'model'),
            startedAt: Read::float($data, 'started_at'),
            finishedAt: Read::float($data, 'finished_at'),
            rejected: array_map(
                fn (array $rejection): Rejection => new Rejection(Read::string($rejection, 'id'), Read::string($rejection, 'title'), Read::string($rejection, 'reason')),
                Read::arrays($data, 'rejected'),
            ),
        );
    }

    private function path(string $key): string
    {
        return $this->directory.'/'.$key.'.json';
    }
}
