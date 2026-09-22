# Task: Dead frontend code

## Mission

Identify frontend code that is never used.

## Inputs

- `resources/views`, `resources/js`, `resources/css`, `app/View/Components`, `app/Livewire`, `lang/`
- Controllers, routes and components that reference views

## Checklist

- Views, partials, Blade/Livewire components, JS/Vue/React components, CSS files and translation keys that are never referenced. (`dead-frontend-code`)
- Check dynamic usage first (string-built view names, `view()->first()`, Livewire auto-discovery, Inertia page resolution, `@include` with variables, mail templates). If dynamic usage cannot be ruled out, use confidence `suspected`. Group into few findings.
