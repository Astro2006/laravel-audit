Here is the report you asked for:

```markdown
---
format: 1
audit: backend
---

# Backend Audit

## Summary
Short summary.

## Findings

### BE-1 - Mass assignment in OrderService

| Field | Value |
|---|---|
| severity | **High** |
| Confidence | `Confirmed` |
| Category | Security |
| Check | Mass-Assignment |
| Effort | small |
| Locations | `app/Services/OrderService.php:9` |

**Evidence:** `$order->fill($data)->save();`

**Impact:**
Anyone can change the order status.

**Recommendation**
Use validated data.

---

## Conventions baseline
Plain Laravel.

## Convention scorecard
None.

## Dead code candidates
None.

## Positive observations
- Tidy.

## Limitations
None.
```
