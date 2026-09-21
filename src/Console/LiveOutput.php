<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Finished lines go into a log that only grows; below it a live area shows
 * what is running right now and is redrawn in place. Without a terminal
 * (CI, pipes, tests) there is no live area and log lines are written as
 * they come.
 *
 * Sections only work while nothing else writes to the output. Call
 * release() before writing anything else; the next log() or show() starts
 * a new pair of sections below it.
 */
final class LiveOutput
{
    private ?ConsoleSectionOutput $log = null;

    private ?ConsoleSectionOutput $live = null;

    public function __construct(private readonly OutputInterface $output) {}

    public function enabled(): bool
    {
        return $this->output instanceof ConsoleOutputInterface && $this->output->isDecorated();
    }

    public function log(string $line): void
    {
        if (! $this->output instanceof ConsoleOutputInterface || ! $this->enabled()) {
            $this->output->writeln($line);

            return;
        }

        $this->sections($this->output)[0]->writeln($line);
    }

    /** @param list<string> $lines */
    public function show(array $lines): void
    {
        if (! $this->output instanceof ConsoleOutputInterface || ! $this->enabled()) {
            return;
        }

        $live = $this->sections($this->output)[1];

        if ($lines === []) {
            $live->clear();
        } else {
            $live->overwrite($lines);
        }
    }

    public function release(): void
    {
        $this->live?->clear();
        $this->log = null;
        $this->live = null;
    }

    /** @return array{ConsoleSectionOutput, ConsoleSectionOutput} */
    private function sections(ConsoleOutputInterface $output): array
    {
        $this->log ??= $output->section();
        $this->live ??= $output->section();

        return [$this->log, $this->live];
    }
}
