# MODULES_AND_FEATURES.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-13

**Status vocabulary (strict):**
- **Existing and verified** — present and tested in this repo.
- **Existing in capstone, not ported** — claimed by proposal/original capstone; not in this repo; port deliberately and verify.
- **Proposed** — recommended by the technical proposal; needs implementation + City Hall context.
- **Future enhancement** — later phases only (proposal §5.2).
- **Blocked / requires approval** — needs explicit user instruction or City Hall decision.
- **Design-only** — Figma design exists; backend does not.

**Verified repo baseline (2026-11-13):** auth pages (Fortify), settings pages, welcome page, placeholder dashboard, shadcn UI kit, app shell layouts. **No domain module exists in code.** Every module below is therefore Proposed/Design-only unless stated otherwise.

---

## Core & operational modules

### M1. Dashboard
- **Status:** Design-only (frame verified) + placeholder page in code.
- **Users:** All roles (role-specific variants pending inspection).
- **Purpose:** Landing view per workspace; building + period selection; status at a glance.
- **Figma:** Admin frame `2024:3` verified (see `docs/FIGMA_DESIGN_GUIDE.md` §2.1).
- **Verified design content:** 4 KPI cards, monthly electricity trend, monthly operating routine, pilot-building readiness table, notices, Upload Monthly Bill + Load Synthetic Demo buttons.
- **Backend needs:** shared props (buildings, latest verified period, KPIs from verified data only), readiness computation.
- **Permissions:** all roles view; content filtered by building access.
- **Acceptance criteria:** renders empty states from real DB (0 verified months etc.); never hardcodes mockup numbers; matches frame layout/tokens; role variant correct.
- **Depends on:** P1 (layout/branding), P3–P5 (data).

### M2. Buildings & Meters (registry)
- **Status:** Proposed (proposal §9.1).
- **Users:** Admin (manage), others (view per permissions).
- **Requirements:** complex → buildings → meters registry; attributes per proposal Table 9 (building: name, code, floor area m², operating hours, notes; meter: Meralco account reference [restricted], tariff class, status).
- **Backend:** CRUD + policies; account-number visibility restricted (Admin, Energy Manager).
- **Acceptance criteria:** reversible migrations; pilot buildings (HIGHRISE, TREASURY) seedable; building codes referenced by CSV template.
- **Depends on:** P2 (roles), feeds everything.

### M3. Data Management (upload/encode)
- **Status:** Existing in capstone, not ported (CSV upload with validation + preprocessing claimed).
- **Users:** Facilities Personnel (own buildings), Energy Manager, Admin.
- **Requirements:** proposal Appendix A CSV template: `building_code, month (YYYY-MM-01), kwh, billed_demand_kw, cost_php, power_factor, notes`; one row per building+month; validation errors surfaced per row; duplicate/missing month detection.
- **Figma:** pending inspection.
- **Acceptance criteria:** invalid/duplicate rows rejected with row-level messages; uploaded months stored as **draft**; synthetic demo data loadable but isolated and flagged.
- **Depends on:** M2; P4.

### M4. Verification Queue
- **Status:** Proposed (proposal §9.3: draft until a second person verifies against the bill).
- **Users:** Energy Manager, Admin.
- **Backend:** draft→verified state change, audited; corrections post-verification create recorded actions and keep previous value in audit history.
- **Acceptance criteria:** only verified months feed analytics/forecasts; verification is auditable; unauthorized users cannot verify (server-enforced).
- **Depends on:** M3, P2.

### M5. Preprocessing
- **Status:** Existing in capstone, not ported (claimed).
- **Requirements:** clean verified months, join weather (source recorded: NASA POWER or PAGASA — proposal §9.3), compute derived features (e.g., load factor when billed demand exists); processed datasets versioned for reproducibility (capstone has dataset/forecast versioning migrations).
- **Acceptance criteria:** derived from verified data only; version lineage queryable.
- **Depends on:** M4, weather data (source TBD).

### M6. Energy Analytics
- **Status:** Existing in capstone, not ported (trends, MoM/YoY, actual-vs-predicted, change indicators); cross-building comparison, cost analysis (PHP), kWh/m², load factor = **Proposed** (proposal §10.1).
- **Users:** all roles (building-filtered for Facilities).
- **Acceptance criteria:** only verified data; comparisons show context (similar-use caveat); cost figures only where bills include them.
- **Depends on:** M5; pilot analytics feasible with 17-month Highrise history **if** data is authorized and ported (blocked).

### M7. Building Comparison
- **Status:** Proposed (proposal §10.1, Table 11).
- **Requirements:** rank by kWh, cost, kWh/m²; show context beside rankings.
- **Depends on:** M6, floor-area data (open item, proposal Table 20 #6).

### M8. Forecasting
- **Status:** Existing in capstone, not ported (next-month forecast; baselines, Linear, Ridge); scheduled run + prediction range + error tracking = Proposed.
- **Users:** Energy Manager (run), Budget Officer/Viewer (view), Facilities (own buildings view).
- **Hard constraints:** **next eligible month only**; runs after the month is verified; Linear Regression primary (see `docs/ML_AND_DSS_RULES.md` §4); confidence label reflects history sufficiency.
- **Acceptance criteria:** forecast stored with model version + metrics; ineligible buildings show data-sufficiency notice, not a number.
- **Depends on:** M5, Python integration (P6).

### M9. Model Evaluation
- **Status:** Existing in capstone, not ported (MAE, RMSE, R², actual-vs-predicted); challenger-vs-current promotion = Proposed (proposal §10.2).
- **Acceptance criteria:** metrics computed on walk-forward test months; **no fabricated metrics**; error history kept per month.
- **Depends on:** M8.

### M10. Decision Support (DSS)
- **Status:** Existing in capstone, not ported (rule-based R1–R7); configurable thresholds/targets/action tracking/what-if = Proposed.
- **Users:** Energy Manager (act), Budget Officer/Viewer (view).
- **Requirements:** see `docs/ML_AND_DSS_RULES.md` §5; every recommendation carries its evidence numbers; Energy Manager accepts/rejects/defers; accepted items become actions with owner + due date (proposal §10.3).
- **Depends on:** M6, M8; P7.

### M11. Solar & Scenarios
- **Status:** Existing in capstone, not ported (solar pre-assessment); what-if = Proposed (§10.3).
- **Hard constraint:** screening-level only (irradiance, assumed capacity, performance ratio) — **never** an engineering feasibility conclusion; what-if is arithmetic on the forecast, not measure prediction.
- **Depends on:** M10.

### M12. Budget Planning
- **Status:** Proposed (budget view: forecast kWh × tariff → estimated cost + range) — proposal §10.5.
- **Users:** Budget Officer, Energy Manager, Admin, Viewer (view).
- **Open item:** tariff source + budget cycle (proposal Table 20 #5). **Blocked on that decision** for real cost outputs.
- **Depends on:** M8.

### M13. Action Tracker
- **Status:** Proposed (owner, due date, outcome; feeds monthly review).
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
- **Status:** Admin/Viewer existing in capstone; five roles + building permissions = Proposed. Full plan in `docs/ROLES_AND_PERMISSIONS.md`.
- **Depends on:** P2.

### M17. Audit Logs
- **Status:** Existing in capstone, not ported (audit trail claimed); extend to corrections + decisions (proposal §12.2).
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
