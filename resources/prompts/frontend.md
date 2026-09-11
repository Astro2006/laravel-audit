# Audit: Frontend, Routes & UI/UX

`audit_key: frontend` · `id_prefix: FE`

## Mission

Audit everything a user or an attacker touches: routes and entry points, how input is handled at the edge, how data is rendered, client-side code and UI/UX consistency. Find injection vectors, access-control gaps at the route level and user-facing bugs.

## Inputs

- `{{preflight_dir}}/summary.json` — detected stack ({{frontend_stack}}), browser test status
- `{{preflight_dir}}/routes.json` — all routes with methods, names, actions and middleware
- `{{preflight_dir}}/tests/browser-junit.xml` — correlate UI failures with your findings (if present)
- `{{preflight_dir}}/npm-audit.json` — context only, if present (the dependencies agent owns dependency issues)

## Scope

**In:** `routes/*.php`, middleware, controllers **as entry points** (input handling and what they return), Form Requests as far as they guard entry points, `resources/views`, `resources/js`, `resources/css`, Livewire components, Inertia pages and shared props, Filament panels, `lang/`, `public/` (excluding build output), Vite config, security headers, session/cookie/CORS config.

**Out:** Domain logic, jobs, model internals (backend agent), tests (tests agent), dependency CVEs (dependencies agent).

**Ownership rule for overlaps:** if untrusted input enters through a route, form, Livewire property or Inertia request and reaches a dangerous sink, you own the finding and reference the backend location. Pure backend-internal issues belong to the backend agent.

## Step 0 — Map the frontend

Before checking anything, build a short map: rendering stack, layouts, component library (Blade components, Vue/React components, UI kit), route groups and their middleware stacks, public vs authenticated vs admin areas. Put it in the report as "Frontend map" (max ~15 lines).

## Checklist

### 1. Routes & access control
- Routes handling user data without `auth`; missing `verified`, `can:` / policy checks, or `password.confirm` on sensitive actions; admin areas protected only by hiding UI elements.
- State-changing actions via GET; missing `throttle` on login, registration, password reset, contact forms and API; API routes without Sanctum or equivalent.
- Route model binding: IDOR risk (no policy, no `scopeBindings()` for nested resources), `withTrashed()` exposure.
- Signed URLs where needed (unsubscribe, downloads, invitations); webhooks without signature verification; CSRF exclusions justified?
- Debug and tooling routes reachable in production (Telescope, Horizon, Pulse, log viewers, `/test`, `/phpinfo`) without a gate.
- Routes pointing to missing controllers or methods, duplicate or conflicting URIs, unnamed routes where the project names them.

### 2. Injection & client-side security
- **XSS:** `{!! !!}`, `v-html`, `dangerouslySetInnerHTML`, Alpine `x-html`, `innerHTML` in JS, markdown rendered without sanitizing, user data in `<script>` without `@js` / `Js::from()`, user-controlled `href`/`src` (`javascript:` URLs), SVG or HTML uploads served inline.
- **Livewire:** public properties that must be `#[Locked]`, authorization inside actions (not only in `mount()`), `wire:model` bound to sensitive attributes, upload validation, models with hidden attributes exposed via public properties.
- **Inertia:** `HandleInertiaRequests::share()` leaking data (full user model, permissions, config), props that pass entire models instead of resources or `only()`.
- **Open redirects:** `redirect()->to()` / `away()` with request input; header injection; `TrustProxies` / `TrustHosts` configuration.
- **SQL / column injection from list views:** sort, order and filter parameters passed into `orderBy()`, `orderByRaw()`, `whereRaw()` or `DB::raw()` without an allow-list.
- **Mass assignment at the edge:** `$request->all()` or `input()` passed into `create()`, `update()`, `fill()`.
- **File uploads:** validation (`mimes` vs extension, `max`), storage on the public disk, trusting client filenames, path traversal in download routes.
- **Secrets in the client:** `VITE_*` variables containing secrets, API keys in JS, source maps shipped to production, sensitive HTML comments.
- **Headers & cookies:** CSP, `X-Frame-Options`/`frame-ancestors`, `secure`, `http_only`, `same_site` in `config/session.php`, CORS config (`*` with credentials).

### 3. Error & state handling
- Validation errors displayed consistently, old input repopulated, loading/empty/error states, double-submit protection, flash messages.
- Custom 403/404/419/500 pages; handling of expired sessions (419) in Livewire/Inertia; no stack traces or exception messages shown to users.

### 4. UI/UX consistency
- Same UI element implemented several ways (buttons, cards, form fields, modals, tables) instead of a shared component.
- Design tokens: inconsistent colors, spacing and typography; Tailwind arbitrary values (`text-[13px]`, `bg-[#1a2b3c]`) bypassing the theme; inline styles.
- Inconsistent patterns: date, number and currency formatting; button labels and order; confirmation for destructive actions; pagination; icon set.
- i18n: hardcoded user-facing strings where the project otherwise uses `__()`; translation keys missing in some locales (compare `lang/` files).
- Responsiveness: fixed widths, wide tables without overflow handling, touch targets.

### 5. Accessibility (WCAG 2.2 AA, static review)
- Inputs without labels, images without `alt`, icon-only buttons without accessible names, clickable `div`/`span` instead of buttons or links, broken heading hierarchy, `outline-none` without a visible focus replacement, state communicated by color only, missing `lang` attribute, modals without focus management.

### 6. Frontend performance
- Images without lazy loading or dimensions, large imports (whole utility libraries), unoptimized assets in `public/`.
- Relationship access inside loops in Blade/Livewire views (N+1 visible from the view). Report it here with the view location; reference the query origin.

### 7. Dead frontend code
- Views, partials, Blade/Livewire components, JS/Vue/React components, CSS files and translation keys that are never referenced.
- Check dynamic usage first (string-built view names, `view()->first()`, Livewire auto-discovery, Inertia page resolution, `@include` with variables). If dynamic usage cannot be ruled out, use confidence `suspected`.

### 8. Custom checks
{{custom_checks}}

## Audit-specific report sections

Add these as level-2 sections between `## Findings` and `## Positive observations`:

- **Frontend map** — short overview from Step 0.
- **Route risk table** — Route · Methods · Middleware · Risk notes. Only sensitive routes or routes with findings, max 30 rows.
- **UI consistency matrix** — Pattern · Variants found · Files · Recommendation.
