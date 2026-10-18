## Summary
The backend follows the project's action pattern. One mass assignment issue stands out.

## Findings

### BE-001 — Unfiltered request data passed to OrderService::update()

| Field | Value |
|---|---|
| Severity | high |
| Confidence | confirmed |
| Category | security |
| Check | mass-assignment |
| Effort | S |
| Locations | `app/Services/OrderService.php:7-12`, `route:PUT /orders/{order}` |
| Occurrences | 2 |
| References | CWE-915, OWASP A08:2021 |
| Related | BE-002 |

**Evidence**
```php
$order->fill($data)->save();
return $order;
---
### not a heading, this is inside a fence
| Severity | critical |
```

**Impact**
Customers can set `status` on their own orders.

**Recommendation**
Pass `$request->validated()` from a Form Request.

---

### BE-002 — Dead job SyncLegacyInventory

| Field | Value |
|---|---|
| Severity | low |
| Confidence | likely |
| Category | dead-code |
| Check | dead-code |
| Effort | S |
| Locations | `app/Services/OrderService.php` |

**Evidence**
No dispatch found.

**Impact**
Maintenance cost.

**Recommendation**
Delete the job.

---

## Conventions baseline
Actions in `app/Actions`.

## Convention scorecard
| Convention | Status | Evidence |
|---|---|---|
| Form Requests | ⚠️ | inline validation |

## Dead code candidates
- `SyncLegacyInventory`

## Positive observations
- Strict types everywhere.

## Limitations
Queues were not inspected.

### Außerhalb des Scopes beobachtet
- A route without auth (frontend).
