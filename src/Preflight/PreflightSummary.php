<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight;

use Astro2006\LaravelAudit\Audits\DependenciesAudit;
use Astro2006\LaravelAudit\Data\Composer\DependencyFactsReport;
use Astro2006\LaravelAudit\Enums\GateDecision;
use Astro2006\LaravelAudit\Enums\PreflightStep;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;

/**
 * preflight/summary.json — the first file every agent reads.
 */
final readonly class PreflightSummary
{
    public function __construct(
        public string $runId,
        public GateDecision $gate,
        public StepResults $steps,
        public ?string $directory = null,
    ) {}

    public static function fromDirectory(string $preflightDir): self
    {
        $data = Json::readFile($preflightDir.'/summary.json');
        $steps = new StepResults;

        foreach (PreflightStep::cases() as $step) {
            $stepData = Read::array($data, 'steps.'.$step->value);

            if ($stepData !== []) {
                $steps->put(StepResult::fromArray($step, $stepData));
            }
        }

        return new self(
            Read::string($data, 'run_id'),
            GateDecision::tryFrom(Read::string($data, 'gate')) ?? GateDecision::NotRun,
            $steps,
            $preflightDir,
        );
    }

    public function dependencyFacts(): ?DependencyFactsReport
    {
        if ($this->directory === null || ! $this->steps->ran(PreflightStep::Composer)) {
            return null;
        }

        $data = Json::readFile($this->directory.'/'.DependenciesAudit::FACTS_FILE);

        return $data === [] ? null : DependencyFactsReport::fromArray($data);
    }
}
