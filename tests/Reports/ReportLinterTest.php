<?php

use Astro2006\LaravelAudit\Audits\BackendAudit;
use Astro2006\LaravelAudit\Audits\FrontendAudit;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Reports\ReportLinter;
use Astro2006\LaravelAudit\Reports\ReportParser;
use Astro2006\LaravelAudit\Reports\RequiredFinding;

function lintReport(string $markdown, $audit = null, array $required = [], int $max = 25)
{
    return (new ReportLinter(fixture('project'), $max))
        ->lint((new ReportParser)->parse($markdown), $audit ?? new BackendAudit, $required);
}

function validBackendReport(): string
{
    return file_get_contents(fixture('reports/backend-valid.md'));
}

it('accepts a valid report and builds findings', function () {
    $result = lintReport(validBackendReport());

    expect($result->errors)->toBe([])
        ->and($result->isValid())->toBeTrue()
        ->and($result->findings)->toHaveCount(2)
        ->and($result->findings[0]->severity)->toBe(Severity::High)
        ->and($result->findings[0]->occurrences)->toBe(2)
        ->and($result->findings[0]->references)->toBe(['CWE-915', 'OWASP A08:2021'])
        ->and($result->warnings)->toBe([]);
});

it('reports missing sections and a misplaced limitations section', function () {
    $result = lintReport("## Summary\nx\n\n## Limitations\nNone.\n\n## Findings\nNo findings.\n");

    expect($result->errors)
        ->toContain('Missing section `## Conventions baseline`.')
        ->toContain('Missing section `## Positive observations`.')
        ->toContain('`## Limitations` must be the last level-2 section.');
});

it('rejects wrong prefixes, unknown enums and foreign checks', function () {
    $report = str_replace(
        ['| Severity | high |', '| Check | mass-assignment |', 'BE-002 — Dead'],
        ['| Severity | huge |', '| Check | xss |', 'FE-002 — Dead'],
        validBackendReport(),
    );

    $result = lintReport($report);

    expect(implode("\n", $result->errors))
        ->toContain('BE-001: Severity "huge" is not allowed.')
        ->toContain('BE-001: check "xss" is not in the check list of the backend audit.')
        ->toContain('FE-002: wrong ID prefix, expected BE-NNN.');
});

it('requires evidence, impact and recommendation', function () {
    $report = str_replace("**Impact**\nCustomers can set `status` on their own orders.\n", '', validBackendReport());

    expect(lintReport($report)->errors)->toContain('BE-001: **Impact** is missing or empty.');
});

it('enforces the finding limit', function () {
    expect(lintReport(validBackendReport(), max: 1)->errors[0])->toContain('The report has 2 findings; the maximum is 1.');
});

it('warns about hallucinated files and lines past the end', function () {
    $report = str_replace('`app/Services/OrderService.php:7-12`', '`app/Services/Missing.php:3`, `app/Services/OrderService.php:40`', validBackendReport());

    expect(lintReport($report)->warnings)
        ->toContain('BE-001: location `app/Services/Missing.php` does not exist in the project (possibly hallucinated).')
        ->toContain('BE-001: `app/Services/OrderService.php` has 13 lines, but the location points to line 40.');
});

it('warns about related ids that are not in the report', function () {
    $report = str_replace('| Related | BE-002 |', '| Related | FE-006 |', validBackendReport());

    expect(lintReport($report)->warnings)->toContain('BE-001: Related "FE-006" does not exist in this report.');
});

it('fails when a required finding is not covered', function () {
    $required = [new RequiredFinding('mass-assignment', 'covered one.', target: 'app/Services/OrderService.php')];
    $missing = [new RequiredFinding('sql-injection', 'a finding for the SQL injection.')];

    expect(lintReport(validBackendReport(), required: $required)->errors)->toBe([])
        ->and(lintReport(validBackendReport(), required: $missing)->errors)->toBe(['Missing required finding: a finding for the SQL injection.']);
});

it('validates checks against the audit it belongs to', function () {
    $report = "## Summary\nx\n\n## Findings\n\n### FE-001 — XSS\n\n| Field | Value |\n|---|---|\n| Severity | high |\n| Confidence | likely |\n| Category | security |\n| Check | xss |\n| Effort | S |\n| Locations | `route:GET /` |\n\n**Evidence**\nx\n\n**Impact**\ny\n\n**Recommendation**\nz\n\n---\n\n## Frontend map\nx\n\n## Route risk table\nx\n\n## UI consistency matrix\nx\n\n## Positive observations\n- a\n\n## Limitations\nNone.\n";

    expect(lintReport($report, new FrontendAudit)->errors)->toBe([]);
});
