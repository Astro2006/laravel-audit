<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Support\ProjectInspector;
use Astro2006\LaravelAudit\Support\Read;

final class BinaryUsage
{
    private ?string $scripts = null;

    public function __construct(private readonly ProjectInspector $project) {}

    private function scriptText(): string
    {
        $text = json_encode(Read::array($this->project->composer()->composerJson(), 'scripts')).json_encode(Read::array($this->project->packageJson(), 'scripts'));

        foreach (glob($this->project->path('.github/workflows').'/*.{yml,yaml}', GLOB_BRACE) ?: [] as $workflow) {
            $text .= (string) file_get_contents($workflow);
        }

        foreach (['Makefile', 'Taskfile.yml'] as $file) {
            if (is_file($this->project->path($file))) {
                $text .= (string) file_get_contents($this->project->path($file));
            }
        }

        return $text;
    }

    /** @param list<string> $binaries */
    public function used(array $binaries): bool
    {
        $this->scripts ??= $this->scriptText();

        foreach ($binaries as $binary) {
            if ($binary !== '' && preg_match('/(^|[\s\/"\'])'.preg_quote($binary, '/').'([\s"\']|$)/', $this->scripts) === 1) {
                return true;
            }
        }

        return false;
    }
}
