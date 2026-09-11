<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Audits;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\PreflightStep;

final class BackendAudit extends BaseAudit
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
            'missing-index' => 'Missing indexes on foreign keys or frequently filtered columns.',
            'missing-cache' => 'Repeated expensive work without caching, or caches without invalidation.',
            'dead-code' => 'Unused controllers, jobs, events, listeners, commands, mailables, policies, services, enum cases or config keys.',
            ...$this->commonChecks(),
        ];
    }

    public function sections(): array
    {
        return ['Conventions baseline', 'Convention scorecard', 'Dead code candidates'];
    }

    public function preflightSteps(): array
    {
        return [
            PreflightStep::About,
            PreflightStep::Stack,
            PreflightStep::LinesOfCode,
            PreflightStep::Pint,
            PreflightStep::Routes,
            PreflightStep::Phpstan,
        ];
    }
}
