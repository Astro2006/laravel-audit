<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run;

use Astro2006\LaravelAudit\Agents\AuditOutcome;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\RunStatus;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Reports\Location;
use Astro2006\LaravelAudit\Reports\Overlap;
use Astro2006\LaravelAudit\Support\Json;
use DateTimeImmutable;

/**
 * run.md: what ran, with which result, cost and findings. Written at the
 * start and updated at the end of every run.
 */
final class RunManifest
{
    public RunStatus $status = RunStatus::Running;

    public ?DateTimeImmutable $finishedAt = null;

    public ?string $preflightFrom = null;

    public bool $preflightComplete = false;

    public GateDecision $gate = GateDecision::NotRun;

    public ?string $browser = null;

    public ?string $model = null;

    /** @var array<string, array<string, mixed>> step => summary data */
    public array $steps = [];

    /** @var list<array{string, string}> */
    public array $metrics = [];

    /** @var array<string, AuditOutcome> */
    public array $outcomes = [];

    /** @var list<Overlap> */
    public array $overlaps = [];

    public function __construct(
        public readonly AuditRun $run,
        public readonly RunSelection $selection,
        public readonly GitInfo $git,
        public readonly string $agent,
        public readonly string $packageVersion,
    ) {}

    /** @param array<string, StepResult> $results */
    public function recordSteps(array $results): void
    {
        foreach ($results as $step => $result) {
            $this->steps[$step] = $result->toArray();
        }
    }

    public function finish(RunStatus $status): void
    {
        $this->status = $status;
        $this->finishedAt = new DateTimeImmutable;
    }

    public function write(): void
    {
        file_put_contents($this->run->manifestPath(), $this->render());
        Json::writeFile($this->run->dir().'/run.json', $this->toArray());
    }

    /**
     * Machine-readable twin of run.md, used by audit:eval.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $outcomes = [];

        foreach ($this->outcomes as $audit => $outcome) {
            $outcomes[$audit] = [
                'status' => $outcome->status->value,
                'reason' => $outcome->reason,
                'repaired' => $outcome->repaired,
                'prompt_hash' => $outcome->promptHash,
                'model' => $outcome->model,
                'telemetry' => $outcome->telemetry->toArray(),
                'findings' => array_map(fn ($finding): array => [
                    'key' => $finding->key($audit),
                    ...$finding->toArray(),
                    'targets' => array_values(array_unique(array_map(fn (Location $location): string => $location->target(), $finding->locations))),
                ], $outcome->findings),
                'errors' => $outcome->errors,
                'warnings' => $outcome->warnings,
            ];
        }

        return [
            'run_id' => $this->run->id,
            'status' => $this->status->value,
            'audits' => $this->selection->auditValues(),
            'without' => $this->selection->withoutValues(),
            'preflight_from' => $this->preflightFrom,
            'gate' => $this->gate->value,
            'agent' => $this->agent,
            'model' => $this->model,
            'git' => ['branch' => $this->git->branch, 'commit' => $this->git->commit, 'dirty' => $this->git->dirty],
            'outcomes' => $outcomes,
            'overlaps' => count($this->overlaps),
        ];
    }

    /**
     * Frontmatter of a run.md.
     *
     * @return array<string, string>
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || preg_match('/^---\n(.*?)\n---/s', (string) file_get_contents($path), $match) !== 1) {
            return [];
        }

        $data = [];

        foreach (explode("\n", $match[1]) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*?)\s*(#.*)?$/', $line, $parts) === 1) {
                $data[$parts[1]] = $parts[2];
            }
        }

        return $data;
    }

    public function render(): string
    {
        $frontmatter = [
            'format' => '1',
            'run_id' => $this->run->id,
            'started_at' => $this->run->startedAt->format(DATE_ATOM),
            'finished_at' => $this->finishedAt?->format(DATE_ATOM) ?? '',
            'status' => $this->status->value,
            'audits' => implode(', ', $this->selection->auditValues()),
            'without' => implode(', ', $this->selection->withoutValues()),
            'preflight' => $this->preflightComplete ? 'complete' : 'incomplete',
            'preflight_from' => $this->preflightFrom ?? '',
            'gate' => $this->gate->value,
            'browser' => $this->browser ?? '',
            'agent' => $this->agent,
            'model' => $this->model ?? '',
            'package_version' => $this->packageVersion,
            'git_branch' => $this->git->branch ?? '',
            'git_commit' => $this->git->commit ?? '',
            'git_dirty' => $this->git->dirty === null ? '' : ($this->git->dirty ? 'true' : 'false'),
        ];

        $lines = ['---'];

        foreach ($frontmatter as $key => $value) {
            $lines[] = rtrim($key.': '.$value);
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = '# Run '.$this->run->id;
        $lines[] = '';

        $this->preflightSection($lines);
        $this->metricsSection($lines);
        $this->auditsSection($lines);
        $this->findingsSection($lines);
        $this->duplicatesSection($lines);
        $this->warningsSection($lines);

        return implode("\n", $lines)."\n";
    }

    /** @param list<string> $lines */
    private function preflightSection(array &$lines): void
    {
        $lines[] = '## Preflight';
        $lines[] = '';
        $lines[] = '| Step | Status | Duration | Notes |';
        $lines[] = '|---|---|---|---|';

        foreach ($this->steps as $step => $data) {
            $notes = $data['reason'] ?? $data['error'] ?? implode('; ', (array) ($data['warnings'] ?? []));
            $lines[] = sprintf('| %s | %s | %ss | %s |', $step, $data['status'], $data['duration_s'] ?? 0, $this->cell((string) $notes));
        }

        $lines[] = '';
    }

