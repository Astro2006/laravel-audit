<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Audits\DependenciesAudit;
use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\Condensers\DependencyFacts;
use Astro2006\LaravelAudit\Preflight\Condensers\UsageMapBuilder;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Json;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

/**
 * Composer audit, show, outdated, licenses and validate in parallel, then
 * the usage map, "composer why" for flagged packages and the facts file.
 */
final class ComposerStep implements Step
{
    private const int MAX_WHY = 20;

    public function __construct(private readonly DependencyFacts $facts = new DependencyFacts) {}

    public function step(): PreflightStep
    {
        return PreflightStep::Composer;
    }

    public function run(PreflightContext $context): StepResult
    {
        $project = $context->project;
        $composer = $this->composerCommand($context);

        if ($composer === null) {
            return StepResult::skipped($this->step(), SkipReason::NotInstalled);
        }

        $commands = [
            'audit' => ['audit', '--format=json', '--locked'],
            'show' => ['show', '--direct', '--format=json'],
            'validate' => ['validate', '--no-check-publish'],
        ];

        if ($context->config('preflight.composer.outdated', true)) {
            $commands['outdated'] = ['outdated', '--direct', '--format=json'];
        }

        if ($context->config('preflight.composer.licenses', true)) {
            $commands['licenses'] = ['licenses', '--format=json'];
        }

        $results = $this->pool($context, array_map(fn (array $arguments): array => [...$composer, ...$arguments, '--no-interaction'], $commands));

        $audit = Json::decode($results['audit']->output());
        $show = Json::decode($results['show']->output());
        $outdated = isset($results['outdated']) ? Json::decode($results['outdated']->output()) : [];
        $licenses = isset($results['licenses']) ? Json::decode($results['licenses']->output()) : [];
        $validateOutput = $results['validate']->output()."\n".$results['validate']->errorOutput();

        $warnings = [];

        if ($audit === []) {
            $warnings[] = 'composer-audit-no-json';
        }

        $context->writeJson('composer/audit.json', $audit);
        $context->writeJson('composer/show.json', $show);
        $context->writeJson('composer/outdated.json', $outdated);
        $context->writeJson('composer/licenses.json', $licenses);
        file_put_contents($context->path('composer/validate.txt'), trim($validateOutput)."\n");

        $usageMap = (new UsageMapBuilder($project))->build();
        $context->writeJson('composer/usage-map.json', $usageMap);

        $why = $this->why($context, $composer, array_slice(DependencyFacts::packagesNeedingWhy($audit, $project), 0, self::MAX_WHY));
        $context->writeJson('composer/why.json', $why);

        $facts = $this->facts->build(
            project: $project,
            audit: $audit,
            outdated: $outdated,
            licenses: $licenses,
            validateOutput: $validateOutput,
            validateExitCode: (int) $results['validate']->exitCode(),
            usageMap: $usageMap,
            why: $why,
            licenseAllowlist: (array) $context->config('preflight.license_allowlist', []),
        );

        $context->writeJson(DependenciesAudit::FACTS_FILE, $facts);

        return StepResult::ok($this->step(), [
            'direct' => count($project->directPackages()),
            'advisories' => count($facts['advisories']),
            'advisory_packages' => count(array_unique(array_column($facts['advisories'], 'package'))),
            'abandoned' => count($facts['abandoned']),
            'outdated_major' => count($facts['outdated_major']),
            'unused_candidates' => count($facts['unused_candidates']),
            'lock_in_sync' => $facts['validate']['lock_in_sync'],
            'file' => DependenciesAudit::FACTS_FILE,
        ], $warnings);
    }

    /** @return list<string>|null */
    private function composerCommand(PreflightContext $context): ?array
    {
        $binary = $context->project->findExecutable('composer');

        if ($binary !== null) {
            return [$binary];
        }

        if (is_file($context->project->path('composer.phar'))) {
            return [$context->phpBinary, $context->project->path('composer.phar')];
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $commands
     * @return array<string, \Illuminate\Contracts\Process\ProcessResult>
     */
    private function pool(PreflightContext $context, array $commands): array
    {
        $results = Process::pool(function (Pool $pool) use ($context, $commands): void {
            foreach ($commands as $key => $command) {
                $pool->as($key)
                    ->path($context->project->basePath)
                    ->timeout(300)
                    ->env(['COMPOSER_NO_INTERACTION' => '1'])
                    ->command($command);
            }
        })->start()->wait();

        $byKey = [];
        $collected = $results->collect();

        foreach (array_keys($commands) as $key) {
            $byKey[$key] = $collected->get($key);
        }

        return $byKey;
    }

    /**
     * @param  list<string>  $composer
     * @param  list<string>  $packages
     * @return array<string, list<string>>
     */
    private function why(PreflightContext $context, array $composer, array $packages): array
    {
        if ($packages === []) {
            return [];
        }

        $commands = [];

        foreach ($packages as $package) {
            $commands[$package] = [...$composer, 'why', $package, '--no-interaction'];
        }

        $why = [];

        foreach ($this->pool($context, $commands) as $package => $result) {
            $why[$package] = array_values(array_filter(array_map('trim', preg_split('/\R/', $result->output()) ?: [])));
        }

        return $why;
    }
}
