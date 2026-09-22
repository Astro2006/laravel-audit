<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Eval;

use Astro2006\LaravelAudit\Data\Run\RunRecord;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\Json;
use Closure;

/**
 * Runs the audit several times and collects the run.json of every run,
 * then writes the evaluation next to the runs.
 */
final class EvalRunner
{
    public function __construct(private readonly AuditConfig $config) {}

    /**
     * @param  list<string>  $audits  empty = the configured audits
     * @param  Closure(int, array<string, mixed>): void  $runAudit  run number (from 1) and the `audit` options
     * @return list<RunRecord>
     */
    public function records(int $runs, array $audits, Closure $runAudit): array
    {
        $records = [];

        for ($i = 0; $i < $runs; $i++) {
            $before = $this->runDirectories();

            // The tools are deterministic; only the agents vary between runs.
            $runAudit($i + 1, [
                '--force' => true,
                '--no-interaction' => true,
                ...array_fill_keys(array_map(fn (string $audit): string => '--'.$audit, $audits), true),
                ...($i > 0 ? ['--reuse-preflight' => null] : []),
            ]);

            foreach (array_diff($this->runDirectories(), $before) as $created) {
                $data = Json::readFile($created.'/run.json');

                if ($data !== []) {
                    $records[] = RunRecord::fromArray($data);
                }
            }
        }

        return $records;
    }

    /** Writes eval-<time>.md and .json into the output folder and returns the .md path. */
    public function write(EvalReport $report, EvalResult $result): string
    {
        $path = $this->outputRoot().'/eval-'.date('Y-m-d_H-i-s').'.md';
        file_put_contents($path, $report->render($result));
        Json::writeFile(substr($path, 0, -3).'.json', $result->toArray());

        return $path;
    }

    /** @return list<string> */
    private function runDirectories(): array
    {
        return glob($this->outputRoot().'/*', GLOB_ONLYDIR) ?: [];
    }

    private function outputRoot(): string
    {
        return rtrim($this->config->string('output_path'), '/');
    }
}
