<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents\Drivers;

use Symfony\Component\Process\ExecutableFinder;

trait RunsCli
{
    public function binary(): string
    {
        return $this->binary;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function isAvailable(): bool
    {
        return is_file($this->binary) || (new ExecutableFinder)->find($this->binary) !== null;
    }

    /** @return list<string> `--model <model>`, or nothing for the CLI default */
    private function modelArguments(): array
    {
        return $this->model === null || $this->model === '' ? [] : ['--model', $this->model];
    }
}
