# ROLES_AND_PERMISSIONS.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-13
**Status labels:** existing claims are proposal/capstone claims, **not** verified in this repo. This repo has exactly one implicit role: any authenticated user.

## 1. Roles (proposal §6.1, Table 3)

| # | Role | Status in proposal | Typical user | Purpose |
| --- | --- | --- | --- | --- |
| 1 | **Admin** | Existing (capstone) | ICT / system administrator | Manage users, settings, data corrections, system health, audit logs |
| 2 | **Energy Manager** | Proposed | Engineering / facilities lead | Verify data, review forecasts, accept/reject/defer recommendations, set targets |
| 3 | **Facilities Personnel** | Proposed | Building staff | Encode/upload monthly data for assigned buildings |
| 4 | **Budget Officer** | Proposed | Budget office | Use forecast + cost estimates for budget planning, download reports |
| 5 | **Viewer / Executive** | Existing (capstone) | City leadership, department heads | View dashboards, forecasts, recommendations, reports |

**Implementation status in this repo:** none of the five roles exists (`app/Models/User.php` has no role column; `database/migrations/` has no role migration). Adding them is Phase P2 in `docs/IMPLEMENTATION_PLAN.md`.

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

1. **Role storage:** add `role` to `users` (string enum backed by `App\Enums\UserRole`) — reversible migration. Building assignments go in a pivot (`building_user`) for building-level restriction.
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
