# QC Energy DSS — Quezon City Hall Energy Analytics, Forecasting, and Decision Support System

Decision Support System for Quezon City Hall buildings: verified electricity analytics, next-eligible-month consumption forecasting, explicit-assumption renewable-energy screening and audited workflows. DSS development and synthetic UAT are complete; operational rule activation remains disabled.

**Pilot scope:** Highrise Building and Treasury Building only. Not for households or citywide consumers.

**Status (2026-11-19): P9 scoped technical audit verified; operational/human acceptance pending.** Registry/intake/independent verification, NASA POWER/versioned analytics, Linear/baseline forecasting, account management, reviewed DSS evaluation/decision/actions, scenarios, scoped PDF/Excel reports, in-app notifications, Admin System Health and read-only System Settings are implemented and tested. Empty DSS activations, future tariff/budget/target and delivery gates remain intact. Local technical checks do not authorize real billing use or production deployment. See `docs/IMPLEMENTATION_PROGRESS.md`, `docs/P9_VERIFICATION.md` and `docs/AI_HANDOFF.md` for exact evidence and acceptance gates.

## Technology Stack

- Laravel 13 + Inertia 3
- React 19 + TypeScript + Tailwind CSS 4 + shadcn/ui
- MySQL (Laravel Sail / Docker)
- Laravel Fortify authentication
- Wayfinder type-safe routing
- Isolated Python/scikit-learn forecasting worker, invoked from Laravel; Linear primary + previous-three-month-average baseline

## Requirements

- PHP 8.3+, Composer, Node.js
- Docker (Laravel Sail) or a local MySQL server

## Getting Started

First checkout only; preserve an existing `.env` and database:

```bash
composer install
cp .env.example .env && php artisan key:generate
./vendor/bin/sail up -d        # MySQL on forwarded port 3309
./vendor/bin/sail artisan migrate  # additive migrations; never reset verified data
npm install
npm run build                  # compiled assets + Wayfinder form variants
```

The Sail application serves the configured `APP_PORT` (8082 in this local environment). `npm run dev` optionally runs Vite. Run artisan/DB tests through Sail: host PHP cannot resolve the Docker `mysql` hostname. See `python/README.md` for the pinned runtime setup; re-run setup only when the container/runtime is missing or rebuilt. Never import real billing data without authorization.

## Checks

```bash
composer test          # Pint + PHPStan + PHPUnit
composer test:forecast # 7 Python contract tests in prepared Sail runtime
npm run types:check    # TypeScript
npm run build          # Production frontend build
```

## Documentation

| File | Contents |
| --- | --- |
| `docs/AI_HANDOFF.md` | Current status, blockers, exact next task |
| `docs/PROJECT_CONTEXT.md` | Purpose, source-of-truth hierarchy, glossary |
| `docs/MODULES_AND_FEATURES.md` | All modules with status classification |
| `docs/ROLES_AND_PERMISSIONS.md` | Five proposed roles, permission matrix |
| `docs/FIGMA_DESIGN_GUIDE.md` | Figma design system and inspection status |
| `docs/DATABASE_DESIGN.md` | Actual vs. proposed schema |
| `docs/ML_AND_DSS_RULES.md` | Forecasting pipeline and DSS rules R1–R7 |
| `docs/IMPLEMENTATION_PLAN.md` | Phased roadmap P0–P9 |
| `docs/IMPLEMENTATION_PROGRESS.md` | Verified progress log |
| `docs/P9_VERIFICATION.md` | Integration/security/accessibility checkpoint and remaining acceptance gates |
| `docs/DSS_UAT.md` | Completed synthetic browser acceptance matrix, screenshots and policy boundaries |
| `docs/references/Proposed_QC_City_Hall_Solution.pdf` | Technical proposal (draft, unapproved) |

AI coding agents: **read `AGENTS.md` first.**
