<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Preflight\Data\Composer\FlaggedConstraint;
use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;

final class ComposerHygiene
{
    /** @return list<FlaggedConstraint> */
    public function constraints(ProjectInspector $project): array
    {
        $composer = $project->composer()->composerJson();
        $result = [];

        foreach (['require', 'require-dev'] as $section) {
            foreach (Read::stringMap($composer, $section) as $package => $constraint) {
                $reason = match (true) {
                    trim($constraint) === '*' => 'any version',
                    str_starts_with($constraint, 'dev-') || str_contains($constraint, '@dev') => 'development branch',
                    str_contains($constraint, '>') && ! str_contains($constraint, '<') && ! str_contains($constraint, '^') && ! str_contains($constraint, '~') => 'no upper bound',
                    default => null,
                };

                if ($reason !== null) {
                    $result[] = new FlaggedConstraint(strtolower($package), $constraint, $reason);
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    public function config(ProjectInspector $project): array
    {
        $composer = $project->composer()->composerJson();
        $issues = [];

        if (Read::string($composer, 'minimum-stability', 'stable') === 'dev' && data_get($composer, 'prefer-stable') !== true) {
            $issues[] = 'minimum-stability is "dev" without prefer-stable: true';
        }

        if (data_get($composer, 'config.allow-plugins') === true) {
            $issues[] = 'config.allow-plugins is true for every plugin';
        }

        foreach (Read::arrays($composer, 'repositories') as $repository) {
            $type = Read::string($repository, 'type');

            if (in_array($type, ['vcs', 'path', 'artifact', 'package'], true)) {
                $issues[] = sprintf('custom repository of type "%s": %s', $type, Read::string($repository, 'url', (string) json_encode($repository)));
            }
        }

        $platform = Read::nullableString($composer, 'config.platform.php');

        if ($platform !== null) {
            $issues[] = 'config.platform.php is set to '.$platform.' — compare with the production PHP version';
        }

        return $issues;
    }
}
