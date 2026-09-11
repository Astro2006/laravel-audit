# Audit: Frontend, Routes & UI/UX

`audit_key: frontend` · `id_prefix: FE`

## Mission

Audit everything a user or an attacker touches: routes and entry points, access control at the route level, how data is rendered, client-side code and UI/UX consistency. Find injection vectors in rendering, access-control gaps at the route level and user-facing bugs.

## Inputs

- `{{preflight_dir}}/summary.json` — detected stack ({{frontend_stack}}), step status
- `{{preflight_dir}}/routes.json` — all routes with methods, names, actions and middleware
- `{{preflight_dir}}/route-facts.json` — **candidates, not findings**: write routes without `auth`, GET routes with state-changing action names, auth and form routes without `throttle`, debug/tooling routes, routes pointing to missing controller methods. Confirm or reject each candidate.
- `{{preflight_dir}}/translations.json` — translation keys missing per locale compared to the default locale
- `{{preflight_dir}}/tests/browser-condensed.json` and `{{preflight_dir}}/tests/browser-artifacts/` — browser test results and screenshots of failures ({{browser_tests}}). Use them as evidence for your own checks; failing tests as such belong to the tests agent.

## Scope

**In:** `routes/*.php`, middleware, controllers and Form Requests **as entry points** (who may call them, what they return), `resources/views`, `resources/js`, `resources/css`, Livewire components, Inertia pages and shared props, Filament panels, `lang/`, `public/` (excluding build output), Vite config, security headers, session/cookie/CORS config.

**Out (other agents own these checks):** mass assignment and SQL injection — also when they start at a route (backend), domain logic, jobs, model internals (backend), tests and failing tests (tests), dependency CVEs (dependencies). Note such observations under `### Out of scope observations`.

## Step 0 — Map the frontend

Before checking anything, build a short map: rendering stack, layouts, component library (Blade components, Vue/React components, UI kit), route groups and their middleware stacks, public vs authenticated vs admin areas. Put it in the report as "Frontend map" (max ~15 lines).

## Checklist

### 1. Routes & access control
- Routes handling user data without `auth`; missing `verified`, `can:` / policy checks, or `password.confirm` on sensitive actions; admin areas protected only by hiding UI elements; Form Requests whose `authorize()` returns `true` without another check. (`route-authorization`)
- Route model binding without an ownership check, no `scopeBindings()` for nested resources, `withTrashed()` exposure. (`idor`)
- State-changing actions via GET. (`state-changing-get`)
- Missing `throttle` on login, registration, password reset, contact forms and API. (`missing-throttle`)
- CSRF exclusions justified? Signed URLs for unsubscribe, downloads, invitations? Webhooks with signature verification? (`csrf-exclusion`, `signed-url`, `webhook-signature`)
- Debug and tooling routes reachable in production (Telescope, Horizon, Pulse, log viewers, `/test`, `/phpinfo`) without a gate. (`debug-route`)
- Routes pointing to missing controllers or methods, duplicate or conflicting URIs. (`broken-route`)

### 2. Injection & client-side security
- **XSS:** `{!! !!}`, `v-html`, `dangerouslySetInnerHTML`, Alpine `x-html`, `innerHTML` in JS, markdown rendered without sanitizing, user data in `<script>` without `@js` / `Js::from()`, user-controlled `href`/`src` (`javascript:` URLs), SVG or HTML uploads served inline. (`xss`)
- **Livewire:** public properties that must be `#[Locked]`, authorization inside actions (not only in `mount()`), `wire:model` bound to sensitive attributes, upload validation, hidden model attributes exposed via public properties. (`livewire-security`)
- **Inertia:** `HandleInertiaRequests::share()` leaking data (full user model, permissions, config), props that pass entire models instead of resources. (`inertia-props-leak`)
- **Open redirects:** `redirect()->to()` / `away()` with request input; header injection; `TrustProxies` / `TrustHosts` configuration. (`open-redirect`)
- **File uploads:** validation (`mimes` vs extension, `max`), storage on the public disk, trusting client filenames, path traversal in download routes. (`file-upload`)
- **Secrets in the client:** `VITE_*` variables containing secrets, API keys in JS, source maps shipped to production, sensitive HTML comments. (`client-secret`)
- **Headers & cookies:** CSP, `X-Frame-Options`/`frame-ancestors` (`security-headers`); `secure`, `http_only`, `same_site` in `config/session.php` (`session-cookie-config`); CORS config (`cors-config`).

### 3. Error & state handling
- Validation errors displayed consistently, old input repopulated, loading/empty/error states, double-submit protection, flash messages. (`error-handling-ui`)
- Custom 403/404/419/500 pages; handling of expired sessions (419) in Livewire/Inertia; no stack traces or exception messages shown to users. (`error-pages`)

### 4. UI/UX consistency
- Same UI element implemented several ways (buttons, cards, form fields, modals, tables) instead of a shared component. (`ui-inconsistency`)
- Inconsistent colors, spacing and typography; Tailwind arbitrary values (`text-[13px]`, `bg-[#1a2b3c]`) bypassing the theme; inline styles. (`design-tokens`)
- Hardcoded user-facing strings where the project otherwise uses `__()`; missing translation keys from `translations.json`. (`i18n`)
- Fixed widths, wide tables without overflow handling, touch targets. (`responsiveness`)

### 5. Accessibility (WCAG 2.2 AA, static review)
- Inputs without labels, images without `alt`, icon-only buttons without accessible names, clickable `div`/`span` instead of buttons or links, broken heading hierarchy, `outline-none` without a visible focus replacement, state communicated by color only, missing `lang` attribute, modals without focus management. (`accessibility`)

### 6. Frontend performance
- Images without lazy loading or dimensions, large imports (whole utility libraries), unoptimized assets in `public/`. (`asset-performance`)
- Relationship access inside loops in Blade/Livewire views (lazy loading visible from the view). Report it with the view location and name the query origin. (`view-query`)

### 7. Dead frontend code
- Views, partials, Blade/Livewire components, JS/Vue/React components, CSS files and translation keys that are never referenced. (`dead-frontend-code`)
- Check dynamic usage first (string-built view names, `view()->first()`, Livewire auto-discovery, Inertia page resolution, `@include` with variables). If dynamic usage cannot be ruled out, use confidence `suspected`.

### 8. Custom checks
{{custom_checks}}

## Audit-specific report sections

Add these as level-2 sections between `## Findings` and `## Positive observations`:

- **Frontend map** — short overview from Step 0.
- **Route risk table** — Route · Methods · Middleware · Risk notes. Only sensitive routes, confirmed or rejected candidates from `route-facts.json`, and routes with findings; max 30 rows.
- **UI consistency matrix** — Pattern · Variants found · Files · Recommendation.
