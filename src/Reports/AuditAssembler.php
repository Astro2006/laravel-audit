<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Contracts\Audit;
use Astro2006\LaravelAudit\Run\RunManifest;
use Astro2006\LaravelAudit\Support\Regex;

/**
 * Puts the task reports of one audit together into the audit report:
 * findings in task order with new IDs (Related is remapped), each section
 * from the task that owns it, positive observations and limitations merged,
 * failed tasks listed. The summary comes from the summary task, or a short
 * generated one when it failed.
 */
final class AuditAssembler
{
    private const int MAX_POSITIVE = 5;

    private const string OUT_OF_SCOPE = '/^###\s+Out of scope observations/i';

    public function __construct(private readonly ReportParser $parser = new ReportParser) {}

    /**
     * @param  list<AuditTask>  $tasks
     * @param  array<string, TaskOutcome>  $outcomes  by task key
     * @param  string|null  $summary  the summary task's `## Summary` text; null = generated
     * @param  array<string, string>  $summarySections  sections written by the summary task
     */
    public function assemble(Audit $audit, array $tasks, array $outcomes, ?string $summary = null, array $summarySections = []): AssembledReport
    {
        $blocks = [];
        $findingTasks = [];
        $sections = $summarySections;
        $positive = [];
        $limitations = [];
        $outOfScope = [];
        $completed = [];
        $failed = [];
        $findings = [];
        $number = 0;

        foreach ($tasks as $task) {
            if ($task->isSummary()) {
                continue;
            }

            $outcome = $outcomes[$task->key()] ?? null;

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
            array_push($findings, ...$outcome->findings);
            $report = $this->parser->parse($outcome->body);

            $ids = [];

            foreach ($report->findings as $parsed) {
                $ids[$parsed->id] = sprintf('%s-%03d', $audit->idPrefix(), ++$number);
                $findingTasks[$ids[$parsed->id]] = $task->key();
            }

            $block = $this->renumber((string) $report->section('Findings'), $ids);

            if ($block !== '') {
                $blocks[] = $block;
            }

            foreach ($task->sections as $title) {
                $sections[$title] = trim((string) $report->section($title));
            }

            foreach (Regex::lines((string) $report->section('Positive observations')) as $line) {
                if (preg_match('/^\s*[-*]\s+\S/', $line) === 1) {
                    $positive[] = rtrim($line);
                }
            }

            $scope = false;

            foreach (Regex::lines((string) $report->section('Limitations')) as $line) {
                if (preg_match(self::OUT_OF_SCOPE, $line) === 1) {
                    $scope = true;

                    continue;
                }

                $line = $this->bullet($line);

                if ($line === null) {
                    continue;
                }

                if ($scope) {
                    $outOfScope[] = $line;
                } else {
                    $limitations[] = $line;
                }
            }
        }

        $body = implode("\n", [
            '## Summary',
            '',
            trim($summary ?? $this->generatedSummary($findings, $completed, $failed)),
            '',
            '## Findings',
            '',
            $blocks === [] ? 'No findings.' : implode("\n\n", $blocks),
            '',
            ...$this->auditSections($audit, $tasks, $sections, $failed),
            '## Positive observations',
            '',
            $positive === [] ? 'None noted.' : implode("\n", array_slice(array_values(array_unique($positive)), 0, self::MAX_POSITIVE)),
            '',
            '## Limitations',
            '',
            $limitations === [] ? 'None.' : implode("\n", array_values(array_unique($limitations))),
            ...($outOfScope === [] ? [] : ['', '### Out of scope observations', '', ...array_values(array_unique($outOfScope))]),
        ])."\n";

        return new AssembledReport($body, $findingTasks, $completed, $failed);
    }

    /**
     * Gives the findings of one task their audit-wide IDs and remaps
     * references to them in the Related field.
     *
     * @param  array<string, string>  $ids  task ID => audit ID
     */
    private function renumber(string $findings, array $ids): string
    {
        $lines = [];

        foreach (Regex::lines($findings) as $line) {
            if (strcasecmp(trim($line), 'No findings.') === 0) {
                continue;
            }

            $heading = Regex::groups('/^(###\s+)([A-Z]{2,4}-\d{3})(\s*[—–-].*)$/u', $line);

            if ($heading !== null) {
                [$hashes, $id, $rest] = $heading;
                $line = $hashes.($ids[$id] ?? $id).$rest;
            } elseif (preg_match('/^\|\s*Related\s*\|/i', $line) === 1) {
                $line = (string) preg_replace_callback('/\b[A-Z]{2,4}-\d{3}\b/', fn (array $match): string => $ids[$match[0]] ?? $match[0], $line);
            }

            $lines[] = $line;
        }

        return trim(implode("\n", $lines));
    }

    /**
     * One line of a merged list: plain text becomes a bullet, list items and
     * table rows stay as they are, empty lines and "None." disappear.
     */
    private function bullet(string $line): ?string
    {
        $line = rtrim($line);
        $text = trim($line);

        if ($text === '' || preg_match('/^(none|n\/a|keine)\.?$/i', $text) === 1) {
            return null;
        }

        return preg_match('/^\s*([-*|]|\d+\.)\s*/', $line) === 1 ? $line : '- '.$text;
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

            $content = $sections[$title] ?? '';

            if ($content === '') {
                $content = $owner !== null && in_array($owner->key(), $failed, true)
                    ? sprintf('Not available: the task `%s` did not complete.', $owner->key())
                    : 'Not available.';
            }

            array_push($lines, '## '.$title, '', $content, '');
        }

        return $lines;
    }

    /**
     * Used when the summary task failed or has not run yet.
     *
     * @param  list<Finding>  $findings
     * @param  list<string>  $completed
     * @param  list<string>  $failed
     */
    private function generatedSummary(array $findings, array $completed, array $failed): string
    {
        $counts = RunManifest::severityCounts($findings);
        $parts = [];

        foreach ($counts as $severity => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.$severity;
            }
        }

        $text = sprintf(
            '%d findings from %d tasks%s.',
            count($findings),
            count($completed),
            $parts === [] ? '' : ' ('.implode(', ', $parts).')',
        );

        return $failed === [] ? $text : $text.sprintf(' %d tasks failed (%s); see Limitations.', count($failed), implode(', ', $failed));
    }
}
