<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Reports\RequiredFinding;

final class TestsAudit extends BaseAudit
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

    public function checks(): array
    {
        return [
            'test-failure' => 'A failing test or browser test; name the app location if it reveals an app bug.',
            'skipped-test' => 'Skipped, incomplete or todo tests, especially stale ones.',
            'slow-test' => 'Suspiciously slow tests (sleep, real network, heavy seeding).',
            'untested-critical-path' => 'A critical path (auth, authorization, payments, uploads, admin, webhooks, jobs) without meaningful tests.',
            'weak-assertion' => 'Tests without assertions or only status assertions on endpoints that change data.',
            'implementation-coupling' => 'Tests that test implementation instead of behaviour, or mock Eloquent / the class under test.',
            'missing-fake' => 'Side effects without Http/Mail/Notification/Queue/Bus/Event/Storage fakes.',
            'flaky-test' => 'Sources of flakiness: time, randomness, order dependence, shared state, fixed pauses in browser tests.',
            'factory-quality' => 'Unrealistic factory defaults, missing states, huge object graphs.',
            'duplicate-test' => 'Duplicate tests, tests for removed code, commented-out tests.',
            'test-structure' => 'Feature/unit split, datasets that would remove duplication, missing arch tests.',
            'missing-authz-test' => 'No test proving another user or a guest gets 403/404 for a protected resource.',
            'missing-validation-test' => 'No tests for invalid input, boundaries or mass-assignment attempts.',
            'missing-security-test' => 'No tests for rate limiting, CSRF, signed URLs, verification gates or tenant isolation.',
            'missing-edge-case' => 'A concrete missing edge case for a critical feature.',
            'browser-coverage' => 'Critical user journeys without browser tests, or weak browser tests.',
            'phpstan-bug' => 'A real bug PHPStan reports (null dereference, wrong types, undefined members, unreachable code).',
            'phpstan-debt' => 'Typing debt of one PHPStan identifier, grouped.',
            'phpstan-baseline' => 'Errors hidden in the PHPStan baseline, as one grouped finding.',
            'phpstan-config' => 'PHPStan configuration issues: excluded paths, broad ignoreErrors.',
            'ci-quality-gate' => 'Tests, PHPStan or Pint missing from CI, no coverage minimum.',
            ...$this->commonChecks(),
        ];
    }

    public function sections(): array
    {
        return ['Test results', 'Coverage map', 'Top 10 missing tests', 'PHPStan overview'];
    }

    public function preflightSteps(): array
    {
        return [
            PreflightStep::About,
            PreflightStep::Stack,
            PreflightStep::LinesOfCode,
            PreflightStep::Routes,
            PreflightStep::Tests,
            PreflightStep::Browser,
            PreflightStep::Phpstan,
        ];
    }

    public function requiredFindings(PreflightSummary $preflight): array
    {
        $required = [];

        $tests = $preflight->step(PreflightStep::Tests);

        if ($preflight->ran(PreflightStep::Tests) && ((int) ($tests['failed'] ?? 0) + (int) ($tests['errors'] ?? 0)) > 0) {
            $required[] = new RequiredFinding(
                check: 'test-failure',
                description: 'at least one `test-failure` finding for the failing tests in tests/tests-condensed.json.',
            );
        }

        $browser = $preflight->step(PreflightStep::Browser);

        if ($preflight->ran(PreflightStep::Browser)
            && ((int) ($browser['failed'] ?? 0) + (int) ($browser['errors'] ?? 0)) > 0
            && ! ($browser['environment_error'] ?? false)) {
            $path = rtrim((string) ($browser['path'] ?? 'tests/Browser'), '/').'/';

            $required[] = new RequiredFinding(
                check: 'test-failure',
                description: sprintf('at least one `test-failure` finding with a browser test below `%s` as location.', $path),
                targetPrefix: $path,
            );
        }

        if ($preflight->ran(PreflightStep::Phpstan) && (int) $preflight->get('steps.phpstan.baselined', 0) > 0) {
            $required[] = new RequiredFinding(
                check: 'phpstan-baseline',
                description: 'one `phpstan-baseline` finding that summarizes the baselined PHPStan errors.',
            );
        }

        return $required;
    }
}
