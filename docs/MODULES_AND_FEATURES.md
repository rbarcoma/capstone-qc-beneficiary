# MODULES_AND_FEATURES.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-14

**Status vocabulary (strict):**
- **Existing and verified** — present and tested in this repo.
- **Existing in capstone, not ported** — claimed by proposal/original capstone; not in this repo; port deliberately and verify.
- **Proposed** — recommended by the technical proposal; needs implementation + City Hall context.
- **Future enhancement** — later phases only (proposal §5.2).
- **Blocked / requires approval** — needs explicit user instruction or City Hall decision.
- **Design-only** — Figma design exists; backend does not.

**Historical starter baseline (2026-11-13):** auth/settings, welcome, placeholder dashboard, shadcn shell. **Current verified scope (2026-11-14):** P1–P4 foundations + P5 source-tracked weather, versioned preprocessing/analytics/comparison, and P6 read-only worker/JSON bridge. Operational forecasting/issuance/evaluation UI and DSS remain unfinished as labeled below.

---

## Core & operational modules

### M1. Dashboard
- **Status:** **Existing and verified** — P1 dashboard + P3–P5 authorized counts and complete-building verified coverage/latest period; trends use current processed datasets only. Stale/partial data suppressed; Facilities assigned-building filtering enforced. Forecast eligibility remains separate/unassessed by the operational UI until P6 issuance is implemented.
- **Users:** All roles (role-specific variants pending inspection).
- **Purpose:** Landing view per workspace; building + period selection; status at a glance.
- **Figma:** Admin frame `2024:3` verified (see `docs/FIGMA_DESIGN_GUIDE.md` §2.1).
- **Verified design content:** 4 KPI cards, monthly electricity trend, monthly operating routine, pilot-building readiness table, notices, Upload Monthly Bill + Load Synthetic Demo buttons.
- **Backend needs:** shared props (buildings, latest verified period, KPIs from verified data only), readiness computation.
- **Permissions:** all roles view; content filtered by building access.
- **Acceptance criteria:** renders empty states from real DB (0 verified months etc.); never hardcodes mockup numbers; matches frame layout/tokens; role variant correct.
- **Depends on:** P1 (layout/branding), P3–P5 (data).

### M2. Buildings & Meters (registry)
- **Status:** **Existing and verified** in P3 (2026-11-14 completion audit) — registry + building/meter create/update/soft-remove; Admin CRUD controls extend shadcn conventions because Figma has no edit/remove/form designs.
- **Users:** Admin (manage), others (view per permissions: Energy Manager full view, Facilities building-filtered, Budget/Viewer no access).
- **Requirements:** complex → buildings → meters registry; attributes per proposal Table 9 (building: name, code, floor area m², operating hours, notes; meter: Meralco account reference [restricted], tariff class, status).
- **Backend:** `buildings-meters` GET (`role:admin,energy_manager,facilities_personnel`); building/meter POST, PATCH, DELETE (`role:admin` + `BuildingPolicy@manage`). All meter refs masked (short refs completely masked); optional replacement on edit, blank retains current value. Meter's building stays fixed on edit. Removal soft-deletes, preserving rows/assignments and reserved identifiers; non-removed meters block building removal. Seeder respects removed pilots. Facilities data filtered server-side; management controls use server-derived `canManage`.
- **Acceptance criteria:** reversible migrations; pilot buildings seedable; CSV codes stable. **Verified:** P3 registry CRUD/filter and Facilities read-only checks; P4 real distinct verified-period counts/latest period and rejection of removing meters with monthly history (including concurrent-intake row lock). No bill history seeded/imported into development.
- **Depends on:** P2 (roles), feeds everything.

### M3. Data Management (upload/encode)
- **Status:** **Existing and verified (P4 complete, 2026-11-14)** — transactional CSV/manual Draft intake, validation, upload audit events, independent verification/correction actions and a separately labeled browser-only synthetic preview. Preprocessing is P5.
- **Users:** Facilities Personnel (own buildings), Energy Manager, Admin.
- **Requirements:** proposal Appendix A CSV template: `building_code, month (YYYY-MM-01), kwh, billed_demand_kw, cost_php, power_factor, notes`; one row per building+month; validation errors surfaced per row; duplicate/missing month detection.
- **Figma:** Admin Data Management `2024:316` inspected and implemented; production adaptations recorded in design guide/handoff.
- **Acceptance criteria (verified):** invalid/duplicate batches rejected atomically with row messages; completed-month gate uses Asia/Manila; gap warnings never fill values; ambiguous multi-meter CSV mapping rejected, manual entry selects meter explicitly; records stored **draft**. Demo preview has separate shape/state, no API writes/persistence/record IDs, and no effect on official totals or analytics.
- **Depends on:** M2; P4.

