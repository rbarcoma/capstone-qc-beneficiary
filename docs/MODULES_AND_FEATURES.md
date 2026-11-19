# MODULES_AND_FEATURES.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-18

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
- **Monthly review UI (Existing and verified, 2026-11-18):** building and period, readiness/forecast comparison/cost availability, explainable recommendations, then follow-up links. R3/R4, R5 and historical R1 evidence are read-only detail views. Billing Evidence and Configuration Review retain their separate access rules.
- **R1 bounded phase (2026-11-18, Existing and verified):** additive customer-account/service/evidence foundation and read-only historical gross/net-service PHP/import-kWh. Requires independently verified, reconciled evidence matching the exact verified monthly-record version and all required meters. Excludes previous balances/separate deposits/account refunds; export credit applied once; embedded service adjustments retained. Source corrections/new drafts withdraw earlier rates; decimal-string weighted sums, gaps/zero/missing coverage explicit. Existing cost RBAC and null actionable policy retained. No real import/mapping inference/future tariff or budget warning. Public entry/review interfaces remain separately scoped; ML/DSS §5.8.
- **Existing and verified:** scoped evidence/history UI, Energy Manager-only audited accept/reject/defer, final-state/version guards, explicit owner/due-date action creation. R2 compares the current issued forecast to the latest complete verified month (undefined percentage for zero reference); R7 reports coverage/eligibility even without a forecast. Neither informational view creates recommendations. Admin/EM can now explicitly evaluate pinned, approved R1–R5 conditional rules for one building and forecast (ML/DSS §5.11). No activation is deployed. Operational triggers, priorities, R1 tariff/budget terms and reminder policies remain **Blocked / requires approval**.
- **Users:** Energy Manager (act), Budget Officer/Viewer (view).
 - **R3/R4 bounded continuation (2026-11-17, Existing and verified):** latest recorded completed actual versus exact prior3 calendar-month mean, R3 complete same-building kWh and R4 independent same-meter billed kW. UI explicitly says **Unapproved exploratory method**; shows actual/mean/signed and absolute differences/% and verified source evidence/availability. No dataset/forecast prerequisite for these actual-only descriptions; gaps/drafts/partial totals/missing demand/zero bases handled without substitutions. No anomaly/demand classification, priority, generated actionable advice or policy activation. Operational baseline validation, triggers and related approvals remain pending; contract ML/DSS §5.6. R2/R7, R6 and existing DSS workflow retained.
  - **R5 review-only prototype (2026-11-17, Existing and verified):** `ConservationCatalogue` displays the proposed R5-01–R5-07 suggestions (operating context, after-hours operation, HVAC schedules/controls/maintenance, billed-demand basis, overlapping equipment, explicit conservation what-if, follow-up/measurement plan) in fixed catalogue order with **Unapproved · review-only prototype** labels. Evidence availability reuses the verified R2/R3/R4 descriptive outputs only; nothing selects, ranks or scores entries by consumption, demand, direction or percentage. Reviewer-scope/operational/monetary requirements and the named-approval row are not_assessed on every entry, never inferred from roles, notes, scenarios or Action Tracker history; limitations and proposed owners are shown. Read-only inside the existing information transaction; no benefit estimate, priority, recommendation, decision, action or source write; policy fingerprint stays null. Official catalogue ownership, per-entry approval, eligibility conditions/targets, saving assumptions and activation remain **Blocked / requires approval** (ML/DSS §5.7).
  - **Configuration review + billing evidence UI (2026-11-18, Existing and verified):** the approved essential contracts are implemented. `dss-configuration` lists versioned tariff/rule/catalogue/R5-review records per building (kind+subject filters, paginated, server-computed actions) and supports draft, submit, Budget-only tariff-source confirmation, different-Admin/EM approve/reject and explicit revoke with state-version/content-hash/freshness/latest-version guards. Approval binds `approved_hash`, appends an immutable event + audit row with `runtime_activated: false`, and never changes the null policy. `billing-evidence` exposes evidence drafting, independent verification and an Admin-only explicit service association (Admin/EM via Verification module; Budget/Viewer/Facilities denied) with private/no-store responses. Per-subject decisions allow separate R4 meter and R5 catalogue-entry outcomes alongside building history. No public intake, bill import, automatic mapping or policy activation (ML/DSS §5.9; migration `000016`).
  - **Requirements:** see `docs/ML_AND_DSS_RULES.md` §5; every recommendation carries its evidence numbers; Energy Manager accepts/rejects/defers; accepted items become actions with owner + due date (proposal §10.3).
