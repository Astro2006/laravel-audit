# Task: UI consistency

## Mission

Find UI patterns implemented several ways, theme bypasses, translation gaps and layout problems.

## Inputs

- The frontend map (below, from another task)
- `resources/views`, `resources/js`, `resources/css`, the Tailwind/theme config, `lang/`
- `{{preflight_dir}}/translations.json` — translation keys missing per locale compared to the default locale

## Checklist

- The same UI element implemented several ways (buttons, cards, form fields, modals, tables) instead of a shared component. (`ui-inconsistency`)
- Inconsistent colors, spacing and typography; Tailwind arbitrary values (`text-[13px]`, `bg-[#1a2b3c]`) bypassing the theme; inline styles. (`design-tokens`)
- Hardcoded user-facing strings where the project otherwise uses `__()`; missing translation keys from `translations.json`. (`i18n`)
- Fixed widths, wide tables without overflow handling, small touch targets. (`responsiveness`)

## Section you write

- `## UI consistency matrix` — Pattern · Variants found · Files · Recommendation.
