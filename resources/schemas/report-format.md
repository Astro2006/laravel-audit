# Report Format (v1)

Every audit agent produces exactly **one** report and returns it as its final answer. The harness saves it as `{{output_dir}}/{audit_key}.md`.
The report is written for humans, but follows a strict structure so the harness (`ReportParser`) can extract every finding reliably for scores, validation, trends and later SARIF/CI export.

Rule of thumb: **everything the harness needs lives in the frontmatter, the finding headings and the finding metadata tables.** Everything else is free markdown.

---

## 1. File structure

Sections in this exact order. Level-2 headings must match literally.

```markdown
---
format: 1
audit: backend
run_id: 2026-09-11_14-30-05
agent: claude-opus-5
generated_at: 2026-09-11T14:42:10+02:00
---

# Backend Audit — 2026-09-11_14-30-05

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
```

If there are no findings, `## Findings` still exists and contains the single line `No findings.`

## 2. Frontmatter

Plain `key: value` lines between `---` markers. No nesting, no quotes needed.

| Key | Required | Value |
|---|---|---|
| `format` | yes | `1` |
| `audit` | yes | `tests`, `frontend`, `backend`, `dependencies` |
| `run_id` | yes | Run ID from the prompt |
| `agent` | no | Agent / model name |
| `generated_at` | yes | ISO 8601 timestamp |

## 3. Finding

A finding consists of four parts, in this order: heading, metadata table, body, separator.

### 3.1 Heading

```
### {ID} — {Title}
```

- ID: `TST-`, `FE-`, `BE-`, `DEP-` followed by three digits. Prefix must match the audit. IDs are unique and numbered in order of appearance.
- Separator between ID and title: ` — ` (em dash). The parser also accepts ` – ` and ` - `.
- Title: max 120 characters, no markdown formatting.

### 3.2 Metadata table

Directly below the heading. Two columns, header row `| Field | Value |`. Row order is free, field names are case-insensitive, enum values are lowercase.

| Field | Required | Allowed values |
|---|---|---|
| Severity | yes | `critical`, `high`, `medium`, `low`, `info` |
| Confidence | yes | `confirmed`, `likely`, `suspected` |
| Category | yes | `security`, `correctness`, `performance`, `maintainability`, `testing`, `ux`, `accessibility`, `consistency`, `dead-code`, `dependency`, `configuration` |
| Effort | yes | `S` (< 1 h), `M` (< 1 day), `L` (> 1 day) |
| Locations | yes | One or more locations, comma-separated, each in backticks (see 3.3) |
| Occurrences | no | Integer ≥ 1, default `1` |
| References | no | Comma-separated, e.g. `CWE-89, OWASP A03:2021` |
| Related | no | Comma-separated finding IDs from any audit, e.g. `FE-004, TST-007` |

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

- Findings are only parsed inside `## Findings`. Audit-specific sections may use `###` headings and tables freely.
- The parser is fence-aware: `###`, `---` or `|` lines inside code blocks are never treated as structure.

## 4. Complete example

````markdown
### BE-003 — Unfiltered request data passed to OrderService::update()

| Field | Value |
|---|---|
| Severity | high |
| Confidence | confirmed |
| Category | security |
| Effort | S |
| Locations | `app/Services/OrderService.php:42-48`, `app/Http/Controllers/OrderController.php:57` |
| Occurrences | 2 |
| References | CWE-915, OWASP A08:2021 |
| Related | FE-006 |

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

## 5. Validation (`ReportLinter`)

The harness checks every report after the agent finishes.

**Errors** (trigger one repair pass, then the audit is marked as failed):

- Frontmatter missing, or `audit` / `run_id` do not match the run
- Required level-2 sections missing
- Finding heading does not match the ID pattern, wrong prefix, duplicate ID
- Metadata table missing or a required field missing
- Enum value not allowed
- No parseable location
- `Evidence`, `Impact` or `Recommendation` missing

**Warnings** (logged and listed under the report's limitations by the harness):

- Location file does not exist in the project (likely hallucinated path)
- Line number exceeds the file length
- `Related` references an ID that does not exist in any report of the run
- Findings not sorted by severity
- Summary longer than 5 sentences

The repair pass sends the agent its own report plus the list of errors and asks for a corrected file. Nothing else is re-audited.

## 6. Parsed representation

Internally the parser produces immutable DTOs. This is what scores, trends and exports work with.

```php
final readonly class Finding
{
    /**
     * @param list<Location> $locations
     * @param list<string>   $references
     * @param list<string>   $related
     */
    public function __construct(
        public string $id,
        public string $title,
        public Severity $severity,
        public Confidence $confidence,
        public Category $category,
        public Effort $effort,
        public array $locations,
        public int $occurrences,
        public array $references,
        public array $related,
        public string $evidence,
        public string $impact,
        public string $recommendation,
    ) {}
}

final readonly class Location
{
    public function __construct(
        public ?string $file = null,
        public ?int $line = null,
        public ?int $endLine = null,
        public ?string $route = null,
        public ?string $package = null,
    ) {}
}
```

## 7. Versioning

`format` in the frontmatter is the format version. Breaking changes to this spec increase it; the parser supports the current and the previous version.
