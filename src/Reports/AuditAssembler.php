<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Audits\Audit;
use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Support\Regex;
use Illuminate\Support\Arr;

final class AuditAssembler
{
    private const int MAX_POSITIVE = 5;

    public function __construct(
        private readonly ReportParser $parser = new ReportParser,
        private readonly FindingEditor $editor = new FindingEditor,
    ) {}

    /**
     * @param  list<AuditTask>  $tasks
     * @param  array<string, TaskOutcome>  $outcomes  by task key
     * @param  string|null  $summary  the summary task's `## Summary` text; null = generated
     * @param  array<string, string>  $summarySections  sections written by the summary task
     * @param  array<string, string>  $skipped  task key => why it was skipped
     */
    public function assemble(Audit $audit, array $tasks, array $outcomes, ?string $summary = null, array $summarySections = [], array $skipped = []): AssembledReport
    {
        $sections = $summarySections;
        $limitations = [];
        $completed = [];
        $failed = [];
        $skippedTasks = [];
        $reports = [];
        $byTask = [];
        $rejected = 0;

        foreach ($tasks as $task) {
            if ($task->isSummary()) {
                continue;
            }

            $outcome = Arr::get($outcomes, $task->key());

            if (Arr::has($skipped, $task->key())) {
                $skippedTasks[] = $task->key();
                $limitations[] = sprintf('- The task `%s` (%s) was skipped: %s', $task->key(), $task->title, Arr::get($skipped, $task->key()));

                foreach ($task->sections as $title) {
                    $sections[$title] = 'Skipped: '.Arr::get($skipped, $task->key());
                }

                continue;
            }

            if ($outcome === null || ! $outcome->completed() || $outcome->body === null) {
                $failed[] = $task->key();
                $limitations[] = sprintf(
                    '- The task `%s` (%s) %s. %s',
                    $task->key(),
                    $task->title,
                    $outcome === null ? 'did not run' : 'failed: '.rtrim((string) $outcome->reason, '.'),
                    $task->checks === [] ? 'Its sections are missing.' : 'These checks were not audited: '.implode(', ', array_map(fn (string $check): string => '`'.$check.'`', $task->checks)).'.',
                );

                continue;
            }

            $completed[] = $task->key();
            $reports[$task->key()] = $this->parser->parse($outcome->body);
            $byTask[$task->key()] = $outcome->findings;
            $rejected += count($outcome->rejected);
        }

        $ordered = FindingOrder::sort($byTask, $audit->idPrefix());
        $maps = [];
        $findingTasks = [];
        $findings = [];
        $blocks = [];

        foreach ($ordered as $entry) {
            $finding = Arr::get($entry, 'finding');
            $maps[Arr::get($entry, 'task')][Arr::get($entry, 'from')] = $finding->id;
            $findingTasks[$finding->id] = Arr::get($entry, 'task');
            $findings[] = $finding;
        }

        foreach ($ordered as $entry) {
            $body = (string) Arr::get($outcomes, Arr::get($entry, 'task'))->body;
            $block = $this->editor->blocks($body, [Arr::get($entry, 'from')]);
            $blocks[] = FindingRenumberer::trimTitle(FindingRenumberer::renumber($block, Arr::get($maps, Arr::get($entry, 'task'), [])));
        }

        $positive = [];
        $taskLimitations = [];
        $outOfScope = [];

        foreach ($tasks as $task) {
            $report = Arr::get($reports, $task->key());

            if ($report === null) {
                continue;
            }

            $ids = Arr::get($maps, $task->key(), []);

            foreach ($task->sections as $title) {
                $sections[$title] = FindingRenumberer::remap(trim((string) $report->section($title)), $ids);
            }

            foreach (Regex::lines((string) $report->section('Positive observations')) as $line) {
                if (preg_match('/^\s*[-*]\s+\S/', $line) === 1) {
                    $positive[] = FindingRenumberer::remap(rtrim($line), $ids);
                }
            }

            [$bullets, $scope] = LimitationsMerger::split((string) $report->section('Limitations'));
            $taskLimitations[$task->key()] = ['title' => $task->title, 'bullets' => array_map(fn (string $bullet): string => FindingRenumberer::remap($bullet, $ids), $bullets)];
            array_push($outOfScope, ...array_map(fn (string $bullet): string => FindingRenumberer::remap($bullet, $ids), $scope));
        }

        array_push($limitations, ...LimitationsMerger::merge($taskLimitations));

        if ($rejected > 0) {
            $limitations[] = sprintf('- %d findings were dropped because their evidence was not in the project, contradicted the preflight, or failed the verify pass. run.md lists them.', $rejected);
        }

        $outOfScope = LimitationsMerger::outOfScope($outOfScope, $findings);

        $body = implode("\n", [
            '## Summary',
            '',
            trim($summary ?? GeneratedSummary::write($findings, $completed, $failed, $skippedTasks)),
            '',
            '## Findings',
            '',
            $blocks === [] ? 'No findings.' : implode("\n\n", $blocks),
            '',
            ...$this->auditSections($audit, $tasks, $sections, $failed),
            '## Checks',
            '',
            ChecksTable::render($tasks, $outcomes, $skipped, $findings, $findingTasks),
            '',
            '## Positive observations',
            '',
            $positive === [] ? 'None noted.' : implode("\n", array_slice(array_values(array_unique($positive)), 0, self::MAX_POSITIVE)),
            '',
            '## Limitations',
            '',
            $limitations === [] ? 'None.' : implode("\n", array_values(array_unique($limitations))),
            ...($outOfScope === [] ? [] : ['', '### Out of scope observations', '', ...$outOfScope]),
        ])."\n";

        return new AssembledReport($body, $findingTasks, $completed, $failed);
    }

    /**
     * @param  list<AuditTask>  $tasks
     * @param  array<string, string>  $sections
     * @param  list<string>  $failed
     * @return list<string>
     */
    private function auditSections(Audit $audit, array $tasks, array $sections, array $failed): array
    {
        $lines = [];

        foreach ($audit->sections() as $title) {
            $owner = null;

            foreach ($tasks as $task) {
                if (in_array($title, $task->sections, true)) {
                    $owner = $task;
                }
            }

            $content = Arr::get($sections, $title, '');

            if ($content === '') {
                $content = $owner !== null && in_array($owner->key(), $failed, true)
                    ? sprintf('Not available: the task `%s` did not complete.', $owner->key())
                    : 'Not available.';
            }

            array_push($lines, '## '.$title, '', $content, '');
        }

        return $lines;
    }
}
