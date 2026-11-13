# DATABASE_DESIGN.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-13

## 1. Actual schema (verified in this repo)

Only Laravel default migrations exist:

| Table | Migration | Notes |
| --- | --- | --- |
| `users`, `password_reset_tokens`, `sessions` | `database/migrations/0001_01_01_000000_create_users_table.php` | No role column |
| `cache`, `cache_locks` | `0001_01_01_000001_create_cache_table.php` | |
| `jobs`, `job_batches`, `failed_jobs` | `0001_01_01_000002_create_jobs_table.php` | Queue tables present but no workers configured |

**No domain tables exist.** Every table below is proposed until a migration lands here.

## 2. Proposed schema (proposal §9, ERD Figures 9–10)

Data hierarchy: **Complex → Buildings → Meters → Monthly Records** (proposal §9.1: "complex, building, meter, month").

Names are indicative; align with Laravel conventions when implementing. Order of creation follows `docs/IMPLEMENTATION_PLAN.md`.

### 2.1 Phase-critical tables

| Table (indicative) | Key columns | Source | Status |
| --- | --- | --- | --- |
| `buildings` | name, code (HIGHRISE/TREASURY), floor_area_m2 (nullable), operating_hours, notes | Proposal Table 9 | Proposed |
| `meters` | building_id FK, meralco_account_ref (restricted visibility), tariff_class, status | Proposal §9.1 | Proposed |
| `monthly_records` | meter_id FK, month (date, first-of-month), kwh, billed_demand_kw (nullable), cost_php (nullable), power_factor (nullable), verification_status (draft/verified), notes | Proposal Table 9 + Appendix A | Proposed; unique (meter_id, month) |
| `monthly_record_revisions` | record_id, changed fields, actor, reason | Proposal §9.3 corrections rule | Proposed |
| `weather_records` | location/month, variables (temp etc.), source (NASA POWER / PAGASA) | Proposal §9.3 | Proposed |
| `user_building` pivot | user_id, building_id | Building-level permissions | Proposed |

### 2.2 Forecasting & DSS tables

| Table | Key columns | Status |
| --- | --- | --- |
| `datasets` | versioned processed dataset per building | Proposed (capstone had `datasets`) |
| `processed_records` | derived features from verified months | Proposed |
| `forecasts` / `forecast_results` | building_id, target month, model name + version, predicted kwh, prediction range, run_by (user), run_at | Proposed; next eligible month only |
| `forecast_errors` | forecast_id, actual kwh, MAE/RMSE/R² | Proposed |
| `recommendations` | building_id, period, rule id (R1–R7), priority, evidence JSON, decision (pending/accepted/rejected/deferred), decided_by/decided_at | Proposed; one current result per period (capstone enforced this) |
| `actions` | recommendation_id, owner, due_date, outcome | Proposed |
| `targets` | building_id, monthly kWh target | Proposed (open item: who sets) |
| `tariffs` | period range, php_per_kwh, demand charges | Proposed; **open item: tariff source** |
| `alerts` | event type, recipients, channel, read_at | Proposed |
| `audit_logs` / `activity_logs` | actor, event, subject, before/after | Proposed (capstone had `audit_trails`, `activity_logs`) |

### 2.3 Capstone reference (external, `~/Documents/capstone-dss-clone` — not part of this repo)

Capstone migrations that exist there and may inform naming: `users` (+role, +permissions columns), `forecasts`, `datasets`, `consumption_records`, `climate_records`, `processed_records`, `forecast_results`, `dss_results`, `dss_result_revisions`, `reports`, `activity_logs`, `audit_trails`. Port concepts deliberately; do not copy migrations blindly.

## 3. Data governance rules (proposal §9.3 — binding for implementation)

| Topic | Rule |
| --- | --- |
| Ownership | QC Hall owns data; Energy Manager is business data owner |
| Verification | Uploaded months stay **draft** until a second person checks totals against the bill |
| Corrections | Recorded action replaces the value; previous value preserved in audit history |
| Missing months | Flag and explain; **never fill silently**; show data-sufficiency notice on forecasts |
| Outliers | Flag and review; keep real events, correct real errors |
| Retention/backup | Set with City Hall records policy; encrypted, tested backups |
| Weather | Keep the source (NASA POWER or PAGASA) with each value |
| Synthetic data | Load Synthetic Demo flag; **never** mixes into official analytics/forecasts |

## 4. Data classification (proposal §12.1)

| Data | Sensitivity | Handling |
| --- | --- | --- |
| User accounts | Personal info (RA 10173) | Minimal collection; DPO review before go-live |
| Meralco billing data + account refs | Confidential institutional | Role-restricted; never public hosting; authorization required |
| Forecasts, recommendations, reports | Internal | Role-based access; reports marked internal |
| Weather data | Public | No restriction |

## 5. Migration discipline

- All new migrations reversible with implemented `down()`.
- Never edit Laravel default migrations; never destroy verified data.
- Seeds for pilot buildings (HIGHRISE, TREASURY) are configuration data, not real billing data.
- Real Meralco data import (17-month Highrise history) is **Blocked / requires City Hall authorization** (proposal §1.3 Decision 1).

## 6. Cross-references

- Roles/permission enforcement over this schema → `docs/ROLES_AND_PERMISSIONS.md`
- Forecasting/derived fields → `docs/ML_AND_DSS_RULES.md`
- Build order for tables → `docs/IMPLEMENTATION_PLAN.md` (P2–P7)
