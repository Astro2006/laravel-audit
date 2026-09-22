<?php

use Astro2006\LaravelAudit\Enums\Category;
use Astro2006\LaravelAudit\Enums\Confidence;
use Astro2006\LaravelAudit\Enums\Effort;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\VerifyPolicy;

function policyFinding(Severity $severity, Confidence $confidence): Finding
{
    return new Finding('BE-001', 'x', $severity, $confidence, Category::Security, 'other', Effort::S, [], 1, [], [], '', '', '');
}

it('verifies severe findings unless the agent is sure enough', function (Severity $severity, Confidence $confidence, bool $verify) {
    expect(VerifyPolicy::needsVerify(policyFinding($severity, $confidence)))->toBe($verify);
})->with([
    [Severity::Critical, Confidence::Confirmed, true],
    [Severity::High, Confidence::Confirmed, false],
    [Severity::High, Confidence::Likely, true],
    [Severity::Medium, Confidence::Likely, false],
    [Severity::Medium, Confidence::Suspected, true],
    [Severity::Low, Confidence::Suspected, false],
    [Severity::Info, Confidence::Suspected, false],
]);
