<?php

use Astro2006\LaravelAudit\Agents\DependenciesAgent;
use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Agents\Telemetry;
use Astro2006\LaravelAudit\Enums\AuditStatus;
use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Reports\AuditAssembler;
use Astro2006\LaravelAudit\Reports\ReportLinter;
use Astro2006\LaravelAudit\Reports\ReportParser;
use Astro2006\LaravelAudit\Reports\ReportSpec;

function taskOutcome(string $name, ?string $body, AuditStatus $status = AuditStatus::Completed, ?string $reason = null): TaskOutcome
{
    $findings = [];

    if ($body !== null && $status === AuditStatus::Completed) {
        $audit = new DependenciesAgent;
        $task = collect($audit->tasks())->firstWhere('name', $name);
        $findings = (new ReportLinter(fixture('project')))->lint((new ReportParser)->parse($body), ReportSpec::forTask($audit, $task))->findings;
    }

    return new TaskOutcome('dependencies.'.$name, AuditType::Dependencies, $status, $reason, $body, $findings, [], [], new Telemetry, false, 'h', null);
}

function finding(string $id, string $check, string $package, string $related = ''): string
{
    return "### {$id} — {$package} finding\n\n| Field | Value |\n|---|---|\n| Severity | low |\n| Confidence | confirmed |\n| Category | dependency |\n| Check | {$check} |\n| Effort | S |\n| Locations | `package:{$package}` |\n".($related === '' ? '' : "| Related | {$related} |\n")."\n**Evidence**\nx\n\n**Impact**\ny\n\n**Recommendation**\nz\n\n---\n";
}

it('renumbers findings across tasks and remaps related ids', function () {
    $audit = new DependenciesAgent;
    $outcomes = [
        'dependencies.security' => taskOutcome('security', "## Findings\n\n".finding('DEP-001', 'vulnerable-dependency', 'a/one').finding('DEP-002', 'abandoned-package', 'a/two', 'DEP-001')."\n## Limitations\nNone.\n"),
        'dependencies.hygiene' => taskOutcome('hygiene', "## Findings\n\n".finding('DEP-001', 'loose-constraint', 'a/three')."\n## Limitations\nNo lock file history.\n"),
    ];

    $report = (new AuditAssembler)->assemble($audit, $audit->tasks(), $outcomes);
    $parsed = (new ReportParser)->parse($report->body);

    expect(array_map(fn ($finding) => $finding->id, $parsed->findings))->toBe(['DEP-001', 'DEP-002', 'DEP-003'])
        ->and($parsed->findings[1]->field('related'))->toBe('DEP-001')
        ->and($parsed->findings[2]->title)->toBe('a/three finding')
        ->and($report->findingTasks)->toBe(['DEP-001' => 'dependencies.security', 'DEP-002' => 'dependencies.security', 'DEP-003' => 'dependencies.hygiene'])
        ->and($report->body)->toContain("## Limitations\n\n- The task `dependencies.necessity`")
        ->toContain('- No lock file history.');
});

it('lists failed tasks, keeps their sections visible and passes the audit linter', function () {
    $audit = new DependenciesAgent;
    $outcomes = [
        'dependencies.security' => taskOutcome('security', "## Findings\n\nNo findings.\n\n## Limitations\nNone.\n"),
        'dependencies.necessity' => taskOutcome('necessity', null, AuditStatus::Failed, 'Agent timed out after 900 seconds.'),
        'dependencies.freshness' => taskOutcome('freshness', "## Findings\n\nNo findings.\n\n## Upgrade plan\n1. Nothing to do.\n\n## Limitations\nNone.\n"),
        'dependencies.hygiene' => taskOutcome('hygiene', "## Findings\n\nNo findings.\n\n## Limitations\nNone.\n"),
    ];

    $report = (new AuditAssembler)->assemble($audit, $audit->tasks(), $outcomes, 'All good.');
    $lint = (new ReportLinter(fixture('project')))->lint((new ReportParser)->parse($report->body), ReportSpec::forAudit($audit));

    expect($lint->errors)->toBe([])
        ->and($report->failedTasks)->toBe(['dependencies.necessity'])
        ->and($report->completedTasks)->toHaveCount(3)
        ->and($report->body)->toContain("## Summary\n\nAll good.")
        ->toContain("## Findings\n\nNo findings.")
        ->toContain("## Dependency inventory\n\nNot available: the task `dependencies.necessity` did not complete.")
        ->toContain("## Upgrade plan\n\n1. Nothing to do.")
        ->toContain('- The task `dependencies.necessity` (Necessity and placement) failed: Agent timed out after 900 seconds. These checks were not audited: `unused-package`, `misplaced-package`, `shadow-dependency`, `redundant-package`.');
});

it('writes a short summary itself when the summary task has none', function () {
    $audit = new DependenciesAgent;
    $outcomes = ['dependencies.security' => taskOutcome('security', "## Findings\n\n".finding('DEP-001', 'vulnerable-dependency', 'a/one')."\n## Limitations\nNone.\n")];

    expect((new AuditAssembler)->assemble($audit, $audit->tasks(), $outcomes)->body)
        ->toContain("## Summary\n\n1 findings from 1 tasks (1 low). 3 tasks failed (dependencies.necessity, dependencies.freshness, dependencies.hygiene); see Limitations.");
});
