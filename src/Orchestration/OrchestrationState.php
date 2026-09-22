<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Orchestration;

use Astro2006\LaravelAudit\Data\AuditOptions;
use Astro2006\LaravelAudit\Support\Json;
use Astro2006\LaravelAudit\Support\Read;

final readonly class OrchestrationState
{
    public const string FILE = 'state.json';

    /**
     * @param  list<string>  $audits  the selected audits
     * @param  list<string>  $without
     */
    public function __construct(
        public array $audits,
        public array $without,
        public bool $force,
        public ?string $agent,
        public ?string $model,
        public ?string $mode,
    ) {}

    public static function fromOptions(AuditOptions $options, RunPlan $plan): self
    {
        return new self($plan->selection->auditValues(), $plan->selection->withoutValues(), $options->force, $plan->driver->name(), $options->model, $options->mode);
    }

    public static function read(string $directory): ?self
    {
        $data = Json::readFile($directory.'/'.self::FILE);

        if ($data === []) {
            return null;
        }

        return new self(
            Read::strings($data, 'audits'),
            Read::strings($data, 'without'),
            Read::bool($data, 'force'),
            Read::nullableString($data, 'agent'),
            Read::nullableString($data, 'model'),
            Read::nullableString($data, 'mode'),
        );
    }

    public function write(string $directory): void
    {
        Json::writeFile($directory.'/'.self::FILE, [
            'audits' => $this->audits,
            'without' => $this->without,
            'force' => $this->force,
            'agent' => $this->agent,
            'model' => $this->model,
            'mode' => $this->mode,
        ]);
    }

    public function options(): AuditOptions
    {
        return new AuditOptions(
            audits: $this->audits,
            without: $this->without,
            force: $this->force,
            agent: $this->agent,
            model: $this->model,
            mode: $this->mode,
        );
    }
}
