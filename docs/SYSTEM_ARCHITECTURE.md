# SYSTEM_ARCHITECTURE.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-13

## 1. Stack (verified in this repo)

| Layer | Technology | Verified where |
| --- | --- | --- |
| Backend | Laravel `^13.17`, PHP 8.3 | `composer.json` |
| Auth | Laravel Fortify `^1.37` (login, registration, 2FA, password reset) | `app/Actions/Fortify/`, `config/fortify.php` |
| SPA bridge | Inertia 3 (`inertiajs/inertia-laravel`) | `composer.json`, `resources/js/app.tsx` |
| Frontend | React 19 + TypeScript | `package.json`, `tsconfig.json` |
| Styling | Tailwind CSS 4 (`@tailwindcss/vite`) | `package.json`, `resources/css/app.css` |
| UI kit | shadcn/ui + Radix primitives | `resources/js/components/ui/`, `components.json` |
| Routing | Laravel Wayfinder (`^0.1`) — generated typed route/controller actions | `resources/js/actions/`, `resources/js/routes/` |
| Build | Vite (vite-plus core) + babel react-compiler | `vite.config.ts`, `package.json` |
| Database | MySQL via Laravel Sail (dev), forwarded port 3309, DB `capstone_qc_beneficiary` | `.env`, `compose.yaml` |
| ML | Isolated Python/scikit-learn invoked from Laravel | Verified P6 worker, locked publication/evaluation and actual reconciliation; see `docs/ML_AND_DSS_RULES.md` |
| Tooling | Pint (lint), Larastan/PHPStan (types), PHPUnit 12 | `composer.json` scripts |

## 2. Historical starter directory map (verified 2026-11-13)

Current domain controllers/services/models/pages and tests extend this original baseline. Authoritative per-module files/status are in `IMPLEMENTATION_PROGRESS.md`; this dated map is retained as historical context, not a claim that the repo has only a User model.

```
app/
  Actions/Fortify/          # Fortify user actions (CreateNewUser, ResetUserPassword)
  Concerns/                 # Profile/Password validation rules
  Http/
    Controllers/            # Controller base + Settings/{Profile,Security}Controller
    Middleware/             # HandleInertiaRequests, HandleAppearance
  Models/User.php           # Single model; NO roles yet
  Providers/                # AppServiceProvider, FortifyServiceProvider
routes/
  web.php                   # '/' welcome + auth'd 'dashboard' (inertia routes)
  settings.php              # Fortify settings routes
database/migrations/        # users, cache, jobs (Laravel defaults only)
resources/js/
  actions/ routes/          # Wayfinder generated actions/routes
  components/ui/            # 25+ shadcn components (button, card, dialog, select, sidebar, ...)
  components/               # App shell: app-sidebar, app-header, nav-main, breadcrumbs, ...
  layouts/                  # app-layout, auth layouts, settings layout
  pages/                    # welcome, dashboard (placeholder), auth/*, settings/*
  types/                    # auth, navigation, ui types
tests/                      # Feature: Auth/*, Settings/*, Dashboard; Unit: Example
```

## 3. Request lifecycle (current)

1. HTTP request → Laravel (routes in `routes/web.php`, `routes/settings.php`).
2. Middleware: web stack → `HandleInertiaRequests` (shares `auth.user`, flash, `name`/`quote` props) → `HandleAppearance`.
3. Fortify handles auth endpoints directly (login/register/2FA/reset) via `FortifyServiceProvider` actions.
4. Controllers return Inertia pages; Wayfinder regenerates `resources/js/actions/` + `resources/js/routes/` for typed frontend calls (Vite plugin: `@laravel/vite-plugin-wayfinder`).
5. Frontend pages render inside `resources/js/layouts/` (app sidebar layout, auth layouts, settings layout).

## 4. Target architecture (proposed — matches proposal §8)

```
Browser (React/Inertia SPA)
   │  HTTPS (proposed: reverse proxy + firewall)
   ▼
Laravel 13
   ├─ Auth: Fortify (+ proposed: roles middleware, building-level policies)
   ├─ Controllers (Wayfinder) ── Inertia pages ── shadcn/ui
   ├─ Domain services (proposed): registry, ingestion/validation,
   │   verification, preprocessing, analytics, DSS rules, reports
   ├─ Audit trail (proposed) → audit_logs
   └─ Scheduler/Queue (proposed: Redis) → monthly forecast job, alerts
            │
            ▼ (subprocess invocation, proposed)
   Python service: pandas/numpy/scikit-learn
   ├─ feature building (verified months + weather)
   ├─ model comparison (baselines vs Linear)
   └─ next-month forecast + metrics (MAE/RMSE/R²) → JSON back to Laravel
            │
            ▼
   MySQL (system of record: complex → buildings → meters → monthly records,
          verified months only; forecasts; recommendations; audit)
```

Key constraints preserved from the proposal:

- Laravel calls Python; there is **no** separate ML microservice unless the user approves FastAPI later (proposal §8.1 marks FastAPI "Proposed / optional").
- Forecasts run **after** a month is verified (proposal §11.1 step 4).
- Audit every upload, correction, verification, forecast run, decision, and download (proposal §12.2).

## 5. Architectural decisions already made (in this repo)

**P8/P9 additions (2026-11-15):** scoped exports render in memory, final-check source/actor/permissions under locks and commit preparation audits before release. Live operational notices derive current sources with personal read receipts (no SMTP/queue delivery claim). Admin System Health runs only explicit bounded read/permission/runtime diagnostics; System Settings is an Admin/EM read-only allowlist with no policy-setting writes. C5 remains null. Feature tests are independent of developer Vite/SSR; isolated synthetic monthly-cycle integration and scope-limited accessibility evidence are in `P9_VERIFICATION.md`.

| Decision | Rationale |
| --- | --- |
| Keep Fortify as-is; extend roles via migrations + middleware/policies | Preserves verified starter kit behavior and its tests |
| Use Wayfinder action imports in new pages (`import { dashboard } from '@/routes'`) | Matches starter-kit convention (`resources/js/pages/dashboard.tsx:3`) |
| Build domain tables as new reversible migrations; never edit Laravel default migrations | Migration safety; default tables never destroyed |
| Reuse `app-sidebar`/`app-header` shell and shadcn components for the Figma layout | Verified Figma sidebar pattern (262px) maps onto `components/ui/sidebar.tsx` |
| Synthetic demo data flagged and isolated from verified Meralco records | Data governance rule (proposal §9.3) |

## 6. Environments

| Env | Command | Notes |
| --- | --- | --- |
| Dev (full) | `./vendor/bin/sail up -d` + `composer dev` + `npm run dev` | MySQL container, forwarded port 3309 |
| Checks | `composer test` (Pint + PHPStan + PHPUnit), `npm run types:check`, `npm run build` | Run after every change |
| Production | Not set up. Proposal §8.3: on-premise (Option A) or approved government cloud (Option B); public hosting forbidden for real billing data | Blocked on City Hall decisions |

## 7. Cross-references

- Actual vs. proposed database → `docs/DATABASE_DESIGN.md`
- Roles/permissions enforcement → `docs/ROLES_AND_PERMISSIONS.md`
- Phase-by-phase build order → `docs/IMPLEMENTATION_PLAN.md`
