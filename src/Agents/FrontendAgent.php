<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents;

use Astro2006\LaravelAudit\Enums\AuditType;
use Astro2006\LaravelAudit\Enums\SnapshotType;

final class FrontendAgent extends Agent
{
    public function type(): AuditType
    {
        return AuditType::Frontend;
    }

    public function idPrefix(): string
    {
        return 'FE';
    }

    public function title(): string
    {
        return 'Frontend, Routes & UI/UX Audit';
    }

    public function checks(): array
    {
        return [
            'route-authorization' => 'A route or controller without auth / policy / can, or a Form Request whose authorize() returns true without other checks.',
            'idor' => 'Route model binding without an ownership check.',
            'state-changing-get' => 'A GET route that changes state.',
            'missing-throttle' => 'Login, registration, password reset, forms or API without throttling.',
            'csrf-exclusion' => 'An unjustified CSRF exclusion.',
            'signed-url' => 'Unsubscribe, download or invitation links without signed URLs.',
            'webhook-signature' => 'A webhook endpoint without signature verification.',
            'debug-route' => 'Debug or tooling routes reachable without a gate.',
            'broken-route' => 'Routes pointing to missing controllers or methods, duplicate or conflicting URIs.',
            'xss' => 'Unescaped output of untrusted data in Blade, Vue, React, Alpine or JS.',
            'livewire-security' => 'Livewire properties that must be locked, missing authorization in actions, unsafe uploads.',
            'inertia-props-leak' => 'Inertia shared data or props leaking models, permissions or config.',
            'open-redirect' => 'Redirects to request-controlled URLs, header injection, TrustProxies/TrustHosts.',
            'file-upload' => 'Upload validation, public storage, trusted client file names, traversal in downloads.',
            'client-secret' => 'Secrets in VITE_* variables, JS, source maps or HTML comments.',
            'security-headers' => 'Missing CSP, frame-ancestors or similar headers.',
            'session-cookie-config' => 'Insecure session or cookie settings.',
            'cors-config' => 'Overly permissive CORS, e.g. * with credentials.',
            'error-handling-ui' => 'Inconsistent validation errors, missing loading/empty/error states, double submits.',
            'error-pages' => 'Missing custom error pages, stack traces or exception messages shown to users.',
            'ui-inconsistency' => 'The same UI element implemented several ways.',
            'design-tokens' => 'Arbitrary values, inline styles or inconsistent colors, spacing and typography.',
            'i18n' => 'Hardcoded user-facing strings or missing translation keys.',
            'responsiveness' => 'Fixed widths, unscrollable tables, tiny touch targets.',
            'accessibility' => 'WCAG 2.2 AA issues visible in the markup.',
            'asset-performance' => 'Unoptimized images and assets, huge imports.',
            'view-query' => 'Lazy loading triggered from Blade or Livewire views.',
            'dead-frontend-code' => 'Unused views, components, JS/CSS files or translation keys.',
            ...$this->commonChecks(),
        ];
    }

    public function sections(): array
    {
        return ['Frontend map', 'Route risk table', 'UI consistency matrix'];
    }

    protected function taskList(): array
    {
        return [
            $this->task('map', 'Frontend map', [], sections: ['Frontend map'], snapshots: [SnapshotType::Routes]),
            $this->task('access', 'Route access control', ['route-authorization', 'idor', 'signed-url', 'webhook-signature'], sections: ['Route risk table'], snapshots: [SnapshotType::Routes]),
            $this->task('routes', 'Route hygiene', ['state-changing-get', 'missing-throttle', 'csrf-exclusion', 'debug-route', 'broken-route'], snapshots: [SnapshotType::Routes]),
            $this->task('injection', 'Rendering and client-side security', ['xss', 'livewire-security', 'inertia-props-leak', 'open-redirect', 'file-upload', 'client-secret']),
            $this->task('config', 'Headers, session and CORS', ['security-headers', 'session-cookie-config', 'cors-config']),
            $this->task('errors', 'Error and state handling', ['error-handling-ui', 'error-pages'], snapshots: [SnapshotType::Browser]),
            $this->task('ui', 'UI consistency', ['ui-inconsistency', 'design-tokens', 'i18n', 'responsiveness'], sections: ['UI consistency matrix'], dependsOn: ['map'], snapshots: [SnapshotType::Translations]),
            $this->task('a11y', 'Accessibility', ['accessibility'], dependsOn: ['map']),
            $this->task('performance', 'Frontend performance', ['asset-performance', 'view-query']),
            $this->task('dead-code', 'Dead frontend code', ['dead-frontend-code']),
        ];
    }
}
