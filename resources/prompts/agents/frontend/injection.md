# Task: Rendering and client-side security

## Mission

Find injection vectors in rendering and data that leaks to the client.

## Inputs

- `resources/views`, `resources/js`, `app/Livewire`, Inertia pages and `HandleInertiaRequests`, upload and download controllers, `.env.example` (`VITE_*`), Vite config
- The Code candidates snapshot above — unescaped output (`{!!`, `v-html`, `x-html`, `innerHTML`, `dangerouslySetInnerHTML`), redirects with request data and stored uploads, with file and line. Open those lines instead of searching

## Checklist

- **XSS:** `{!! !!}`, `v-html`, `dangerouslySetInnerHTML`, Alpine `x-html`, `innerHTML` in JS, markdown rendered without sanitizing, user data in `<script>` without `@js` / `Js::from()`, user-controlled `href`/`src` (`javascript:` URLs), SVG or HTML uploads served inline. (`xss`)
- **Livewire:** public properties that must be `#[Locked]`, authorization inside actions (not only in `mount()`), `wire:model` bound to sensitive attributes, upload validation, hidden model attributes exposed via public properties. Skip without Livewire. (`livewire-security`)
- **Inertia:** `share()` leaking data (full user model, permissions, config), props that pass entire models instead of resources. Skip without Inertia. (`inertia-props-leak`)
- **Open redirects:** `redirect()->to()` / `away()` with request input; header injection; `TrustProxies` / `TrustHosts` configuration. (`open-redirect`)
- **File uploads:** validation (`mimes` vs extension, `max`), storage on the public disk, trusting client filenames, path traversal in download routes. (`file-upload`)
- **Secrets in the client:** `VITE_*` variables containing secrets, API keys in JS, source maps shipped to production, sensitive HTML comments. (`client-secret`)
