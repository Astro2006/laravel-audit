<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\SnapshotType;

final class BackendAgent extends Agent
{
    public function type(): AuditType
    {
        return AuditType::Backend;
    }

    public function idPrefix(): string
    {
        return 'BE';
    }

    public function title(): string
    {
        return 'Backend Audit';
    }

    public function checks(): array
    {
        return [
            'env-outside-config' => 'env() called outside config/.',
            'inline-validation' => 'Inline validation in controllers where the project uses Form Requests.',
            'fat-controller' => 'Business logic in controllers, models or views; god classes.',
            'eloquent-hygiene' => 'Missing casts or relationship return types, mixed accessor styles, duplicated query logic, strict mode off.',
            'magic-values' => 'Magic strings where enums fit, hardcoded values that belong in config.',
            'transaction-safety' => 'Multi-step writes without a transaction, dispatches inside transactions without afterCommit.',
            'queue-robustness' => 'Jobs that are not idempotent or lack tries, backoff, timeout, uniqueness or failed() handling.',
            'scheduler-overlap' => 'Long or critical scheduled tasks without withoutOverlapping() / onOneServer().',
            'swallowed-exception' => 'Exceptions caught without rethrow or log, catching Throwable broadly.',
            'exception-leak' => 'Exception details returned to users or API clients.',
            'sensitive-logging' => 'Passwords, tokens, full payloads or personal data in logs.',
            'naming-structure' => 'Naming and structure that break PSR-4 or Laravel conventions.',
            'code-style' => 'Pint rule violations, one grouped finding per relevant rule.',
            'sql-injection' => 'Interpolated input in raw SQL, including sort and filter parameters.',
            'command-injection' => 'Shell or process commands built from input.',
            'unsafe-deserialization' => 'unserialize() on untrusted data.',
            'ssrf' => 'HTTP requests to user-controlled URLs or hosts.',
            'path-traversal' => 'File access with user-influenced paths.',
            'authorization-gap' => 'An action or service with several entry points where only some check permissions.',
            'mass-assignment' => 'Unfiltered request data reaching fill(), create() or update(), in controllers or services.',
            'weak-crypto' => 'md5/sha1 for security, homemade crypto, weak tokens, secret comparison without hash_equals().',
            'hardcoded-secret' => 'Secrets in PHP code, config defaults or .env.example; APP_DEBUG defaulting to true.',
            'data-exposure' => 'Sensitive attributes not hidden, resources or toArray() exposing internals.',
            'tenant-isolation' => 'Bypassable global scopes, queries without the tenant constraint.',
            'auth-config' => 'Password rehashing, session invalidation on password change, password rules.',
            'logic-bug' => 'A bug found by reading the code (not by a failing test or PHPStan).',
            'n-plus-one' => 'N+1 queries or queries in loops in PHP classes.',
            'inefficient-query' => 'Collection filtering that belongs in SQL, get()->count(), missing chunk()/lazy().',
            'missing-cache' => 'Repeated expensive work without caching, or caches without invalidation.',
            'missing-index' => 'Missing indexes on foreign keys or frequently filtered or sorted columns.',
            'missing-foreign-key' => 'Reference columns without a foreign key constraint, or with onDelete behaviour that orphans or loses data.',
            'missing-unique' => 'Uniqueness the code relies on (unique validation, firstOrCreate, lookups by a natural key) without a unique index.',
            'column-type' => 'Wrong column types: money as float, dates as strings, unbounded text for short values, nullable where the code never expects null.',
            'migration-safety' => 'Irreversible or risky migrations: missing or empty down(), dropped columns or tables with data, data changes through models.',
            'dead-code' => 'Unused controllers, jobs, events, listeners, commands, mailables, policies, services, enum cases or config keys.',
            ...$this->commonChecks(),
        ];
    }

    public function sections(): array
    {
        return ['Conventions baseline', 'Convention scorecard', 'Dead code candidates'];
    }

    protected function taskList(): array
    {
        return [
            $this->task('conventions', 'Conventions baseline', ['naming-structure', 'code-style'], sections: ['Conventions baseline', 'Convention scorecard'], snapshots: [SnapshotType::Pint]),
            $this->task('laravel', 'Laravel conventions', ['env-outside-config', 'inline-validation'], dependsOn: ['conventions'], snapshots: [SnapshotType::CodeCandidates]),
            $this->task('structure', 'Structure and responsibilities', ['fat-controller', 'magic-values'], dependsOn: ['conventions'], snapshots: [SnapshotType::Routes]),
            $this->task('data', 'Models and data exposure', ['eloquent-hygiene', 'mass-assignment', 'data-exposure'], dependsOn: ['conventions'], snapshots: [SnapshotType::CodeCandidates]),
            $this->task('jobs', 'Queues and scheduler', ['queue-robustness', 'scheduler-overlap']),
            $this->task('errors', 'Error handling and logging', ['swallowed-exception', 'exception-leak', 'sensitive-logging'], snapshots: [SnapshotType::CodeCandidates]),
            $this->task('injection', 'Injection', ['sql-injection', 'command-injection', 'unsafe-deserialization', 'ssrf', 'path-traversal'], snapshots: [SnapshotType::Routes, SnapshotType::CodeCandidates]),
            $this->task('auth', 'Authorization, auth and secrets', ['authorization-gap', 'auth-config', 'tenant-isolation', 'weak-crypto', 'hardcoded-secret'], snapshots: [SnapshotType::Routes, SnapshotType::CodeCandidates]),
            $this->task('logic', 'Logic and transactions', ['logic-bug', 'transaction-safety'], snapshots: [SnapshotType::Routes]),
            $this->task('performance', 'Performance', ['n-plus-one', 'inefficient-query', 'missing-cache']),
            $this->task('dead-code', 'Dead code', ['dead-code'], sections: ['Dead code candidates'], snapshots: [SnapshotType::Routes, SnapshotType::Phpstan]),
            $this->task('database', 'Database schema and migrations', ['missing-index', 'missing-foreign-key', 'missing-unique', 'column-type', 'migration-safety'], snapshots: [SnapshotType::DatabaseSchema]),
        ];
    }
}
