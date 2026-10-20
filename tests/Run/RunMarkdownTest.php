<?php

use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\Data\Steps\BrowserDTO;
use Astro2006\LaravelAudit\Preflight\Data\Steps\TestsDTO;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\GitInfo;
use Astro2006\LaravelAudit\Run\RunManifest;
use Astro2006\LaravelAudit\Run\RunSelection;

it('shows failing tests in the status column of the preflight table', function () {
    $manifest = new RunManifest(AuditRun::create(sys_get_temp_dir()), RunSelection::resolve(auditsConfig(['tests']), [], []), new GitInfo, 'claude_code', 'dev');
    $manifest->steps->put(StepResult::ok(PreflightStep::Tests, new TestsDTO(total: 641, passed: 639, failed: 2))->withDuration(80.63));
    $manifest->steps->put(StepResult::ok(PreflightStep::Browser, new BrowserDTO(total: 3, passed: 3)));
    $manifest->steps->put(StepResult::skipped(PreflightStep::Phpstan, SkipReason::Without));

    expect($manifest->render())
        ->toContain('| tests | ok (2 failed) | 80.63s |  |')
        ->toContain('| browser | ok | 0s |  |')
        ->toContain('| phpstan | skipped | 0s | without |');
});
