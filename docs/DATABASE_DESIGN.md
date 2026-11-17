# DATABASE_DESIGN.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-16

## 1. Actual schema (verified in this repo, updated 2026-11-16)

Laravel defaults and domain migrations through the approved M16 schema-only checkpoint are implemented:

| Table | Migration | Notes |
| --- | --- | --- |
| `users`, `password_reset_tokens`, `sessions` | `database/migrations/0001_01_01_000000_create_users_table.php` + `2026_11_14_000001_add_role_to_users_table.php` + `2026_11_16_000013_add_user_management_foundation.php` | Five-role enum/default viewer retained; passive active/version/access-version/deactivation metadata added |
| `cache`, `cache_locks` | `0001_01_01_000001_create_cache_table.php` | |
| `jobs`, `job_batches`, `failed_jobs` | `0001_01_01_000002_create_jobs_table.php` | Queue tables present but no workers configured |
| `buildings`, `user_building` | `2026_11_14_000002_create_buildings_table.php` | Unique building code, nullable metadata; composite assignment key |
| `meters` | `2026_11_14_000003_create_meters_table.php` | Building FK; unique restricted account ref, nullable tariff, active/inactive status |
| `buildings.deleted_at`, `meters.deleted_at` | `2026_11_14_000004_add_soft_deletes_to_registry_tables.php` | Reversible additive migration; registry removal preserves stored rows |
| `monthly_records`, `audit_logs` | `2026_11_14_000005_create_monthly_records_and_audit_logs_tables.php` | Meter/month uniqueness, decimal bill values, Draft intake and actor-linked audit events |
| `monthly_record_revisions`, record version/current editor | `2026_11_14_000006_add_monthly_record_revisions.php` | Retained before/after corrections, stale-action guard and independent-review author tracking |
| `weather_records` | `2026_11_14_000007_create_weather_records_table.php` | Unique source/location/aggregation identity + month; daily raw/provenance, means/totals, missing flags/hash |
| `datasets`, `processed_records` | `2026_11_14_000008_create_datasets_and_processed_records_tables.php` | Unique version/fingerprint; retained verified and observed/lagged weather snapshots, complete monthly rollups/features/metrics |
| `forecasts`, `forecast_results`, `forecast_errors`, forecast-linked audits | `2026_11_14_000009_create_forecast_tables.php` | Versioned immutable issuance/evaluation, source/runtime identity, reconciled/retracted realized errors |
| `recommendations`, `recommendation_decisions`, `actions` | `2026_11_14_000010_create_recommendation_workflow_tables.php` | Source/policy fingerprint identity; retained decision snapshots, optimistic version and final states; unique assigned action per accepted recommendation |
| action version/completion + `action_updates` | `2026_11_14_000011_add_action_lifecycle_history.php` | Default version1, nullable server completion time; retained actor/before/after and unique action/version history, restrict-on-delete action FK |
| `notification_receipts` | `2026_11_15_000012_create_notification_receipts_table.php` | Personal read timestamp, unique user/source-identity key; user FK cascades on account removal. Current notices derive from live sources, not a stored delivery archive |
| `user_module_restrictions`, user metadata + audit subject ID | `2026_11_16_000013_add_user_management_foundation.php` | Unique user/module restriction storage, cascade-on-delete user FK; existing users active/default version1; nullable indexed historical audit subject ID has no FK so deletion retains its value |

Migrations through `000013` are additive and applied. P6 forecast persistence, P7 independent decision/action foundations and P8 minimal action lifecycle/history, scoped Reports and current-source Notifications are **Existing and verified**. M16 adds a verified schema/model foundation only. No production recommendation generator exists; default-null policy configuration blocks new decisions, while previously accepted actions may retain factual follow-up. Registry rows with billing history cannot be removed (meter lock guards concurrent intake). No real/synthetic billing history is seeded into development.

**M16 foundation boundary (2026-11-16):** `users.is_active` defaults true, `version`/`access_version` default1, `deactivated_at` nullable. These fields are guarded against mass assignment. `User::moduleRestrictions()` / `UserModuleRestriction::user()` store restrictions only; absent rows inherit the current role baseline. No grant table, module catalogue/validation, request authorization, login blocking, session revocation, CRUD endpoints or password-setup fields added. Fortify and existing account-deletion policies are unchanged. `down()` removes only the new schema; rolling it back discards new restriction/metadata values, not legacy account/assignment/audit rows.

**Audit subject retention:** `audit_logs.subject_user_id` is a nullable indexed unsigned ID deliberately **without a foreign key**. It remains after account hard deletion; `AuditLog::subjectUser()` is only an optional live lookup and becomes null. This avoids cascading events, blocking the existing deletion flow or nulling the historical subject identifier. Existing `actor_id` FK behavior and JSON before/after/reason are unchanged. Future account-management writers must validate the subject and capture appropriate historical identity/change evidence in snapshots; existing events are not backfilled or reattributed. No audit writer or API projection added in this checkpoint.

**P8 audit viewer (Existing and verified):** reads the retained `audit_logs` table without a schema change. `actor_id` is nullable (system event or deleted actor); current account identity is not historical role attribution. Viewer list projects only IDs/event/time/actor/references; snapshots/reason are Admin-only on-demand details. No retention/delete/edit endpoint is introduced.

## 2. Proposed schema (proposal §9, ERD Figures 9–10)

Data hierarchy: **Complex → Buildings → Meters → Monthly Records** (proposal §9.1: "complex, building, meter, month").

Names are indicative; align with Laravel conventions when implementing. Order of creation follows `docs/IMPLEMENTATION_PLAN.md`.

### 2.1 Phase-critical tables

