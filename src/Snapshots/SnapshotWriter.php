<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Snapshots;

use Astro2006\LaravelAudit\Contracts\Snapshot;
use Astro2006\LaravelAudit\Preflight\PreflightSummary;
use Illuminate\Filesystem\Filesystem;

/**
 * Writes every snapshot of the preflight: preflight/snapshots/<type>.md (what
 * goes into the prompt) and preflight/details/<type>/<ID>.md (what the agent
 * opens on demand). Rewritten on every run, so a reused preflight gets them too.
 */
final class SnapshotWriter
{
    /** @param  list<Snapshot>  $snapshots */
    public function __construct(
        private readonly array $snapshots,
        private readonly int $maxRows = 15,
        private readonly Filesystem $files = new Filesystem,
    ) {}

    /**
     * @param  string  $displayDir  the preflight directory as the agent sees it (relative to the project)
     * @return array<string, string> snapshot type => markdown for the prompt
     */
    public function write(PreflightSummary $preflight, string $displayDir): array
    {
        if ($preflight->directory === null || ! is_dir($preflight->directory)) {
            return [];
        }

        $this->files->deleteDirectory($preflight->directory.'/snapshots');
        $this->files->deleteDirectory($preflight->directory.'/details');

        $rendered = [];
        $displayDir = rtrim($displayDir, '/');

        foreach ($this->snapshots as $snapshot) {
            $manifest = $snapshot->build($preflight, $displayDir);

            if ($manifest === null) {
                continue;
            }

            $type = $manifest->type->value;
            $dir = $preflight->directory.'/details/'.$type;
            $this->files->ensureDirectoryExists($dir);

            foreach ($manifest->rows as $index => $row) {
                if ($row->details !== null) {
                    $id = $manifest->id($index);
                    $this->files->put($dir.'/'.$id.'.md', "# {$id}\n\n".trim($row->details)."\n");
                }
            }

            if (count($manifest->rows) > $this->maxRows) {
                $this->files->put($dir.'/'.SnapshotManifest::ALL_FILE.'.md', '# '.$manifest->title."\n\n".implode("\n", $manifest->table())."\n");
            }

            foreach ($manifest->files as $file) {
                $this->files->put($dir.'/'.$file->name.'.md', '# '.$file->label."\n\n".trim($file->content)."\n");
            }

            $text = $manifest->render($displayDir.'/details/'.$type, $this->maxRows);
            $this->files->ensureDirectoryExists($preflight->directory.'/snapshots');
            $this->files->put($preflight->directory.'/snapshots/'.$type.'.md', $text."\n");
            $rendered[$type] = $text;
        }

        return $rendered;
    }
}
