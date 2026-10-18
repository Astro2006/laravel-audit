<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Tool;

use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Support\Json;

final class ContinuePastGate
{
    /** @return array<string, mixed> */
    public function handle(OpenedRun $run): array
    {
        $preflight = $run->preflight();

        if ($preflight === null || $preflight->gate !== GateDecision::Aborted) {
            return ['error' => 'The test gate did not stop this run.', 'gate' => $preflight?->gate->value ?? GateDecision::NotRun->value];
        }

        $path = $run->run->preflightDir().'/summary.json';
        Json::writeFile($path, [...Json::readFile($path), 'gate' => GateDecision::Forced->value]);

        return ['gate' => GateDecision::Forced->value, 'next' => 'Start the tasks with `start <task> …`.'];
    }
}
