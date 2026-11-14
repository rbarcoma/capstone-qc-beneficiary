# ROLES_AND_PERMISSIONS.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-14
**Current implementation (2026-11-14):** five roles + building assignments (P2), Admin-only registry management (P3), and P4 authorized CSV/manual intake, independent verification/correction/history are **Existing and verified**. Proposal status labels below describe their source, not implementation evidence for future modules.

## 1. Roles (proposal §6.1, Table 3)

| # | Role | Status in proposal | Typical user | Purpose |
| --- | --- | --- | --- | --- |
| 1 | **Admin** | Existing (capstone) | ICT / system administrator | Manage users, settings, data corrections, system health, audit logs |
| 2 | **Energy Manager** | Proposed | Engineering / facilities lead | Verify data, review forecasts, accept/reject/defer recommendations, set targets |
| 3 | **Facilities Personnel** | Proposed | Building staff | Encode/upload monthly data for assigned buildings |
| 4 | **Budget Officer** | Proposed | Budget office | Use forecast + cost estimates for budget planning, download reports |
| 5 | **Viewer / Executive** | Existing (capstone) | City leadership, department heads | View dashboards, forecasts, recommendations, reports |

**Implementation status in this repo:** **Implemented in P2 (2026-11-14).** `users.role` column (default `viewer`) + `App\Enums\UserRole`; `role:` middleware (`App\Http\Middleware\EnsureUserHasRole`, registered in `bootstrap/app.php`) for route groups; `buildings` + `user_building` pivot with `User::hasBuildingAccess()` / `allowedBuildingIds()` (building-level restriction; only Facilities Personnel is building-restricted per §2). Role + `roleLabel` + `allowedBuildingIds` shared to the frontend via `HandleInertiaRequests` — presentation only, enforcement stays server-side. Entity-level policies land with their entities (P3+). Permission-denied audit logging lands with `audit_logs` (P4+).

## 2. Permission matrix (proposed, from proposal §6.2 Table 4 — to be confirmed with City Hall offices)

| Capability | Admin | Energy Manager | Facilities Personnel | Budget Officer | Viewer |
| --- | :-: | :-: | :-: | :-: | :-: |
| Manage users and settings | Yes | – | – | – | – |
| Upload / encode monthly data | Yes | Yes | Own buildings | – | – |
| Verify uploaded data | Yes | Yes | – | – | – |
| Run or refresh forecast | Yes | Yes | – | – | – |
| View analytics | Yes | Yes | Own buildings | Yes | Yes |
| View forecasts & recommendations | Yes | Yes | Own buildings | Yes | Yes |
| Accept / reject recommendations | – | Yes | – | – | – |
| Set targets, tariffs, thresholds | Yes | Yes | – | – | – |
| View cost & budget estimates | Yes | Yes | – | Yes | Yes |
| Download reports | Yes | Yes | Own buildings | Yes | Yes |
| View audit logs | Yes | – | – | – | – |

Notes:
- "Own buildings" = building-level access restriction. Enforcement must be **server-side** (Eloquent global scopes / policies / middleware), never just hidden UI.
- This matrix is **Proposed** and requires City Hall confirmation before locking in.

## 3. Enforcement plan (when P2 is implemented)

**P5–P7 checkpoint:** preprocessing/build/weather sync + operational dataset lineage and forecast issuance/reconciliation = Admin/Energy Manager, server role/policy gates; analytics/comparison/forecast/scenarios = authenticated roles with Facilities building scope and cost suppression. Decision Support = all authenticated view roles, Facilities assigned-building non-cost recommendations only (R1/cost-marked rows excluded). Accept/reject/defer = **Energy Manager only**, including persisted-role recheck; Admin cannot decide. Matching evaluated policy/current source/version required; default production policy unavailable. Acceptance requires owner with recommendation/building visibility and an explicit due date. Decision history/action creation/audit are atomic; scoped route-role denials audited. Standalone action lifecycle editing remains P8.

**P4 enforcement verified:** intake/template/data page = Admin/Energy Manager/Facilities (assigned buildings only). Queue/verify/correct/history = Admin/Energy Manager. `MonthlyRecordPolicy` additionally denies verification by the current author (`last_edited_by`, fallback uploader), including after corrections. Bill confirmation + version required; corrections require reason and re-verification. Operational correction history is available to these reviewers; global audit logs UI remains Admin-only/P8. Scoped P4 route/policy denials are audited without raw request/account contents.

1. **Role storage:** `role` on `users` (string enum backed by `App\Enums\UserRole`) — reversible migration. Building assignments use the implemented `user_building` pivot.
2. **Authorization layer:**
   - Middleware for route groups (e.g., `role:admin`), plus **Laravel Policies** for entity-level checks (verify record, accept recommendation, manage target).
   - Building-level: policy checks `user->buildings()->contains($record->building_id)`.
3. **Frontend:** pass role + allowed buildings via `HandleInertiaRequests` shared props; hide/disable unauthorized controls, but never treat that as security.
4. **Account references:** Meralco account numbers visible only to authorized roles (Admin, Energy Manager) — proposal §9.1: "Restrict viewing of account numbers to authorized roles".
5. **Audit:** every permission-denied action of interest is logged (proposal §12.2 extends audit to corrections and decisions).

## 4. Data-privacy constraints (proposal §12)

- User accounts contain personal data → **Data Privacy Act of 2012 (RA 10173)**; collect only what is needed; DPO review required before go-live (**Blocked / requires approval**).
- Meralco billing data + account references = **confidential institutional data**: role-restricted, never on public hosting, requires City Hall authorization (proposal §1.3, Decision 1).
- Forecasts/recommendations/reports = **internal**, role-based access.
- Weather data = **public**.

## 5. Open items owned by this doc

| # | Item | Owner | Status |
| --- | --- | --- | --- |
| 1 | Confirm permission matrix with City Hall offices | User / City Hall | Blocked-requires approval |
| 2 | Decide 2FA/SSO adoption (proposal §8.1 optional) | User | Future enhancement |
| 3 | Who encodes, who verifies, time limits (proposal Table 20 #7) | Energy Manager | Open |

## 6. Cross-references

- Module access per screen → `docs/MODULES_AND_FEATURES.md`
- Migration plan for roles → `docs/IMPLEMENTATION_PLAN.md` (P2)
- Audit logging schema → `docs/DATABASE_DESIGN.md`