    /** @param list<string> $lines */
    private function metricsSection(array &$lines): void
    {
        if ($this->metrics === []) {
            return;
        }

        $lines[] = '## Metrics';
        $lines[] = '';
        $lines[] = '| Metric | Value |';
        $lines[] = '|---|---|';

        foreach ($this->metrics as [$label, $value]) {
            $lines[] = sprintf('| %s | %s |', $label, $value);
        }

        $lines[] = '';
    }

    /** @param list<string> $lines */
    private function auditsSection(array &$lines): void
    {
        if ($this->outcomes === []) {
            return;
        }

        $lines[] = '## Audits';
        $lines[] = '';
        $lines[] = '| Audit | Status | Findings | C/H/M/L/I | Repair | Turns | Input | Output | Cache read | Cost USD | Duration | Prompt |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|---|---|---|';

        foreach ($this->outcomes as $audit => $outcome) {
            $t = $outcome->telemetry->toArray();
            $lines[] = sprintf(
                '| %s | %s | %d | %s | %s | %d | %d | %d | %d | %s | %ss | %s |',
                $audit,
                $outcome->status->value.($outcome->reason === null ? '' : ': '.$this->cell($outcome->reason)),
                count($outcome->findings),
                implode('/', self::severityCounts($outcome->findings)),
                $outcome->repaired ? 'yes' : 'no',
                $t['turns'],
                $t['input_tokens'],
                $t['output_tokens'],
                $t['cache_read_tokens'],
                $t['cost_usd'] === null ? '–' : number_format($t['cost_usd'], 2),
                $t['duration_s'],
                $outcome->promptHash,
            );
        }

        $lines[] = '';
    }

    /** @param list<string> $lines */
    private function findingsSection(array &$lines): void
    {
        $rows = [];

        foreach ($this->outcomes as $audit => $outcome) {
            foreach ($outcome->findings as $finding) {
                $rows[] = sprintf(
                    '| %s | %s | %s | %s | %s | %s |',
                    $finding->key($audit),
                    $finding->id,
                    $finding->check,
                    $finding->severity->value,
                    $finding->confidence->value,
                    $this->cell(implode(', ', array_map(fn (Location $location): string => '`'.$location.'`', $finding->locations))),
                );
            }
        }

        if ($rows === []) {
            return;
        }

        $lines[] = '## Findings';
        $lines[] = '';
        $lines[] = '| Key | ID | Check | Severity | Confidence | Locations |';
        $lines[] = '|---|---|---|---|---|---|';
        array_push($lines, ...$rows);
        $lines[] = '';
    }

    /** @param list<string> $lines */
    private function duplicatesSection(array &$lines): void
    {
        if ($this->overlaps === []) {
            return;
        }

        $lines[] = '## Possible duplicates';
        $lines[] = '';
        $lines[] = '| Keys | IDs | Shared location |';
        $lines[] = '|---|---|---|';

        foreach ($this->overlaps as $overlap) {
            $lines[] = sprintf(
                '| %s, %s | %s, %s | `%s` |',
                $overlap->findingA->key($overlap->auditA),
                $overlap->findingB->key($overlap->auditB),
                $overlap->findingA->id,
                $overlap->findingB->id,
                $overlap->location,
            );
        }

        $lines[] = '';
    }

    /** @param list<string> $lines */
    private function warningsSection(array &$lines): void
    {
        $warnings = [];

        foreach ($this->outcomes as $audit => $outcome) {
            foreach ([...$outcome->warnings, ...$outcome->errors] as $message) {
                $warnings[] = '- '.$audit.': '.$message;
            }
        }

        if ($warnings === []) {
            return;
        }

        $lines[] = '## Linter warnings';
        $lines[] = '';
        array_push($lines, ...$warnings);
        $lines[] = '';
    }

    /**
     * @param  list<\Astro2006\LaravelAudit\Reports\Finding>  $findings
     * @return array{critical: int, high: int, medium: int, low: int, info: int}
     */
    public static function severityCounts(array $findings): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];

        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    private function cell(string $value): string
    {
        return str_replace(["\n", '|'], [' ', '\\|'], $value);
    }
}
