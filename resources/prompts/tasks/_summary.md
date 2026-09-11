# Laravel Audit — Summary of the {{audit_title}}

You write the summary of the {{audit_title}} for {{project_name}} (Laravel {{laravel_version}} · PHP {{php_version}}). Other tasks already audited the code; their findings are listed below with the IDs they have in the final report.

## Rules

- Work only from the list below. Do not open files and do not audit again.
- Refer to findings by their IDs (`{{id_prefix}}-004`); do not copy their text.
- Do not add findings and do not change severities.
- No secrets in your answer.

## Findings

{{findings_list}}

Tasks that failed (their checks were not audited): {{failed_tasks}}

## What to write

`## Summary` — 3 to 5 sentences: the overall state, the most important findings by ID and why they matter, and what was not audited because a task failed.

## Output

Return only this as your final answer, starting directly with the `## Summary` line, without a surrounding code fence:

````markdown
## Summary
<3–5 sentences>
{{sections_template}}````

Report language: **{{report_language}}** for all prose. Keep IDs, identifiers and section headings exactly as they are.
