<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Support\Json;

/**
 * The log files of one agent call in the run's logs folder.
 */
final class TaskLog
{
    public function __construct(private readonly string $logsDir) {}

    /** Writes <name>.json and, when there is any, <name>.stderr.log. */
    public function write(string $name, string $output, string $errorOutput): void
    {
        if (! is_dir($this->logsDir)) {
            mkdir($this->logsDir, 0777, true);
        }

        if (trim($output) === '') {
            file_put_contents($this->logsDir.'/'.$name.'.json', $output);
        } else {
            Json::writeFile($this->logsDir.'/'.$name.'.json', $this->readable($output));
        }

        if (trim($errorOutput) !== '') {
            file_put_contents($this->logsDir.'/'.$name.'.stderr.log', $errorOutput);
        }
    }

    /**
     * Turns the agent output into one JSON document, so the log can be read
     * and formatted like any JSON file. A JSON Lines stream (OpenCode, Codex)
     * becomes a list of its events; a line that is not valid JSON stays in
     * the list as a string.
     */
    private function readable(string $output): mixed
    {
        $document = json_decode($output, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $document;
        }

        $events = [];

        foreach (explode("\n", $output) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $event = json_decode($line, true);
            $events[] = json_last_error() === JSON_ERROR_NONE ? $event : $line;
        }

        return $events;
    }
}
