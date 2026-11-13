# PROJECT_CONTEXT.md

**Project:** Quezon City Hall Energy Analytics, Forecasting, and Decision Support System (QC Energy DSS)
**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-13

## 1. Purpose

Quezon City Hall pays monthly Meralco electricity bills for its buildings. The QC Energy DSS gives decision makers three things (per the technical proposal, §1):

1. **A clear view of the past** — monthly consumption analytics per building.
2. **A dependable estimate of the next month** — machine-learning next-month kWh forecasting.
3. **Advice they can understand** — explainable rule-based recommendations, including preliminary renewable-energy (solar) screening.

**Design principle** (proposal §3): *"Forecasting estimates; rules explain."* Every recommendation shows the numbers behind it. The system supports decisions; it does not make them.

## 2. Operational scope (fixed)

- **Pilot:** Highrise Building + Treasury Building, Quezon City Hall Complex.
- **Data hierarchy:** City Hall Complex → Buildings → Meters (Meralco accounts) → Monthly Records.
- **Data inputs:** monthly kWh, billed demand (kW), optional cost (PHP) and power factor; weather variables joined by the system.
- **Forecast horizon: next eligible month only**, per building, after verification.
- **Explicitly out of scope:** households, barangay halls, streetlights, citywide consumers, solar engineering feasibility studies (screening-level only), procurement/financing of equipment.

## 3. Source-of-truth hierarchy

| Priority | Source | Authoritative for | Caveat |
| --- | --- | --- | --- |
| 1 | Code in this repo | What is implemented | Fresh starter kit; no domain code yet |
| 2 | Figma file | Visual design | Mockups are not working backends |
| 3 | Technical proposal (`docs/references/Proposed_QC_City_Hall_Solution.pdf`) | Intended workflows, requirements | **Draft 1.0, not approved by QC Hall** |
| 4 | Figwright MCP | Un-documented Figma frames | Requires plugin open in Figma Desktop |
| 5 | `docs/` | Prior verified decisions | Preserve; update only what changed |

### 3.1 The "Existing" trap (read this)

The proposal labels capabilities "Existing" because they exist in the **original capstone system** — a different codebase at `~/Documents/capstone-dss-clone` (external reference only, not part of this project; its companion "Document 1: Capstone System Documentation" is not in this repo).

**This repo (`capstone-qc-beneficiary`) contains none of those features.** It is a fresh `laravel/react-starter-kit`. Therefore:

- "Existing and verified" in our docs means *verified in this repo*.
- Capstone-era capabilities are labeled **"Existing in capstone, not ported"** until re-implemented and tested here.

### 3.2 Known source conflicts (do not silently resolve)

| # | Conflict | Resolution status |
| --- | --- | --- |
| C1 | Proposal says forecasting uses previous-3-month-average baseline + Linear **and Ridge** Regression; capstone `Python/algo/` shows Linear, Random Forest, XGBoost scripts; original capstone claim in system prompt says Linear Regression primary | Working rule until user decides: **Linear Regression primary**, baselines for comparison, Ridge/others experimental. Tracked in `docs/ML_AND_DSS_RULES.md` §4 |
| C2 | Proposal Appendix A CSV template vs. capstone `Python/` dataset column conventions | Standardize on proposal template (Table 21) when Data Management is built; confirm against Document 1 |
| C3 | `.env` `APP_NAME="DSS Enegy QC"` contains a typo | Cosmetic; fix to "QC Energy DSS" at P1 branding phase (needs user go-ahead since it touches `.env`) |
| C4 | Proposal claims 17 months of real Highrise Meralco data (Apr 2025–Aug 2026) | Data lives with the capstone project, not here. Do not import without explicit user instruction and City Hall authorization (proposal §1.3, Decision 1) |

## 4. Repository status snapshot (verified 2026-11-13)

- Laravel `^13.17`, Fortify `^1.37`, Inertia `^3.0`, Wayfinder `^0.1`, Chisel `^0.1`; dev: Larastan, Pint, Sail, PHPUnit 12.
- Frontend: React 19, Tailwind 4, shadcn/ui + Radix, vite (vite-plus core), babel react-compiler, Wayfinder vite plugin.
- Migrations: `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs` only (Laravel defaults).
- Pages: `welcome.tsx`, placeholder `dashboard.tsx`, Fortify auth pages, settings pages.
- Tests: starter auth/settings tests only. No domain tests.
- No buildings/meters/consumption/forecast/DSS code, no roles system, no Python integration in this repo.

## 5. External references

| Reference | Path | Status |
| --- | --- | --- |
| Technical proposal (Doc 2 of 2) | `docs/references/Proposed_QC_City_Hall_Solution.pdf` | Draft, unapproved; authoritative for intent |
| Original capstone codebase | `~/Documents/capstone-dss-clone` | External only; port concepts deliberately, verify everything |
| Figma design file | https://www.figma.com/design/qrM4ARYcW0bu16ooetbkmg/Untitled?node-id=0-1 | Primary UI reference; see `docs/FIGMA_DESIGN_GUIDE.md` |
| Figwright MCP | `opencode.json` (project) + plugin in Figma Desktop | Verified working 2026-11-13 (server 0.7.0, plugin bridge :3055) |

## 6. Glossary

| Term | Meaning |
| --- | --- |
| Verified month | Monthly record checked against the bill by the Energy Manager; only verified months feed analytics/forecasts |
| Draft month | Uploaded record awaiting verification |
| Forecast eligibility | A building can be forecast when its latest month is verified and history meets the minimum |
| DSS | Decision Support System (rules + evidence, not automation) |
| Solar pre-assessment | Screening-level offset estimate from irradiance, assumed capacity, performance ratio — **not** an engineering feasibility study |
| Capstone | The original working system in `~/Documents/capstone-dss-clone` |
| Meralco | Manila Electric Company, the utility billing QC Hall |

## 7. Cross-references

- Module inventory → `docs/MODULES_AND_FEATURES.md`
- Roles → `docs/ROLES_AND_PERMISSIONS.md`
- Design system → `docs/FIGMA_DESIGN_GUIDE.md`
- Roadmap → `docs/IMPLEMENTATION_PLAN.md`
- Current status → `docs/AI_HANDOFF.md`
