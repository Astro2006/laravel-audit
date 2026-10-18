# Task: Livewire, Inertia and client secrets

## Mission

Find data that leaks to the client and unsafe Livewire components.

## Inputs

- `app/Livewire`, Inertia pages and `HandleInertiaRequests`, `.env.example` (`VITE_*`), Vite config, `resources/js`

## Checklist

- **Livewire:** public properties that must be `#[Locked]`, authorization inside actions (not only in `mount()`), `wire:model` bound to sensitive attributes, upload validation, hidden model attributes exposed via public properties. Skip without Livewire. (`livewire-security`)
- **Inertia:** `share()` leaking data (full user model, permissions, config), props that pass entire models instead of resources. Skip without Inertia. (`inertia-props-leak`)
- **Secrets in the client:** `VITE_*` variables containing secrets, API keys in JS, source maps shipped to production, sensitive HTML comments. (`client-secret`)
