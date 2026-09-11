# Task: Frontend map

## Mission

Build a short map of the frontend. Other frontend tasks rely on it, so be accurate and brief. You write no findings unless something is plainly broken (use check `other` sparingly).

## Inputs

- `{{preflight_dir}}/summary.json` — detected stack ({{frontend_stack}})
- `{{preflight_dir}}/routes.json` — route groups and middleware
- `resources/views`, `resources/js`, `resources/css`, `app/Livewire`, `app/View/Components`, Vite config

## Section you write

- `## Frontend map` — max ~15 lines: rendering stack, layouts, component library (Blade components, Vue/React components, UI kit), how buttons, forms, modals and tables are built, route groups and their middleware stacks, public vs authenticated vs admin areas. Name the paths.
