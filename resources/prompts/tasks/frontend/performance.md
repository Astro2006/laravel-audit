# Task: Frontend performance

## Mission

Find frontend performance problems and queries triggered from views.

## Inputs

- `resources/views`, `resources/js`, `public/` (excluding build output), Vite config, `package.json`
- Livewire components and the views that loop over models

## Checklist

- Images without lazy loading or dimensions, large imports (whole utility libraries), unoptimized assets in `public/`. (`asset-performance`)
- Relationship access inside loops in Blade/Livewire views (lazy loading visible from the view). Report it with the view location and name the query origin. (`view-query`)
