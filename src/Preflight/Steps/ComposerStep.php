<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Agents\DependenciesAgent;
use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Data\Steps\ComposerDTO;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\Condensers\DependencyFacts;
use Astro2006\LaravelAudit\Preflight\Condensers\UsageMapBuilder;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Regex;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

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

        $commands = new Collection([
            'audit' => ['audit', '--format=json', '--locked'],
            'show' => ['show', '--direct', '--format=json'],
            'validate' => ['validate', '--no-check-publish'],
        ]);

        if ($context->config->bool('preflight.composer.outdated')) {
            $commands->put('outdated', ['outdated', '--direct', '--format=json']);
        }

        if ($context->config->bool('preflight.composer.licenses')) {
            $commands->put('licenses', ['licenses', '--format=json']);
        }

        $results = $this->pool($context, $commands->map(fn (array $arguments): array => [...$composer, ...$arguments, '--no-interaction']));
        $json = fn (string $key): array => Json::decode($results->get($key)?->output() ?? '');

        $audit = $json('audit');
        $outdated = $json('outdated');
        $licenses = $json('licenses');
        $validate = $results->get('validate');
        $validateOutput = ($validate?->output() ?? '')."\n".($validate?->errorOutput() ?? '');

        $context->writeJson('composer/audit.json', $audit);
        $context->writeJson('composer/show.json', $json('show'));
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
            validateExitCode: (int) $validate?->exitCode(),
            usageMap: $usageMap,
            why: $why,
            licenseAllowlist: $context->config->strings('preflight.license_allowlist'),
        );

        $context->writeJson(DependenciesAgent::FACTS_FILE, $facts);

        return StepResult::ok($this->step(), new ComposerDTO(
            direct: count($project->composer()->directPackages()),
            advisories: count($facts->advisories),
            advisoryPackages: count($facts->advisoryPackages()),
            abandoned: count($facts->abandoned),
            outdatedMajor: count($facts->outdatedMajor),
            unusedCandidates: count($facts->unusedCandidates),
            lockInSync: $facts->validate->lockInSync,
        ), $audit === [] ? ['composer-audit-no-json'] : []);
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
     * @param  iterable<string, list<string>>  $commands
     * @return Collection<string, ProcessResult>
     */
    private function pool(PreflightContext $context, iterable $commands): Collection
    {
        return Process::pool(function (Pool $pool) use ($context, $commands): void {
            foreach ($commands as $key => $command) {
                $pool->as($key)
                    ->path($context->project->basePath)
                    ->timeout(300)
                    ->env(['COMPOSER_NO_INTERACTION' => '1'])
                    ->command($command);
            }
        })->start()->wait()->collect();
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

        $commands = (new Collection($packages))->mapWithKeys(fn (string $package): array => [$package => [...$composer, 'why', $package, '--no-interaction']]);

        return $this->pool($context, $commands)
            ->map(fn (ProcessResult $result): array => array_values(array_filter(array_map('trim', Regex::lines($result->output())))))
            ->all();
    }
}
