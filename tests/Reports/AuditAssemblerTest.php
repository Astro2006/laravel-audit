<?php

use Astro2006\LaravelAudit\Agents\TaskOutcome;
use Astro2006\LaravelAudit\Agents\Telemetry;
use Astro2006\LaravelAudit\Audits\DependenciesAudit;
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
        $audit = new DependenciesAudit;
        $task = collect($audit->tasks())->firstWhere('name', $name);
        $findings = (new ReportLinter(fixture('project')))->lint((new ReportParser)->parse($body), ReportSpec::forTask($audit, $task))->findings;
    }

    return new TaskOutcome('dependencies.'.$name, AuditType::Dependencies, $status, $reason, $body, $findings, [], [], new Telemetry, false, 'h', null);
}

function finding(string $id, string $check, string $package, string $related = '', string $severity = 'low', string $title = ''): string
{
    $title = $title === '' ? "{$package} finding" : $title;

    return "### {$id} — {$title}\n\n| Field | Value |\n|---|---|\n| Severity | {$severity} |\n| Confidence | confirmed |\n| Category | dependency |\n| Check | {$check} |\n| Effort | S |\n| Locations | `package:{$package}` |\n".($related === '' ? '' : "| Related | {$related} |\n")."\n**Evidence**\nx\n\n**Impact**\ny\n\n**Recommendation**\nz\n\n---\n";
}

it('renumbers findings across tasks and remaps related ids', function () {
    $audit = new DependenciesAudit;
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
        ->toContain('- **Constraints, Composer config and licenses:** No lock file history.')
        ->toContain("## Checks\n\n| Task | Check | Result |")
        ->toContain('| security | `vulnerable-dependency` | 1 finding (DEP-001) |')
        ->toContain('| hygiene | `loose-constraint` | 1 finding (DEP-003) |')
        ->toContain('| hygiene | `lock-out-of-sync` | clean |')
        ->toContain('| necessity | `unused-package` | not run |');
});

it('lists failed tasks, keeps their sections visible and passes the audit linter', function () {
    $audit = new DependenciesAudit;
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
    $audit = new DependenciesAudit;
    $outcomes = ['dependencies.security' => taskOutcome('security', "## Findings\n\n".finding('DEP-001', 'vulnerable-dependency', 'a/one')."\n## Limitations\nNone.\n")];

    expect((new AuditAssembler)->assemble($audit, $audit->tasks(), $outcomes)->body)
        ->toContain("## Summary\n\n1 findings from 1 tasks (1 low). 3 tasks failed (dependencies.necessity, dependencies.freshness, dependencies.hygiene); see Limitations.");
});

it('orders the findings by severity and confidence and remaps every ID in prose', function () {
    $audit = new DependenciesAudit;
    $outcomes = [
        'dependencies.security' => taskOutcome('security', "## Findings\n\n".finding('DEP-001', 'abandoned-package', 'a/one')."\n## Limitations\n- DEP-001 could not be checked against the changelog.\n\n### Out of scope observations\n- `composer.json:8` pins nothing (see the hygiene task).\n"),
        'dependencies.hygiene' => taskOutcome('hygiene', "## Findings\n\n".finding('DEP-001', 'loose-constraint', 'a/three', '', 'medium')."\n## Limitations\n- Only the first 20 constraints were read.\n"),
    ];

    $report = (new AuditAssembler)->assemble($audit, $audit->tasks(), $outcomes);
    $parsed = (new ReportParser)->parse($report->body);

    expect(array_map(fn ($finding) => [$finding->id, $finding->field('severity')], $parsed->findings))->toBe([['DEP-001', 'medium'], ['DEP-002', 'low']])
        ->and($report->findingTasks)->toBe(['DEP-001' => 'dependencies.hygiene', 'DEP-002' => 'dependencies.security'])
        ->and($report->body)->toContain('- **Advisories and abandoned packages:** DEP-002 could not be checked against the changelog.')
        ->toContain("## Summary\n\n2 findings from 2 tasks (1 medium, 1 low). Most important: DEP-001 — a/three finding.")
        ->toContain('| hygiene | `loose-constraint` | 1 finding (DEP-001) |')
        ->toContain('- `composer.json:8` pins nothing');
});

it('merges the limitations and drops out-of-scope notes that repeat a finding', function () {
    $audit = new DependenciesAudit;
    $limitations = implode("\n", [
        '- All preflight steps finished ok.',
        '- I did not open every package.',
        '- I did not open every package.',
        '- No step was skipped.',
        '- Licenses were judged from composer.json only.',
        '- The lock file was not compared with production.',
        '- Transitive packages were not traced.',
        '- Some files were skipped.',
    ]);
    $outOfScope = "### Out of scope observations\n- `package:a/one` is also outdated.\n- `app/Http/Controllers/OrderController.php:12` validates inline.\n- `app/Http/Controllers/OrderController.php:12` validates inline.\n";
    $outcomes = ['dependencies.security' => taskOutcome('security', "## Findings\n\n".finding('DEP-001', 'abandoned-package', 'a/one')."\n## Limitations\n{$limitations}\n\n{$outOfScope}")];

    $body = (new AuditAssembler)->assemble($audit, $audit->tasks(), $outcomes)->body;
    $section = (new ReportParser)->parse($body)->section('Limitations');

    expect(substr_count((string) $section, 'I did not open every package.'))->toBe(1)
        ->and($section)->not->toContain('preflight steps finished')
        ->not->toContain('No step was skipped')
        ->toContain('- **Advisories and abandoned packages:** 2 more limitations in the task log.')
        ->not->toContain('`package:a/one` is also outdated')
        ->and(substr_count((string) $section, 'validates inline'))->toBe(1);
});

it('shortens titles over 120 characters at a word boundary', function () {
    $audit = new DependenciesAudit;
    $title = str_repeat('word ', 30).'end';
    $outcomes = ['dependencies.security' => taskOutcome('security', "## Findings\n\n".finding('DEP-001', 'abandoned-package', 'a/one', '', 'low', $title)."\n## Limitations\nNone.\n")];

    $heading = (new ReportParser)->parse((new AuditAssembler)->assemble($audit, $audit->tasks(), $outcomes)->body)->findings[0]->title;

    expect(mb_strlen($heading))->toBeLessThanOrEqual(120)
        ->and($heading)->toEndWith('…')
        ->and($heading)->not->toContain('wor…');
});
