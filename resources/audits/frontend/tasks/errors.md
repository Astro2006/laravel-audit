# Task: Error and state handling

## Mission

Check what users see when something goes wrong or is loading.

## Inputs

- `resources/views` (forms, layouts, `errors/`), Livewire components, JS form handling, `bootstrap/app.php` (exception rendering)
- The Browser snapshot above — screenshots of failed browser tests, if any

## Checklist

- Validation errors displayed consistently, old input repopulated, loading/empty/error states, double-submit protection, flash messages. (`error-handling-ui`)
- Custom 403/404/419/500 pages; handling of expired sessions (419) in Livewire/Inertia/fetch calls; no stack traces or exception messages shown to users. (`error-pages`)
