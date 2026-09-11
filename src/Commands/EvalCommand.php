<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Commands;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Eval\EvalReport;
use Astro2006\LaravelAudit\Eval\ExpectedSet;
use Astro2006\LaravelAudit\Support\HarnessFactory;
use Astro2006\LaravelAudit\Support\Json;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Developer command: runs the audit several times against the fixture app
 * and measures recall, false positives, stability and cost.
 */
class EvalCommand extends Command
{
    protected $signature = 'audit:eval
        {expected : Path to expected.json of the fixture app}
        {--runs=3 : Number of runs}
        {--audit=* : Limit to these audits}';

    protected $description = 'Evaluate the audit agents against a fixture app with known problems';

    protected $hidden = true;

    public function handle(HarnessFactory $factory): int
    {
        try {
            $expected = ExpectedSet::fromFile((string) $this->argument('expected'));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $outputRoot = rtrim((string) $factory->config('output_path', storage_path('audit')), '/');
        $runs = max(1, (int) $this->option('runs'));
        $audits = array_values(array_intersect(AuditType::values(), (array) $this->option('audit')));
        $runDirs = [];

        for ($i = 0; $i < $runs; $i++) {
            $before = glob($outputRoot.'/*', GLOB_ONLYDIR) ?: [];

            $options = ['--force' => true, '--no-interaction' => true];

            foreach ($audits as $audit) {
                $options['--'.$audit] = true;
            }

            // The tools are deterministic; only the agents vary between runs.
            if ($i > 0) {
                $options['--reuse-preflight'] = null;
            }

            $this->components->info(sprintf('Eval run %d of %d', $i + 1, $runs));
            $this->call('audit', $options);

            $created = array_values(array_diff(glob($outputRoot.'/*', GLOB_ONLYDIR) ?: [], $before));

            if ($created !== []) {
                $runDirs[] = $created[0];
            }
        }

        $data = array_values(array_filter(array_map(fn (string $dir): array => Json::readFile($dir.'/run.json'), $runDirs)));

        if ($data === []) {
            $this->components->error('No run produced a run.json.');

            return self::FAILURE;
        }

        $report = new EvalReport;
        $result = $report->evaluate($expected, $data);
        $path = $outputRoot.'/eval-'.date('Y-m-d_H-i-s').'.md';
        file_put_contents($path, $report->render($result));
        Json::writeFile(substr($path, 0, -3).'.json', $result);

        $this->newLine();

        foreach ($result['audits'] as $audit => $item) {
            $this->components->twoColumnDetail($audit, sprintf(
                'recall %s · control hits %s · jaccard %s',
                $item['recall'] === null ? '–' : $item['recall'].' %',
                implode('/', $item['control_hits']),
                $item['stability']['jaccard'] ?? '–',
            ));
        }

        $this->newLine();
        $this->line(' → '.$path);

        return self::SUCCESS;
    }
}
