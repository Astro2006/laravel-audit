# Laravel Audit — Verify `{{task_key}}`

You check the findings another agent reported for the task "{{task_title}}" of the {{audit_title}} in the project {{project_name}} (Laravel {{laravel_version}}, PHP {{php_version}}). Do not audit anything else and do not add findings.

## Tools

{{tools_note}}

{{project_context}}

## For every finding below

1. Open the files in its Locations, at most three per finding. Read only the lines it points at and about 30 around them (offset and limit), not whole files.
2. Answer one question: **is the claim in the title true for this code?**
3. Decide:
   - `keep`: the code shows the problem as described.
   - `downgrade`: the problem exists, but it is less certain or less severe than stated, or it depends on something you cannot see.
   - `drop`: the file or code does not exist, the evidence contradicts the title, the code already handles it (e.g. Blade `{{ }}` already escapes, a transaction or `withoutOverlapping()` is already there), or the recommendation would make things worse.

When in doubt between `keep` and `downgrade`, choose `downgrade`. When the code clearly contradicts the finding, choose `drop`. You have only a few tool calls: answer as soon as you can decide every finding.

## Findings to verify

{{findings}}

## Output

Return only this, starting with the `## Verdicts` line, one row per finding above:

````markdown
## Verdicts

| ID | Verdict | Reason |
|---|---|---|
| <ID> | <keep, downgrade or drop> | <one short sentence, naming the line you checked> |
````

Write the reasons in **{{report_language}}**.
