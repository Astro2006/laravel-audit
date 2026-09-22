<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Audits\AuditTask;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Reports\RequiredFinding;

final class TestsAgent extends Agent
{
    public function type(): AuditType
    {
        return AuditType::Tests;
    }

    public function idPrefix(): string
    {
        return 'TST';
    }

    public function title(): string
    {
        return 'Tests & Static Analysis Audit';
    }

    public function skipReason(AuditTask $task, PreflightSummary $preflight): ?string
    {
        $tests = $preflight->steps->tests();
        $browser = $preflight->steps->browser();
        $phpstan = $preflight->steps->phpstan();

        return match ($task->name) {
            'phpstan' => $phpstan !== null && $phpstan->errors === 0 && $phpstan->baselined === 0 ? 'PHPStan reports no errors and no baseline.' : null,
            'results' => $tests !== null && $tests->failures() === 0 && $tests->skipped === 0 && ($browser === null || ($browser->failures() === 0 && $browser->skipped === 0))
                ? 'all tests passed and none were skipped.'
                : null,
            default => null,
        };
    }

    public function requiredFindings(PreflightSummary $preflight): array
    {
        $required = [];
        $tests = $preflight->steps->tests();
        $browser = $preflight->steps->browser();
        $phpstan = $preflight->steps->phpstan();

        if ($tests !== null && $tests->failures() > 0) {
            $required[] = new RequiredFinding(
                check: 'test-failure',
                description: 'at least one `test-failure` finding for the failing tests in the Tests snapshot.',
            );
        }

        if ($browser !== null && $browser->failures() > 0 && ! $browser->environmentError) {
            $path = rtrim($browser->path ?? 'tests/Browser', '/').'/';

            $required[] = new RequiredFinding(
                check: 'test-failure',
                description: sprintf('at least one `test-failure` finding with a browser test below `%s` as location.', $path),
                targetPrefix: $path,
            );
        }

        if ($phpstan !== null && $phpstan->baselined > 0) {
            $required[] = new RequiredFinding(
                check: 'phpstan-baseline',
                description: 'one `phpstan-baseline` finding that summarizes the baselined PHPStan errors.',
            );
        }

        return $required;
    }
}