### M4. Verification Queue
- **Status:** **Existing and verified (P4, 2026-11-14)** — frame `2024:481` inspected/implemented; Admin/Energy Manager verify a different person's latest values after explicit bill confirmation. Corrections require a reason, retain previous state in revisions/audit, clear verification and return Draft. Version checks reject stale actions; billing-month corrections reject duplicate periods; meter assignment fixed. Operational revision history paginated for Admin/Energy Manager. Overdue timing remains Not assessed pending policy.
- **Users:** Energy Manager, Admin.
- **Backend:** draft→verified state change, audited; corrections post-verification create recorded actions and keep previous value in audit history.
- **Acceptance criteria:** only verified months feed analytics/forecasts; verification is auditable; unauthorized users cannot verify (server-enforced).
- **Depends on:** M3, P2.

### M5. Preprocessing
- **Status:** **Existing and verified P5** — versioned complete-building preprocessing from verified records, retained source/weather lineage and exact calendar past-only predictors; current fingerprints invalidate superseded analytics. NASA POWER approved/implemented; raw daily aggregation contract documented.
- **Requirements:** clean verified months, join weather (source recorded: NASA POWER or PAGASA — proposal §9.3), compute derived features (e.g., load factor when billed demand exists); processed datasets versioned for reproducibility (capstone has dataset/forecast versioning migrations).
- **Acceptance criteria:** derived from verified data only; version lineage queryable.
- **Depends on:** M4; NASA POWER source/units/missing-day contract verified (weather gaps remain null).

### M6. Energy Analytics
- **Status:** **Existing and verified P5** — current verified-dataset trends, exact MoM/YoY, actual cost/intensity and single-meter calendar load factor. Facilities cost fields suppressed server-side. Actual-vs-predicted/backtests remain P6, not fabricated.
- **Users:** all roles (building-filtered for Facilities).
- **Acceptance criteria:** only verified data; comparisons show context (similar-use caveat); cost figures only where bills include them.
- **Depends on:** M5; pilot analytics feasible with 17-month Highrise history **if** data is authorized and ported (blocked).

