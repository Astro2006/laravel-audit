# Audit: Executive Summary

`audit_key: summary`

> This prompt is used **without** `_base.md`. It runs after all audit agents are done and can be disabled with `--no-summary`.

## Context

- Project: {{project_name}} · Laravel {{laravel_version}} · PHP {{php_version}}
- Run ID: {{run_id}}
- Run directory (read-only): `{{output_dir}}`
- History: `{{history_file}}` — the last {{history_runs}} completed runs (empty if no history was requested)
- Report language: **{{report_language}}**

## Inputs

- `{{output_dir}}/tests.md`, `frontend.md`, `backend.md`, `dependencies.md` (whichever exist — some audits may have been skipped or failed)
- `{{output_dir}}/scores.md` — written by the harness from the parsed findings. **Do not recompute or change scores.**
- `{{output_dir}}/preflight/summary.json`
- `{{history_file}}` — condensed history built by the harness: previous runs with scores, score trend per audit, and a finding timeline with status (`new`, `open (N runs)`, `gone`, `regressed`, `severity changed`, `flaky`) and match quality (`exact`, `fuzzy`). **Do not open old run directories.** The history file is all you need, except for the targeted checks in task 7.

All audit reports follow the report format: frontmatter, then `## Findings` with `### ID — Title` headings and a metadata table (Severity, Confidence, Category, Effort, Locations, …). Read the metadata tables first; read Evidence/Impact only where you need them for merging or chains.

## Rules

- Do not re-audit the codebase. Open a source file only to resolve a duplicate or a contradiction between agents.
- Read-only: never create, modify or delete any file. No secrets in the output.
- Refer to findings by their IDs (`BE-004`); do not copy their full text.

## Tasks

1. **Deduplicate.** Same root cause reported by several agents → one merged entry: keep the highest severity, list all IDs.
2. **Attack chains & systemic issues.** Combine findings that together create a bigger risk (e.g. route without policy `FE-004` + no authorization test `TST-007` + mass assignment `BE-012` → account takeover path). Also name patterns that point to a process problem (e.g. "authorization is consistently checked only in the UI").
3. **Severity consistency.** Flag findings whose severity looks inconsistent across audits. Do not change them silently; list them as adjusted with a reason.
4. **Top 10 priorities**, ordered by risk reduction per effort.
5. **Quick wins**: fixes under one hour each (Effort `S`).
6. **Roadmap**: Now (this week) · Next (this sprint) · Later.
7. **Trend** — only if `{{history_file}}` is set:
   - **Score trajectory:** overall and per audit across all runs in the history. Name the direction and the biggest mover in one or two sentences.
   - **Fuzzy matches:** confirm or reject every `fuzzy` row by comparing titles and locations. Treat rejected ones as `new`.
   - **Gone ≠ fixed:** for every `gone` finding with severity critical or high, open the location in the current code and check whether the issue still exists. Report it as *fixed (verified)* or *still present (missed this run)*. Lower severities stay *gone (unverified)*.
   - **Regressions:** `regressed` findings go into the top priorities regardless of effort — something that was fixed broke again.
   - **Chronic issues:** findings `open` for 3 or more runs. Name them explicitly and raise them in the priority order; long-open findings are a process signal.
   - **Flaky findings:** list them separately. They indicate unstable detection, not necessarily unstable code.
   - **Agent/model changes:** the history lists agent and model per run. If they changed between runs, say so next to the trend. New, gone or flaky findings may then come from a different model looking differently, not from code changes. Do not report a score jump after such a change as progress or regression without this caveat.
8. **Management summary**: 5 sentences, non-technical, readable by someone who does not code.

## Output

Do **not** write any files. Return the summary as your **final answer**, starting directly with the `---` frontmatter line, with nothing before or after it and no surrounding code fence. The harness saves it as `{{output_dir}}/SUMMARY.md`.

````markdown
---
format: 1
audit: summary
run_id: {{run_id}}
agent: <your agent/model name>
generated_at: <ISO 8601 timestamp>
---

# Audit Summary — {{run_id}}

## Management summary
<5 sentences>

## Scores
<copy the table from scores.md unchanged>

## Top 10 priorities

| # | IDs | Title | Severity | Effort | Why now |
|---|---|---|---|---|---|

## Attack chains & systemic issues
### <Chain title>
| Field | Value |
|---|---|
| Severity | <critical, high, medium, low or info> |
| Findings | <IDs, comma-separated> |

<Explanation: how the findings combine, and which single fix breaks the chain.>

## Quick wins
<list: ID — one-line action>

## Roadmap
### Now
### Next
### Later

## Trend
<1–2 sentences on the direction>

### Score trend
<copy the score trend table from the history file>

| Status | Findings (current ID or history key + title) |
|---|---|
| Fixed (verified) | |
| Gone (unverified) | |
| Still present (missed this run) | |
| Regressed | |
| Chronic (open ≥ 3 runs) | |
| New | |
| Flaky | |

## Merged & adjusted findings
| IDs | Merged title | Severity | Adjustment / reason |
|---|---|---|---|

## Missing or failed audits
````

If `{{history_file}}` is empty, write the single line `No history requested.` under `## Trend`.
