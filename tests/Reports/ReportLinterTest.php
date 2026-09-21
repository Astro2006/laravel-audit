<?php

use Astro2006\LaravelAudit\Audits\BackendAudit;
use Astro2006\LaravelAudit\Audits\FrontendAudit;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\FindingGuard;
use Astro2006\LaravelAudit\Reports\ReportLinter;
use Astro2006\LaravelAudit\Reports\ReportParser;
use Astro2006\LaravelAudit\Reports\ReportSpec;
use Astro2006\LaravelAudit\Reports\RequiredFinding;

function lintReport(string $markdown, $audit = null, array $required = [], ?int $max = 25)
{
    return (new ReportLinter(fixture('project')))
        ->lint((new ReportParser)->parse($markdown), ReportSpec::forAudit($audit ?? new BackendAudit, $max), $required);
}

function lintTask(string $markdown, string $task, array $required = [])
{
    $audit = new BackendAudit;
    $task = collect($audit->tasks())->firstWhere('name', $task);

    return (new ReportLinter(fixture('project')))->lint((new ReportParser)->parse($markdown), ReportSpec::forTask($audit, $task, 8), $required);
}

function validBackendReport(): string
{
    return file_get_contents(fixture('reports/backend-valid.md'));
}

it('accepts a valid report and builds findings', function () {
    $result = lintReport(validBackendReport());

    [$first] = $result->findings;

    expect($result->errors)->toBe([])
        ->and($result->isValid())->toBeTrue()
        ->and($result->findings)->toHaveCount(2)
        ->and($first->severity)->toBe(Severity::High)
        ->and($first->occurrences)->toBe(2)
        ->and($first->references)->toBe(['CWE-915', 'OWASP A08:2021'])
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
        ->toContain('BE-001: check "xss" is not in your check list (backend).')
        ->toContain('FE-002: wrong ID prefix, expected BE-NNN.');
});

it('requires evidence, impact and recommendation', function () {
    $report = str_replace("**Impact**\nCustomers can set `status` on their own orders.\n", '', validBackendReport());

    expect(lintReport($report)->errors)->toContain('BE-001: **Impact** is missing or empty.');
});

it('enforces the finding limit', function () {
    expect(implode("\n", lintReport(validBackendReport(), max: 1)->errors))->toContain('The report has 2 findings; the maximum is 1.');
});

it('rejects findings in files that do not exist and warns about lines past the end', function () {
    $missing = lintReport(str_replace('`app/Services/OrderService.php:7-12`', '`app/Services/Missing.php:3`', validBackendReport()));
    $long = lintReport(str_replace('`app/Services/OrderService.php:7-12`', '`app/Services/OrderService.php:40`', validBackendReport()));

    expect($missing->warnings)->toContain('BE-001: rejected — location `app/Services/Missing.php` does not exist in the project (possibly hallucinated).')
        ->and($missing->errors)->toBe([])
        ->and(array_map(fn ($finding) => $finding->id, $missing->findings))->toBe(['BE-002'])
        ->and($missing->rejected[0]->id)->toBe('BE-001')
        ->and($long->warnings)->toContain('BE-001: `app/Services/OrderService.php` has 13 lines, but the location points to line 40.');
});

it('rejects a finding whose quoted code is not in its file', function () {
    $report = str_replace('$order->fill($data)->save();', '$order->update($request->all());', validBackendReport());
    $result = lintReport($report);

    expect($result->rejected)->toHaveCount(1)
        ->and($result->rejected[0]->reason)->toBe('the code in Evidence does not appear in `app/Services/OrderService.php`.')
        ->and($result->findings)->toHaveCount(1);
});

it('accepts missing: locations only when the file is really missing', function () {
    $absent = lintReport(str_replace('`app/Services/OrderService.php`', '`missing:config/cors.php`', validBackendReport()));
    $present = lintReport(str_replace('`app/Services/OrderService.php`', '`missing:composer.json`', validBackendReport()));

    expect($absent->findings)->toHaveCount(2)
        ->and($absent->warnings)->toBe([])
        ->and($present->rejected[0]->reason)->toBe('`composer.json` is reported as missing, but it exists.');
});

it('rejects what the audit guard rejects', function () {
    $guard = new class implements FindingGuard
    {
        public function reject(Finding $finding): ?string
        {
            return $finding->check === 'dead-code' ? 'contradicts the preflight.' : null;
        }
    };

    $result = (new ReportLinter(fixture('project')))
        ->lint((new ReportParser)->parse(validBackendReport()), ReportSpec::forAudit(new BackendAudit, 25, $guard));

    expect($result->rejected[0]->id)->toBe('BE-002')
        ->and($result->rejected[0]->reason)->toBe('contradicts the preflight.');
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

function taskFinding(string $check): string
{
    return "### BE-001 — Raw SQL with input\n\n| Field | Value |\n|---|---|\n| Severity | high |\n| Confidence | confirmed |\n| Category | security |\n| Check | {$check} |\n| Effort | S |\n| Locations | `app/Services/OrderService.php:7` |\n\n**Evidence**\nx\n\n**Impact**\ny\n\n**Recommendation**\nz\n\n---\n";
}

it('accepts a task report without summary and audit sections', function () {
    $result = lintTask("## Findings\n\n".taskFinding('sql-injection')."\n## Limitations\nNone.\n", 'injection');

    expect($result->errors)->toBe([])
        ->and($result->findings)->toHaveCount(1);
});

it('keeps a task to its own checks and sections', function () {
    $foreign = lintTask("## Findings\n\n".taskFinding('mass-assignment')."\n## Limitations\nNone.\n", 'injection');
    $sections = lintTask("## Findings\nNo findings.\n\n## Limitations\nNone.\n", 'dead-code');

    expect(implode("\n", $foreign->errors))->toContain('BE-001: check "mass-assignment" is not in your check list (backend.injection). Use one of: sql-injection, command-injection, unsafe-deserialization, ssrf, path-traversal, other.')
        ->and($sections->errors)->toBe(['Missing section `## Dead code candidates`.']);
});

it('ignores findings in the summary task and only requires its sections', function () {
    $result = lintTask("## Summary\nAll good.\n\n## Findings\n\n".taskFinding('xss'), 'summary');

    expect($result->errors)->toBe([])
        ->and($result->findings)->toBe([]);
});