| Table (indicative) | Key columns | Source | Status |
| --- | --- | --- | --- |
| `buildings` | name, code (HIGHRISE/TREASURY), floor_area_m2 (nullable), operating_hours, notes, deleted_at | Proposal Table 9 | Existing and verified (P2/P3) |
| `meters` | building_id FK, meralco_account_ref (restricted visibility), tariff_class, status, deleted_at | Proposal §9.1 | Existing and verified (P3) |
| `monthly_records` | meter_id FK, month, kwh, billed_demand_kw, cost_php, power_factor, verification_status, notes, source, uploaded_by, last_edited_by, verified_by, verified_at, version | Proposal Table 9 + Appendix A | Existing and verified (P4); unique (meter_id, month), retained FK history |
| `monthly_record_revisions` | monthly_record_id, actor_id, reason, before/after JSON, timestamps | Proposal §9.3 corrections rule | Existing and verified (P4), paginated operational history for Admin/Energy Manager |
| `weather_records` | location_key/month, source, coordinates, weather_values, units, quality, raw_values, source_metadata, content_hash, fetched_at | NASA POWER contract in `NASA_POWER_DATA_CONTRACT.md` | Existing and verified (P5); null/incomplete distinct from zero |
| `user_building` pivot | user_id, building_id | Building-level permissions | Existing and verified (P2) |
| `user_module_restrictions` | id, user_id, module_key (64 chars), timestamps; unique user/module | Restriction-only storage; missing row = existing role baseline | Existing and verified schema/model foundation only (M16); enforcement deferred |

### 2.2 Forecasting & DSS tables

| Table | Key columns | Status |
| --- | --- | --- |
| `datasets` | building/version, pipeline/cutoff, fingerprint, input_manifest, issues, row/weather counts, creator | Existing and verified P5; unique building/version + fingerprint, no update/delete endpoint |
| `processed_records` | dataset/month, complete-building kWh/optional cost, source_snapshot, weather_record_id/observed+lagged snapshots, features/metrics | Existing and verified P5; unique dataset/month, retained lineage |
| `forecasts` / `forecast_results` | building/dataset, target/training month, issuance key, model/runtime versions, prediction/band/baseline, worker evidence, actor/time; per-method/period held-out results | Existing and verified P6; next eligible month only, immutable versioned runs |
| `forecast_errors` | unique forecast_id, valid/reason/state hash, actual snapshot, absolute/percentage error | Existing and verified P6; current verified actual reconciles, corrections retract; MAE/RMSE/R² are stored walk-forward worker metrics |
| `recommendations` | forecast_id (building/target via forecast), rule code, policy/source fingerprints, title/message/evidence/priority, cost flag, evaluation_status, decision/version, decided_by/at | Existing and verified P7 foundation; unique forecast/rule/policy, requires evaluated matching policy/current source for decisions; no generator yet |
| `recommendation_decisions` | recommendation/actor, from/to state, note, recommendation_version, source_snapshot | Existing and verified P7; retained append-only workflow history, actor nullable on user removal |
| `actions` | unique recommendation_id, owner_id, title, due_date, status, outcome, version, completed_at | Existing and verified P7 assignment + P8 EM-only Open/follow-up/Completed lifecycle; assignment/source fixed, completion server-timed and final in v1 |
| `action_updates` | action_id, nullable actor_id, action_version, before/after JSON, timestamps | Existing and verified P8; unique action/version, retained reported outcome/state snapshots, atomic with action/audit; no edit/delete endpoint |
| `targets` | building_id, monthly kWh target | Proposed (open item: who sets) |
| `tariffs` | period range, php_per_kwh, demand charges | Proposed; **open item: tariff source** |
| `notification_receipts` | user_id, notice_key SHA256, read_at, unique user/key | Existing and verified P8 live-inbox personal read state; notices derive current draft/version or current-issued forecast/run, not historical sent events |
| `alerts` | event type, recipients, channel, sent_at | Proposed future delivery/event archive; current in-app inbox uses live sources + receipt table instead |
| `audit_logs` | actor_id, nullable historical subject_user_id (no FK), event, monthly_record_id, forecast_id, before/after JSON, reason | Existing and verified P4–P7/P8 events/viewer + M16 retained-subject storage; account-management audit writers remain deferred |

### 2.3 Capstone reference (external, `~/Documents/capstone-dss-clone` — not part of this repo)

Capstone migrations that exist there and may inform naming: `users` (+role, +permissions columns), `forecasts`, `datasets`, `consumption_records`, `climate_records`, `processed_records`, `forecast_results`, `dss_results`, `dss_result_revisions`, `reports`, `activity_logs`, `audit_trails`. Port concepts deliberately; do not copy migrations blindly.

## 3. Data governance rules (proposal §9.3 — binding for implementation)

| Topic | Rule |
| --- | --- |
| Ownership | QC Hall owns data; Energy Manager is business data owner |
| Verification | Uploaded/corrected values stay **draft** until another Admin/Energy Manager checks them against the bill; current author (`last_edited_by`, fallback uploader) cannot self-verify |
| Corrections | Reason required; previous values/status preserved in revisions + audit; record returns to Draft and verifier fields clear. Meter fixed; month changes validate completed periods and reject collisions under meter/record locks. Versions reject stale actions |
| Missing months | Flag and explain; **never fill silently**; show data-sufficiency notice on forecasts |
| Outliers | Flag and review; keep real events, correct real errors |
| Retention/backup | Set with City Hall records policy; encrypted, tested backups |
| Weather | Keep the source (NASA POWER or PAGASA) with each value |
| Synthetic data | P4 loader is a labeled browser preview with separate fixture shape/state; no record/meter IDs, writes, persistence or verification path. **Never** mixes into official analytics/forecasts |

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
