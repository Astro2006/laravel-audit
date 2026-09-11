<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * An audit report put together from its task reports.
 */
final readonly class AssembledReport
{
    /**
     * @param  string  $body  the report in the audit format (Summary … Limitations)
     * @param  array<string, string>  $findingTasks  final finding ID => task key
     * @param  list<string>  $completedTasks  keys of the tasks whose output is in the report
     * @param  list<string>  $failedTasks  keys of the tasks that failed or did not run
     */
    public function __construct(
        public string $body,
        public array $findingTasks,
        public array $completedTasks,
        public array $failedTasks,
    ) {}
}
