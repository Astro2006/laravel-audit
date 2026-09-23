<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console\Commands;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Eval\EvalReport;
use Astro2006\LaravelAudit\Eval\EvalRunner;
use Astro2006\LaravelAudit\Eval\ExpectedSet;
use Illuminate\Console\Command;
use InvalidArgumentException;

class EvalCommand extends Command
{
    protected $signature = 'audit:eval
        {expected : Path to expected.json of the fixture app}
        {--runs=3 : Number of runs}
        {--audit=* : Limit to these audits}';

    protected $description = 'Evaluate the audit agents against a fixture app with known problems';

    protected $hidden = true;

    public function handle(EvalRunner $runner): int
    {
        try {
            $expected = ExpectedSet::fromFile((string) $this->argument('expected'));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $runs = max(1, (int) $this->option('runs'));
        $audits = array_values(array_intersect(AuditType::values(), array_map('strval', (array) $this->option('audit'))));

        $records = $runner->records($runs, $audits, function (int $run, array $options) use ($runs): void {
            $this->components->info(sprintf('Eval run %d of %d', $run, $runs));
            $this->call('audit', $options);
        });

        if ($records === []) {
            $this->components->error('No run produced a run.json.');

            return self::FAILURE;
        }

        $report = new EvalReport;
        $result = $report->evaluate($expected, $records);
        $path = $runner->write($report, $result);

        $this->newLine();

        foreach ($result->audits as $audit) {
            $recall = $audit->recall();
            $this->components->twoColumnDetail($audit->audit, sprintf(
                'recall %s · control hits %s · jaccard %s',
                $recall === null ? '–' : $recall.' %',
                implode('/', $audit->controlHits),
                $audit->stability->jaccard ?? '–',
            ));
        }

        $this->newLine();
        $this->line(' → '.$path);

        return self::SUCCESS;
    }
}
