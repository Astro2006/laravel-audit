## Summary
One medium advisory in a transitive package and two abandoned packages.

## Findings

### DEP-001 — guzzlehttp/psr7 2.4.1 has a header validation advisory

| Field | Value |
|---|---|
| Severity | medium |
| Confidence | confirmed |
| Category | dependency |
| Check | vulnerable-dependency |
| Effort | S |
| Locations | `package:guzzlehttp/psr7` |
| References | CVE-2023-29197 |

**Evidence**
`composer/dependency-facts.json` lists PKSA-1234 for 2.4.1, pulled in by laravel/framework.

**Impact**
Header injection in outgoing requests.

**Recommendation**
`composer update guzzlehttp/psr7`.

---

### DEP-002 — acme/unused-lib is abandoned and unused

| Field | Value |
|---|---|
| Severity | low |
| Confidence | confirmed |
| Category | dependency |
| Check | abandoned-package |
| Effort | S |
| Locations | `package:acme/unused-lib` |

**Evidence**
Abandoned in favour of acme/new-lib; zero references in the usage map.

**Impact**
No security fixes.

**Recommendation**
Remove it.

---

### DEP-003 — acme/test-only is abandoned

| Field | Value |
|---|---|
| Severity | low |
| Confidence | confirmed |
| Category | dependency |
| Check | abandoned-package |
| Effort | M |
| Locations | `package:acme/test-only` |

**Evidence**
Marked abandoned in composer outdated.

**Impact**
No fixes.

**Recommendation**
Replace it.

---

## Dependency inventory
| Package | prod/dev | Verdict |
|---|---|---|
| acme/used-lib | prod | upgrade |

## Upgrade plan
1. Update guzzlehttp/psr7.

## Positive observations
- Lock file committed.

## Limitations
npm was not audited.
