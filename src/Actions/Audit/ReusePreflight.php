<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Actions\Audit;

use Astro2006\LaravelAudit\Console\AuditConsole;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Astro2006\LaravelAudit\Preflight\StepResults;
use Astro2006\LaravelAudit\Run\AuditRun;
use Astro2006\LaravelAudit\Run\PreflightReuse;
use Astro2006\LaravelAudit\Run\RunManifest;
use Astro2006\LaravelAudit\Run\RunSelection;
use Astro2006\LaravelAudit\Support\ProjectInspector;

/**
 * Copies the preflight of an earlier run into this one (--reuse-preflight)
 * instead of running the tools again.
 */
final class ReusePreflight
{
    /**
     * @param  string|null  $runId  null = the latest run with a preflight
     * @return StepResults|null  null when there is no usable preflight
     */
    public function handle(AuditRun $run, RunManifest $manifest, RunSelection $selection, ProjectInspector $project, ?string $runId, AuditConsole $console): ?StepResults
    {
        $reuse = new PreflightReuse;
        $messages = $console->messages;
        $source = $reuse->find($run->outputRoot, $runId, $run->id);

        if ($source === null) {
            $console->error($messages->get('reuse.not_found', ['path' => $project->relative($run->outputRoot)]));

            return null;
        }

        $reuse->copy($run->outputRoot.'/'.$source->runId.'/preflight', $run->preflightDir());
        $stored = PreflightSummary::fromDirectory($run->preflightDir());
        $missing = $reuse->missingSteps($stored, $selection->requiredSteps());

        if ($missing !== []) {
            $console->error($messages->get('reuse.incomplete', ['run' => $source->runId, 'steps' => implode(', ', $missing)]));

            return null;
        }

        $console->line('  '.$messages->get('reuse.using', ['run' => $source->runId]));

        $now = ($manifest->git->commit ?? '').($manifest->git->dirty ? '+dirty' : '');

        if ($source->codeState() !== $now || str_contains($now, '+dirty')) {
            $console->line('  <fg=yellow>⚠</> '.$messages->get('reuse.commit_changed', ['then' => $source->codeState() ?: '?', 'now' => $now ?: '?']));
        }

        foreach ($stored->steps->all() as $result) {
            if (in_array($result->step, $selection->requiredSteps(), true)) {
                $console->stepFinished($result);
            }
        }

        $manifest->preflightFrom = $source->runId;

        return $stored->steps;
    }
}