- **Depends on:** M6, M8; P7.

### M11. Solar & Scenarios
- **Status:** **Existing and verified P7**: source-checked explicit-percentage arithmetic and paired explicit kWp/performance-ratio solar screening, historical proxy/target-day formulas, assumptions audit, independent scenarios, unchanged issued forecast, null cost without tariff.
- **Hard constraint:** screening-level only (irradiance, assumed capacity, performance ratio) — **never** an engineering feasibility conclusion; what-if is arithmetic on the forecast, not measure prediction.
- **Depends on:** current M8 forecast; M10 rule generation is independently blocked.

### M12. Budget Planning
- **Status:** Proposed (budget view: forecast kWh × tariff → estimated cost + range) — proposal §10.5.
- **Historical prerequisite:** R1 historical effective-rate analytics now verified in M10, not activation of this module or approval of future prices. Historical bill ratios never automatically populate tariff settings.
- **Users:** Budget Officer, Energy Manager, Admin, Viewer (view).
- **Open item:** tariff source + budget cycle (proposal Table 20 #5). **Blocked on that decision** for real cost outputs.
- **Depends on:** M8.

### M13. Action Tracker
- **Status:** **Existing and verified P7 creation + P8 minimal tracker/lifecycle**: scoped building/status filters, real counts, source/owner/due date, human-reported outcomes, paginated history, EM-only Open→Open follow-up / Open→Completed, version/locks/acceptance-ledger guards and atomic history/audit. Completed history final; source/assignment/title fixed. Facilities inherit assigned-building/non-cost recommendation visibility; other roles (including owners/Admin) read-only. This conservative working convention follows proposal Table13/Table14 step8; delegated writes/reopen/reassignment/extra states/overdue policy remain unspecified extensions. No automatic savings/procurement conclusion.
- **Depends on:** retained accepted M10 action. Follow-through may continue after its forecast/policy becomes historical; it cannot create advice or bypass disabled C5. Alerts not required for manual tracking.

### M14. Reports
- **Status:** **Existing and verified P8 (2026-11-15)** — INTERNAL executive PDF and billing/analytics/model-evidence Excel, real render/read-back/browser checks; current authorized complete verified datasets/current issued next-eligible forecast only. Reviewed fingerprint/final locked source+actor+scope recheck and atomic download-preparation audit with byte hash/count. Historical open accepted actions labeled; C5/tariff outputs unavailable. No private account refs/notes; Facilities cost columns/advice excluded. Private/no-store, POST+CSRF+throttle; renderer/audit/stale failures release no successful export. Synchronous cap5000 billing/500 advice/500 actions, no partial truncation. No saved public report files.
- **Users:** Budget Officer, Viewer, Admin, Energy Manager; Facilities own buildings/non-cost exports (proposal Table4).
- **Acceptance criteria:** reports marked internal; downloads audited.
- **Depends on:** M6/M8/M10.

### M15. Notifications / Alerts
- **Status:** **Existing and verified P8 policy-independent in-app slice (2026-11-15)** — current draft/version notices for Energy Manager, current issued eligible forecast/run for Energy Manager/Budget Officer; current persisted roles, no implicit Admin recipient. Live inbox (not historical delivery ledger), personal idempotent read receipts, stable pagination/read filter/mark-one-or-reviewed-page, locked freshness and atomic audit. Verified/removed drafts and superseded forecasts disappear; corrected versions unread. No source values/notes/account refs exposed. Other roles receive no current notice types. **Proposed/unconfigured:** SMTP/queued delivery/scheduled reminders; high-priority-C5 alerts remain disabled, overdue/tolerance contracts absent. No bill verification/advice acceptance through read state.
- **Depends on:** M3/M8 current sources; receipt persistence migration `000012`. Email/scheduled channels retain their queue/SMTP dependencies; current in-app notices need no worker.

### M16. Users & Access
- **Status:** **Existing and verified (approved CRUD extension, 2026-11-17)** — Admin-only list/detail, temporary-password create/renewal, versioned edits, explicit Facilities assignments, module restrictions, deactivate/reactivate and retained audit history. Fresh account/access-version checks, password-setup/expiry, confirmation throttling and last-Admin guard are enforced server-side. Figma `2024:2179` CRUD states extend the original P2 reference page; the earlier “not in frame/not implemented” description is superseded. Public registration, personal deletion/retention, SMTP delivery, technical timeout defaults and office permission confirmation retain their operating decisions; see handoff/progress.
- **Depends on:** P2, migrations000013–000014, Fortify and existing module/building policies.

### M17. Audit Logs
- **Status:** **Existing and verified P4–P7 storage + P8 Admin viewer** — recorded uploads/verifications/corrections, weather/datasets, forecast issuance/reconciliation/failures, scenarios/decisions/actions, report download preparation and scoped permission denials. Admin-only filtered/paginated list and on-demand immutable before/after/reason details, inclusive Manila dates and private/no-store responses. Historical roles were not recorded; actor names reflect current accounts and null actors retain an explicit unattributed label. Download counts mean audited preparation, not receipt. Unimplemented-module logging remains Proposed; not a claim of complete system activity coverage. Operational revision history remains available to authorized reviewers.
- **Acceptance criteria:** covers uploads, verifications, corrections, forecast runs, decisions, downloads, permission denials of interest; viewable by Admin only.
- **Depends on:** P4 onward.

### M18. Settings & System Health
- **Status:** **Existing and verified P8 unblocked scope (2026-11-15).** System Health: Admin-only page and on-demand throttled/private/no-store DB read/local-directory permission/Python pin-import-estimator diagnostics;5-second isolated process, redacted independent failures, no source/config mutations. Worker/scheduler/SMTP/backups/TLS/firewall/production approval remain explicitly unverified; local passed checks do not establish readiness. System Settings: Admin/EM-only read-only14-key configuration allowlist, real building/latest complete verified period, null tariff/target/threshold/PV defaults, no save endpoints. Fresh roles + named Gates/route middleware; denials audited. Existing Fortify account settings preserved. **Blocked / requires contracts:** numeric parameter editing, effective dates, tariff/budget cycle, target ownership and C5 trigger/evidence/priorities. Prototype15% is excluded.
- **Depends on:** P8.

---

## Not modules but required features

| Feature | Status | Source |
| --- | --- | --- |
| Weather data ingestion | Existing and verified P5: user-approved NASA POWER daily-to-monthly ingestion with retained values/units/provenance; no repeat ingestion required for P9 | P5 progress; `NASA_POWER_DATA_CONTRACT.md`; proposal §9.3 |
| Monthly operating routine | Existing and verified technical subset: HTTP intake/independent verification/preprocessing/real-worker forecast/distribution/actual-correction cycle plus separately verified synthetic evaluator/decision/action UAT. Operational activation, tariff-budget cycle, scheduled delivery and office/production acceptance remain blocked; the full institutional routine is not complete | `P9_VERIFICATION.md`, `DSS_UAT.md`; proposal §11.1 |
| Scheduled monthly forecast job | Proposed (scheduler + Redis queues) | Proposal §8.1 |
| HTTPS, backups, monitoring, least-privilege DB user | Proposed (production) | Proposal §12.2 |

## Cross-references

- Permissions per capability → `docs/ROLES_AND_PERMISSIONS.md` §2
- Schema per module → `docs/DATABASE_DESIGN.md`
- Build order → `docs/IMPLEMENTATION_PLAN.md`
