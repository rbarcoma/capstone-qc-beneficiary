# QC Energy DSS — Quezon City Hall Energy Analytics, Forecasting, and Decision Support System

Decision Support System for Quezon City Hall buildings: electricity consumption analytics, machine-learning next-month consumption forecasting, and explainable renewable-energy transition recommendations.

**Pilot scope:** Highrise Building and Treasury Building only. Not for households or citywide consumers.

**Status: project scaffolding phase.** This repo is a fresh Laravel starter kit; no domain features are implemented yet. See `docs/IMPLEMENTATION_PROGRESS.md` for verified status and `docs/AI_HANDOFF.md` for the exact next task.

## Technology Stack

- Laravel 13 + Inertia 3
- React 19 + TypeScript + Tailwind CSS 4 + shadcn/ui
- MySQL (Laravel Sail / Docker)
- Laravel Fortify authentication
- Wayfinder type-safe routing
- Python (scikit-learn) forecasting service, invoked from Laravel (planned)

## Requirements

- PHP 8.3+, Composer, Node.js
- Docker (Laravel Sail) or a local MySQL server

## Getting Started

```bash
composer install
cp .env.example .env && php artisan key:generate
./vendor/bin/sail up -d        # MySQL on forwarded port 3309
php artisan migrate
npm install
composer dev                   # Laravel dev server
npm run dev                    # Vite
```

## Checks

```bash
composer test          # Pint + PHPStan + PHPUnit
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
| `docs/references/Proposed_QC_City_Hall_Solution.pdf` | Technical proposal (draft, unapproved) |

AI coding agents: **read `AGENTS.md` first.**
