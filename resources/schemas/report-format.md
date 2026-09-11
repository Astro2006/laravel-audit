# Report Format (v1)

Every audit is split into tasks. Each task agent returns a **task report** (section 9) as its final answer; the harness validates it, assembles the task reports of one audit into the **audit report** described here, validates that too, and saves it as `{output_dir}/{audit_key}.md`.
The report is written for humans, but follows a strict structure so the harness (`ReportParser`, `ReportLinter`) can extract every finding reliably for validation, keys, metrics and later exports.

Rule of thumb: **everything the harness needs lives in the level-2 headings, the finding headings and the finding metadata tables.** Everything else is free markdown.

---

## 1. Who writes what

| Part | Written by |
|---|---|
| Frontmatter, H1 title | harness |
| `## Summary` | summary task (a generated one if that task failed) |
| `## Findings` | the task agents; the harness renumbers the IDs across tasks |
| Audit-specific sections | the task that owns each section |
| `## Positive observations`, `## Limitations` | merged from the tasks; failed tasks are listed under Limitations |

A task agent's answer starts with `## Findings` (the summary task's with `## Summary`). Anything before the first level-2 heading (introductions, a frontmatter, an H1) and a surrounding code fence are removed by the harness.

Frontmatter written by the harness:

```markdown
---
format: 1
audit: backend
run_id: 2026-10-02_09-15-40
agent: claude_code
model: claude-opus-5
prompt_hash: 3f9a1c02   # hash of the task prompt hashes
generated_at: 2026-10-02T09:21:52+02:00
---

# Backend Audit — 2026-10-02_09-15-40
```

## 2. Body structure

Sections in this order. Level-2 headings must match literally (case-insensitive).

```markdown
## Summary
3–5 sentences.

## Findings
### BE-001 — …
### BE-002 — …

## <audit-specific sections>
(free markdown, defined per audit prompt)

## Positive observations
Max 5 bullets.

## Limitations
What could not be checked and why.

### Out of scope observations
Optional: one sentence per observation that belongs to another audit.
```

- If there are no findings, `## Findings` contains the single line `No findings.`
- `## Limitations` is always the last level-2 section.

## 3. Finding

A finding consists of four parts, in this order: heading, metadata table, body, separator.

### 3.1 Heading

```
### {ID} — {Title}
```

- ID: `TST-`, `FE-`, `BE-`, `DEP-` followed by three digits. Prefix must match the audit. IDs are unique and numbered in order of appearance.
- Separator between ID and title: ` — ` (em dash). The normalizer also accepts ` – `, ` - ` and `:` and fixes unpadded IDs (`BE-1` → `BE-001`).
- Title: max 120 characters, no markdown formatting.

### 3.2 Metadata table

Directly below the heading. Two columns, header row `| Field | Value |`. Row order is free, field names are case-insensitive.

| Field | Required | Allowed values |
|---|---|---|
| Severity | yes | `critical`, `high`, `medium`, `low`, `info` |
| Confidence | yes | `confirmed`, `likely`, `suspected` |
| Category | yes | `security`, `correctness`, `performance`, `maintainability`, `testing`, `ux`, `accessibility`, `consistency`, `dead-code`, `dependency`, `configuration` |
| Check | yes | one slug from the check list of **this** audit (every check belongs to exactly one audit) |
| Effort | yes | `S` (< 1 h), `M` (< 1 day), `L` (> 1 day) |
| Locations | yes | One or more locations, comma-separated, each in backticks (see 3.3) |
| Occurrences | no | Integer ≥ 1, default `1` |
| References | no | Comma-separated, e.g. `CWE-89, OWASP A03:2021` |
| Related | no | Comma-separated finding IDs from **the same report** |

The check lists live in the audit classes (`src/Audits`) and are rendered into the prompt.

### 3.3 Location syntax

| Form | Example |
|---|---|
| File | `` `app/Models/User.php` `` |
| File + line | `` `app/Models/User.php:42` `` |
| File + range | `` `app/Models/User.php:42-48` `` |
| Route | `` `route:POST /orders/{order}` `` |
| Package | `` `package:guzzlehttp/guzzle` `` |

File paths are relative to the project root. If a finding has more locations than fit comfortably, list the most important ones and set `Occurrences` to the total.

