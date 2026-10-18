<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Reports\EvidenceChecker;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\Location;
use Astro2006\LaravelAudit\Reports\ReportValidator;

final class FollowUp
{
    private const string REPAIR_MESSAGE = <<<'TEXT'
        Your report did not pass validation. Fix exactly these errors and return the complete corrected report as your final answer, starting with the `## %s` line. Do not audit again and do not change findings that are not affected.

        Errors:
        TEXT;

    private const string OPEN_FILES_MESSAGE = 'You reported findings about project files without opening a single file. Open the files you cite now, check every finding against the code, drop the ones you cannot confirm, and return the complete report, starting with the `## %s` line.';

    private const string OUT_OF_TIME_MESSAGE = 'You ran out of time. Do not open any more files. Write your complete report now from what you found, starting with the `## %s` line. Put what you could not check under `## Limitations`.';

    private const string ANSWER_NOW_MESSAGE = 'You reached the turn limit. Stop investigating and write your complete report now from what you found, starting with the `## %s` line. Put what you could not check under `## Limitations`.';

    public function __construct(private readonly ReportValidator $validator) {}

    /**
     * What the result of an attempt leads to. A session is continued at most
     * once: to finish a report that ran out of turns or time, to repair an
     * invalid one, or to make an agent open the files it reported on.
     *
     * @param  Telemetry  $telemetry  the job's telemetry including this result
     * @return string|TaskOutcome the message that continues the session, or the task's outcome
     */
    public function decide(Attempt $attempt, AgentResult $result, Telemetry $telemetry): string|TaskOutcome
    {
        $job = $attempt->job;
        $section = $job->spec->firstSection();
        $canContinue = ! $attempt->repair && $result->sessionId !== null;

        if ($canContinue && in_array($result->exit, [AgentExit::MaxTurns, AgentExit::Timeout], true)) {
            return sprintf($result->exit === AgentExit::Timeout ? self::OUT_OF_TIME_MESSAGE : self::ANSWER_NOW_MESSAGE, $section);
        }

        if (! $result->succeeded()) {
            $reason = $result->truncated ? 'Answer truncated (output token limit).' : ($result->error ?? $result->exit->value);

            return TaskOutcome::fromAttempt($attempt, AuditStatus::Failed, $reason, null, $telemetry, $result);
        }

        $validated = $this->validator->validate($result->answer, $job->spec, $job->required);

        if ($validated->lint->isValid()) {
            return $canContinue && self::unread($result, $validated->lint->findings)
                ? sprintf(self::OPEN_FILES_MESSAGE, $section)
                : TaskOutcome::fromAttempt($attempt, AuditStatus::Completed, null, $validated, $telemetry, $result);
        }

        if (! $canContinue) {
            return TaskOutcome::fromAttempt($attempt, AuditStatus::Failed, 'Report failed validation'.($attempt->repair ? ' after the repair pass.' : '.'), $validated, $telemetry, $result);
        }

        return sprintf(self::REPAIR_MESSAGE, $section)."\n".implode("\n", array_map(fn (string $error): string => '- '.$error, $validated->lint->errors));
    }

    /**
     * Findings about project files from an answer that took a single turn,
     * so the agent never opened a file. Small models do this; the harness
     * cannot tell a guess from a finding by the excerpt alone.
     *
     * @param  list<Finding>  $findings
     */
    private static function unread(AgentResult $result, array $findings): bool
    {
        if ($result->telemetry->turns !== 1) {
            return false;
        }

        foreach ($findings as $finding) {
            if (! EvidenceChecker::isPreflightReference($finding->evidence) && array_filter($finding->locations, fn (Location $location): bool => $location->isFile()) !== []) {
                return true;
            }
        }

        return false;
    }
}
