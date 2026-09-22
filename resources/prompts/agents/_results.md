# Laravel Audit — Overview of the run

You write the overview of this audit run of {{project_name}} (Laravel {{laravel_version}}), run `{{run_id}}`. Every selected audit already finished and wrote its own summary; they are listed below with their finding counts by severity.

## Rules

- Work only from the digests below. Do not open files and do not audit again.
- Refer to findings by their IDs (e.g. `BE-004`); do not copy their text.
- Do not invent findings, severities or counts that are not in the digests.
- Call out patterns a single audit could not see on its own: the same root cause showing up in more than one audit, or one audit's finding making another one worse.
- No secrets in your answer.

## Audits

{{audits_digest}}

## What to write

`## Summary` — 4 to 6 sentences for someone who will read only this before deciding what to fix first: the overall state of the app, the most important findings across all audits by ID and why they matter together, and any cross-audit pattern you noticed. End with what to fix first.

## Output

Return only this as your final answer, starting directly with the `## Summary` line, without a surrounding code fence:

````markdown
## Summary
<4–6 sentences>
````

Report language: **{{report_language}}** for all prose. Keep IDs and section headings exactly as they are.