### M7. Building Comparison
- **Status:** **Existing and verified P5** — common complete period from current authorized datasets; missing/stale portfolio sums and unknown cost/intensity ranks suppressed; functions/schedules/context caveat retained.
- **Requirements:** rank by kWh, cost, kWh/m²; show context beside rankings.
- **Depends on:** M6, floor-area data (open item, proposal Table 20 #6).

### M8. Forecasting
- **Status:** **Existing and verified P6**: Linear primary + three-month baseline, computed walk-forward evidence, immutable versioned issuance/storage, locked freshness/runtime/actor rechecks, idempotency, realized-error reconciliation/retraction and scoped UI. Optional model-family choices remain tracked; no Ridge/tree promotion silently implemented.
- **Users:** Energy Manager (run), Budget Officer/Viewer (view), Facilities (own buildings view).
- **Hard constraints:** **next eligible month only**; runs after the month is verified; Linear Regression primary (see `docs/ML_AND_DSS_RULES.md` §4); confidence label reflects history sufficiency.
- **Acceptance criteria:** forecast stored with model version + metrics; ineligible buildings show data-sufficiency notice, not a number.
- **Depends on:** M5, Python integration (P6).

### M9. Model Evaluation
- **Status:** **Existing and verified P6** (computed same-period walk-forward MAE/RMSE/R² and actual-vs-predicted evidence, current/historical/superseded states); challenger-vs-current promotion = Proposed (proposal §10.2).
- **Acceptance criteria:** metrics computed on walk-forward test months; **no fabricated metrics**; error history kept per month.
- **Depends on:** M8.

### M10. Decision Support (DSS)
- **Status:** **Existing and verified P7 independent workflow**: scoped evidence/history UI, Energy Manager-only audited accept/reject/defer, final-state/version guards, explicit owner/due-date action creation. **Blocked / requires clarification:** C5 R1–R7 mapping/numeric trigger/evidence/priority contract; no rule generator. Default-null policy prevents production decisions, including on stored evaluated rows. Configurable thresholds/targets remain Proposed.
- **Users:** Energy Manager (act), Budget Officer/Viewer (view).
- **Requirements:** see `docs/ML_AND_DSS_RULES.md` §5; every recommendation carries its evidence numbers; Energy Manager accepts/rejects/defers; accepted items become actions with owner + due date (proposal §10.3).
- **Depends on:** M6, M8; P7.

### M11. Solar & Scenarios
- **Status:** **Existing and verified P7**: source-checked explicit-percentage arithmetic and paired explicit kWp/performance-ratio solar screening, historical proxy/target-day formulas, assumptions audit, independent scenarios, unchanged issued forecast, null cost without tariff.
- **Hard constraint:** screening-level only (irradiance, assumed capacity, performance ratio) — **never** an engineering feasibility conclusion; what-if is arithmetic on the forecast, not measure prediction.
- **Depends on:** current M8 forecast; M10 rule generation is independently blocked.

### M12. Budget Planning
- **Status:** Proposed (budget view: forecast kWh × tariff → estimated cost + range) — proposal §10.5.
- **Users:** Budget Officer, Energy Manager, Admin, Viewer (view).
- **Open item:** tariff source + budget cycle (proposal Table 20 #5). **Blocked on that decision** for real cost outputs.
- **Depends on:** M8.

### M13. Action Tracker
- **Status:** P7 action creation with explicit authorized owner/due date and Decision Support summary is **Existing and verified**. Standalone tracker/status/outcome editing remains **Proposed (P8)**; no automatic procurement decision.
- **Depends on:** M10, alerts.

### M14. Reports
- **Status:** Existing in capstone but **unverified** — proposal Table 20 #9 explicitly requires verifying PDF/Excel export in the working system; executive summary PDF = Proposed.
- **Users:** Budget Officer, Viewer, Admin, Energy Manager.
- **Acceptance criteria:** reports marked internal; downloads audited.
- **Depends on:** M6/M8/M10.

### M15. Notifications / Alerts
- **Status:** Proposed (proposal §10.4 Table 12): new data awaiting verification (→ Energy Manager), forecast ready (→ EM, Budget), high-priority recommendation, month overdue (→ Facilities, EM), forecast error above tolerance 3 months (→ EM, Admin). Channels: in-app + email (SMTP proposed).
- **Depends on:** queues (P8+), M3/M8/M10.

### M16. Users & Access
- **Status:** **Reference page implemented in P2 (2026-11-14)** — Admin page matching Figma frame `2024:2179` (role status cards Existing/Proposed + role-based permission matrix). The five roles + building permissions are now **implemented server-side** (role column, `App\Enums\UserRole`, `role:` middleware, `user_building` pivot); the wider permission matrix in `docs/ROLES_AND_PERMISSIONS.md` §2 remains **Proposed** pending City Hall confirmation. User CRUD / role-assignment UI is **not in the frame** and not implemented.
- **Depends on:** P2 (roles landed).

### M17. Audit Logs
- **Status:** **Existing and verified P4 event-storage foundation** — uploads/verifications/corrections with actor + before/after, and scoped P4 permission denials. Operational revision history is available to authorized reviewers. Global Admin-only audit viewer and future-module events remain Proposed (P8+).
- **Acceptance criteria:** covers uploads, verifications, corrections, forecast runs, decisions, downloads, permission denials of interest; viewable by Admin only.
- **Depends on:** P4 onward.

### M18. Settings & System Health
- **Status:** Settings = existing starter-kit user settings (verified) + proposed system settings (thresholds, tariffs, targets); System Health = Proposed (proposal §6.1 Admin purpose).
- **Depends on:** P8.

---

## Not modules but required features

| Feature | Status | Source |
| --- | --- | --- |
| Weather data ingestion | Proposed; source (NASA POWER / PAGASA) recorded per value | Proposal §9.3 |
| Monthly operating routine | Proposed workflow (§11.1, 8 steps) | Proposal §11 |
| Scheduled monthly forecast job | Proposed (scheduler + Redis queues) | Proposal §8.1 |
| HTTPS, backups, monitoring, least-privilege DB user | Proposed (production) | Proposal §12.2 |

## Cross-references

- Permissions per capability → `docs/ROLES_AND_PERMISSIONS.md` §2
- Schema per module → `docs/DATABASE_DESIGN.md`
- Build order → `docs/IMPLEMENTATION_PLAN.md`