### 3.4 Body

Three bold labels, each on its own line, in this order. All three are required.

- `**Evidence**` — code block (max ~10 lines) or a reference to a preflight file.
- `**Impact**` — what can go wrong, for whom.
- `**Recommendation**` — concrete fix; code blocks allowed.

The body ends at the next `### ` heading, the next `## ` heading, or a `---` line.

### 3.5 Parser scope

- Findings are only parsed inside `## Findings`. Other sections may use `###` headings and tables freely.
- The parser is fence-aware: `###`, `---` or `|` lines inside code blocks are never treated as structure.

## 4. Complete example

````markdown
### BE-003 — Unfiltered request data passed to OrderService::update()

| Field | Value |
|---|---|
| Severity | high |
| Confidence | confirmed |
| Category | security |
| Check | mass-assignment |
| Effort | S |
| Locations | `app/Services/OrderService.php:42-48`, `app/Http/Controllers/OrderController.php:57` |
| Occurrences | 2 |
| References | CWE-915, OWASP A08:2021 |

**Evidence**
```php
public function update(Order $order, array $data): Order
{
    $order->fill($data)->save();
```
`OrderController::update()` passes `$request->all()`; `Order` uses `$guarded = []`.

**Impact**
Any authenticated customer can set `status`, `total` or `user_id` on their own orders, e.g. marking unpaid orders as paid.

**Recommendation**
Pass `$request->validated()` from a Form Request and replace `$guarded = []` with an explicit `$fillable` list.

---
````

## 5. Normalization

Before validation, the harness fixes trivial slips without asking the agent again: heading separators and ID padding, enum casing (`High` → `high`, `small` → `S`), decorated values (`**high**`, `` `high` ``) and body labels (`**Evidence:**` → `**Evidence**`).

## 6. Validation (`ReportLinter`)

**Errors** (trigger one repair pass in the same agent session, then the task is marked as failed and the audit as partial):

- Required level-2 sections missing, or `## Limitations` is not the last one
- Finding heading does not match the ID pattern, wrong prefix, duplicate ID
- Metadata table missing or a required field missing
- Enum value not allowed, or the check is not in the task's check list
- No parseable location, invalid `Occurrences`
- `Evidence`, `Impact` or `Recommendation` missing or empty
- More findings than `max_findings_per_task` (default 8, raised when a task has more required findings)
- A required finding is missing: facts from the preflight the report must cover (e.g. every security advisory, failing tests, baselined PHPStan errors)

**Warnings** (listed in `run.md`):

- Location file does not exist in the project (likely hallucinated path)
- Line number exceeds the file length
- `Related` references an ID that does not exist in the report
- Check `other`
- Title longer than 120 characters, summary longer than 5 sentences
- No findings and no `No findings.` line

After all audits are done, the harness also lists **possible duplicates**: findings from different audits, or from different tasks of one audit, whose locations share overlapping lines of the same file, the same route or the same package.

## 7. Parsed representation

Internally the parser produces immutable DTOs (`src/Reports/Finding.php`, `src/Reports/Location.php`). Each finding gets a stable key for comparisons across runs:

```
key = substr(sha1(audit | check | sort(unique(location targets))), 0, 8)
location target = file path without lines · route:METHOD /uri · package:vendor/name
```

## 8. Versioning

`format` in the frontmatter is the format version. Breaking changes to this spec increase it; the parser supports the current and the previous version.

## 9. Task reports

A task report is what one task agent returns. It follows the audit report rules above, with these differences:

```markdown
## Findings
### BE-001 — …        (numbered from 001 in every task; the harness renumbers)

## <sections this task owns>
(only the audit-specific sections assigned to the task, e.g. `## Dead code candidates`)

## Positive observations
Optional, max 2 bullets.

## Limitations
What could not be checked and why.

### Out of scope observations
Optional.
```

- No `## Summary`; `## Positive observations` is optional.
- Every finding uses one of the task's own checks or `other`.
- `Related` may only reference IDs of the same task report; the harness remaps them.
- Required findings are checked against the task that owns their check.

The summary task returns only `## Summary` (3–5 sentences) plus the sections it owns (e.g. `## Top 10 missing tests`). It gets the merged findings list and writes no findings.
