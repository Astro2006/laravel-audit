<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Steps;

use Astro2006\LaravelAudit\Contracts\Step;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Enums\SkipReason;
use Astro2006\LaravelAudit\Preflight\Condensers\MigrationSchema;
use Astro2006\LaravelAudit\Preflight\Data\Steps\MigrationsDTO;
use Astro2006\LaravelAudit\Preflight\PreflightContext;
use Astro2006\LaravelAudit\Preflight\StepResult;
use Illuminate\Support\Arr;

final class MigrationsStep implements Step
{
    public function __construct(private readonly MigrationSchema $schema = new MigrationSchema) {}

    public function step(): PreflightStep
    {
        return PreflightStep::Migrations;
    }

    public function run(PreflightContext $context): StepResult
    {
        if (! is_dir($context->project->path('database/migrations'))) {
            return StepResult::skipped($this->step(), SkipReason::NoConfig);
        }

        $schema = $this->schema->read($context->project);
        $context->writeJson('database/schema.json', $schema);

        $flagged = count(array_filter(Arr::get($schema, 'tables'), fn (array $table): bool => Arr::get($table, 'flags') !== []))
            + count(array_filter(Arr::get($schema, 'migrations'), fn (array $migration): bool => Arr::get($migration, 'flags') !== []));

        return StepResult::ok($this->step(), new MigrationsDTO(count(Arr::get($schema, 'migrations')), count(Arr::get($schema, 'tables')), $flagged));
    }
}
