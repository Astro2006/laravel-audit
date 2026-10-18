<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Tool;

use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Support\Arr;

final class Wait
{
    public const string CURSOR_FILE = 'wait-cursor.json';

    public function __construct(
        private readonly Status $status,
        private readonly int $pollMicroseconds = 1_000_000,
    ) {}

    /**
     * Blocks until the worker is idle or the timeout passes, then reports
     * what changed since the last call: the full table stays in `status`.
     *
     * @return array<string, mixed>
     */
    public function handle(OpenedRun $run, int $timeout): array
    {
        $until = microtime(true) + max(1, $timeout);

        while ($run->lock->current() !== null && microtime(true) < $until) {
            usleep($this->pollMicroseconds);
        }

        $done = $run->lock->current() === null;
        $cursorPath = OpenedRun::directory($run->run).'/'.self::CURSOR_FILE;
        $cursor = Read::float(Json::readFile($cursorPath), 'at');
        $latest = $cursor;
        $finished = [];
        $failed = [];

        foreach ($run->store->taskKeys() as $key) {
            $record = $run->store->record($key) ?? [];
            $finishedAt = Read::float($record, 'finished_at');

            if ($finishedAt <= $cursor) {
                continue;
            }

            $latest = max($latest, $finishedAt);
            $status = Read::string($record, 'status');
            $finished[$key] = array_filter([
                'status' => $status,
                'reason' => Read::nullableString($record, 'reason'),
                'findings' => count(Read::arrays($record, 'findings')),
                'cost_usd' => Read::nullableFloat($record, 'telemetry.cost_usd'),
            ], fn (mixed $value): bool => $value !== null);

            if ($status === AuditStatus::Failed->value) {
                $failed[] = $key;
            }
        }

        Json::writeFile($cursorPath, ['at' => $latest]);
        $status = $this->status->handle($run);

        return array_filter([
            'done' => $done,
            'hint' => $done ? null : 'Still working. Run `wait` again.',
            'worker' => $done ? null : Arr::get($status, 'worker'),
            'gate' => Arr::get($status, 'gate'),
            'failing_tests' => Arr::get($status, 'failing_tests'),
            'gate_hint' => Arr::get($status, 'gate_hint'),
            'finished_since' => $finished,
            'failed_since' => $failed,
            'pending' => Arr::get($status, 'pending'),
            'spend_usd' => Arr::get($status, 'spend_usd'),
            'next' => Arr::get($status, 'next'),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
