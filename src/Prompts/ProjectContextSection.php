<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Prompts;

use Astro2006\LaravelAudit\Preflight\Data\Steps\AboutDTO;
use Illuminate\Support\Arr;

final class ProjectContextSection
{
    private const array DRIVERS = ['database', 'cache', 'queue', 'session', 'broadcasting', 'mail'];

    private const string RULES = 'Rate exploitability against this context: in a single-user app, a finding that needs another logged-in user is at most `low` unless it is reachable without login. Name the database and the drivers only as given in the runtime facts.';

    /**
     * The `## Project context` section of the task and verify prompts: what
     * the project owner wrote in `project.context`, plus the runtime facts
     * the preflight read. Nothing when there is neither.
     *
     * @return string the section, or an empty string
     */
    public static function render(?string $context, ?AboutDTO $about): string
    {
        $context = trim((string) $context);
        $facts = $about === null ? [] : self::facts($about);

        if ($context === '' && $facts === []) {
            return '';
        }

        $parts = ['## Project context'];

        if ($context !== '') {
            $parts[] = $context;
        }

        if ($facts !== []) {
            $parts[] = 'Runtime facts: '.implode(' · ', $facts).'.';
        }

        $parts[] = self::RULES;

        return implode("\n\n", $parts);
    }

    /** @return list<string> */
    private static function facts(AboutDTO $about): array
    {
        $facts = [];

        if ($about->laravel !== null) {
            $facts[] = 'Laravel '.$about->laravel;
        }

        if ($about->php !== null) {
            $facts[] = 'PHP '.$about->php;
        }

        foreach (self::DRIVERS as $driver) {
            $value = Arr::get($about->drivers, $driver);

            if (is_string($value) && $value !== '') {
                $facts[] = $driver.' '.$value;
            }
        }

        return $facts;
    }
}
