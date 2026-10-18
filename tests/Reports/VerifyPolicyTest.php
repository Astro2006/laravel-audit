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

it('verifies severe findings unless the agent is sure enough', function (Severity $severity, Confidence $confidence, bool $verify) {
    expect((new VerifyPolicy)->needsVerify(policyFinding($severity, $confidence)))->toBe($verify);
})->with([
    [Severity::Critical, Confidence::Confirmed, true],
    [Severity::High, Confidence::Confirmed, false],
    [Severity::High, Confidence::Likely, true],
    [Severity::Medium, Confidence::Likely, false],
    [Severity::Medium, Confidence::Suspected, true],
    [Severity::Low, Confidence::Suspected, false],
    [Severity::Info, Confidence::Suspected, false],
]);

it('always verifies unconfirmed security and correctness findings from medium up', function () {
    $policy = VerifyPolicy::fromConfig(auditConfig());

    expect($policy->needsVerify(policyFinding(Severity::Medium, Confidence::Likely, Category::Security)))->toBeTrue()
        ->and($policy->needsVerify(policyFinding(Severity::Medium, Confidence::Likely, Category::Correctness)))->toBeTrue()
        ->and($policy->needsVerify(policyFinding(Severity::Medium, Confidence::Confirmed, Category::Security)))->toBeFalse()
        ->and($policy->needsVerify(policyFinding(Severity::Low, Confidence::Suspected, Category::Security)))->toBeFalse()
        ->and($policy->needsVerify(policyFinding(Severity::Medium, Confidence::Likely, Category::Consistency)))->toBeFalse();
});

it('takes the threshold and the categories from the config', function () {
    $policy = VerifyPolicy::fromConfig(auditConfig(['agent' => ['verify' => ['threshold' => 3, 'always_categories' => ['performance']]]]));

    expect($policy->needsVerify(policyFinding(Severity::High, Confidence::Likely)))->toBeFalse()
        ->and($policy->needsVerify(policyFinding(Severity::Critical, Confidence::Likely)))->toBeTrue()
        ->and($policy->needsVerify(policyFinding(Severity::Medium, Confidence::Likely, Category::Performance)))->toBeTrue()
        ->and($policy->needsVerify(policyFinding(Severity::Medium, Confidence::Likely, Category::Security)))->toBeFalse();
});
