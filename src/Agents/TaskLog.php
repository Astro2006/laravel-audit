<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Support\Json;

final class TaskLog
{
    public function __construct(private readonly string $logsDir) {}

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
