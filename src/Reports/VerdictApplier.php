<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Support\Regex;

/**
 * Applies the verdicts of finished verify jobs (keep, drop, downgrade) to
 * the task they checked.
 */
final class VerdictApplier
{
    private const string VERDICT_ROW = '/^\|\s*([A-Z]{2,4}-\d{3})\s*\|\s*`?(keep|drop|downgrade)`?\s*\|\s*(.*?)\s*\|\s*$/i';

    public function __construct(
        private readonly ReportParser $parser = new ReportParser,
        private readonly FindingEditor $editor = new FindingEditor,
    ) {}

    /**
     * A verify job that failed changes nothing.
     *
     * @param  array<string, TaskOutcome>  $outcomes  by key
     * @return array<string, TaskOutcome>
     */
    public function apply(array $outcomes): array
    {
        foreach ($outcomes as $key => $verify) {
            $task = $outcomes[substr($key, 0, -strlen(TaskOutcome::VERIFY_SUFFIX))] ?? null;

            if (! $verify->isVerify() || ! $verify->completed() || $verify->body === null || $task === null || $task->body === null) {
                continue;
            }

            $titles = [];

            foreach ($task->findings as $finding) {
                $titles[$finding->id] = $finding->title;
            }

            $dropped = [];
            $downgraded = [];
            $rejected = [];

            foreach (Regex::lines((string) $this->parser->parse($verify->body)->section('Verdicts')) as $line) {
                $row = Regex::groups(self::VERDICT_ROW, trim($line));

                if ($row === null || ! isset($titles[$row[0]])) {
                    continue;
                }

                [$id, $verdict, $reason] = $row;

                if (strcasecmp($verdict, 'drop') === 0) {
                    $dropped[] = $id;
                    $rejected[] = new Rejection($id, $titles[$id], 'verify pass: '.$reason);
                } elseif (strcasecmp($verdict, 'downgrade') === 0) {
                    $downgraded[] = $id;
                }
            }

            $body = $this->editor->downgrade($this->editor->remove($task->body, $dropped), $downgraded);
            $outcomes[$task->key] = $task->withVerdicts($body, $dropped, $downgraded, $rejected);
        }

        return $outcomes;
    }
}
