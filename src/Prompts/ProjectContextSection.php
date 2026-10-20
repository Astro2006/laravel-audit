<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Prompts;

use Astro2006\LaravelAudit\Preflight\Data\Steps\AboutDTO;
use Illuminate\Support\Arr;

final class ProjectContextSection
{
    private const array DRIVERS = ['database', 'cache', 'queue', 'session', 'broadcasting', 'mail'];

    private const string WITH_CONTEXT = 'Rate exploitability against this context. Only when it says the app has a single user is a finding that needs another logged-in user at most `low`, unless it is reachable without login.';

    private const string WITHOUT_CONTEXT = 'The project context is not set: do not assume how many users the app has, what roles they hold or where it runs. Rate exploitability as if any logged-in user may be hostile, and say in the finding which assumption the severity depends on.';

    private const string DRIVERS_RULE = 'Name the database and the drivers only as given in the runtime facts.';

    /**
     * The `## Project context` section of the task and verify prompts: what
     * the project owner wrote in `project.context`, plus the runtime facts
     * the preflight read. Nothing when there is neither. Without a context
     * the rule tells the model not to guess the user model, because a model
     * given a single-user example once took it for a fact.
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

        $parts[] = ($context === '' ? self::WITHOUT_CONTEXT : self::WITH_CONTEXT).' '.self::DRIVERS_RULE;

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
