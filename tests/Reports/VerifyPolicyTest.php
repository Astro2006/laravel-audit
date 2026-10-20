<?php

use Astro2006\LaravelAudit\Enums\Category;
use Astro2006\LaravelAudit\Enums\Confidence;
use Astro2006\LaravelAudit\Enums\Effort;
use Astro2006\LaravelAudit\Enums\Severity;
use Astro2006\LaravelAudit\Reports\Finding;
use Astro2006\LaravelAudit\Reports\VerifyPolicy;

function policyFinding(Severity $severity, Confidence $confidence, Category $category = Category::Consistency): Finding
{
    return new Finding('BE-001', 'x', $severity, $confidence, $category, 'other', Effort::S, [], 1, [], [], '', '', '');
}

it('verifies every finding from medium up unless the agent confirmed it', function (Severity $severity, Confidence $confidence, bool $verify) {
    expect((new VerifyPolicy)->needsVerify(policyFinding($severity, $confidence)))->toBe($verify);
})->with([
    [Severity::Critical, Confidence::Confirmed, true],
    [Severity::High, Confidence::Confirmed, false],
    [Severity::High, Confidence::Likely, true],
    [Severity::Medium, Confidence::Likely, true],
    [Severity::Medium, Confidence::Confirmed, false],
    [Severity::Medium, Confidence::Suspected, true],
    [Severity::Low, Confidence::Likely, false],
    [Severity::Low, Confidence::Suspected, false],
    [Severity::Info, Confidence::Suspected, false],
]);

it('does not care about the category', function () {
    $policy = VerifyPolicy::fromConfig(auditConfig());

    expect($policy->needsVerify(policyFinding(Severity::Medium, Confidence::Likely, Category::Accessibility)))->toBeTrue()
        ->and($policy->needsVerify(policyFinding(Severity::Medium, Confidence::Likely, Category::Security)))->toBeTrue()
        ->and($policy->needsVerify(policyFinding(Severity::Medium, Confidence::Confirmed, Category::Security)))->toBeFalse();
});

it('takes the threshold and the severity floor from the config', function () {
    $policy = VerifyPolicy::fromConfig(auditConfig(['agent' => ['verify' => ['threshold' => 3, 'from' => 'low']]]));

    expect($policy->needsVerify(policyFinding(Severity::High, Confidence::Confirmed)))->toBeFalse()
        ->and($policy->needsVerify(policyFinding(Severity::Critical, Confidence::Likely)))->toBeTrue()
        ->and($policy->needsVerify(policyFinding(Severity::Low, Confidence::Likely)))->toBeTrue()
        ->and($policy->needsVerify(policyFinding(Severity::Info, Confidence::Suspected)))->toBeFalse();
});
