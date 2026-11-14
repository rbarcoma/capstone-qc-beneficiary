# IMPLEMENTATION_PROGRESS.md

**Repo:** `capstone-qc-beneficiary` · Append-only log. Only record what was actually run and verified.

## 2026-11-13 — P1 Global UI, branding, and dashboard (verified)

**Scope:** shell + dashboard rebuilt to match verified Figma frame `2024:3` (HTML EXACT / Admin / Dashboard), with real empty states. Design tokens extracted via Figwright `get_design_context` (full detail, 173 nodes) this session.

**Design tokens mapped → `resources/css/app.css` (`:root` light theme + sidebar vars):**
- `--background` `#F5F7FB`, `--foreground` `#1C2B40`, `--card` `#FFFFFF`, `--primary`/`--sidebar` `#173A7A`, `--muted-foreground` `#66758B`, `--border` `#DFE6F0`, `--input` `#DBE3ED`, `--ring`/accent `#2E61A1`, `--secondary`/table header `#EFF4FB`, sidebar accent `#315B93`, sidebar border/role box `#2A4D86`, sidebar foreground `#D3DFF1`. Font `--font-sans` → **Inter** (bunny font in `vite.config.ts`, weights 400/500/600/700). Sidebar width constant → `16.375rem` (262px, frame-verified) in `ui/sidebar.tsx`.
- Dark theme untouched (design is light-only).

**Files changed/created:**
- `resources/css/app.css` (tokens), `vite.config.ts` (Inter), `resources/js/components/ui/sidebar.tsx` (width constant only), `ui/table.tsx` (new, shadcn table set), `types/navigation.ts` (+`DisabledNavItem`, `NavGroup`), `types/dashboard.ts` (new, exported from `types/index.ts`).
- `components/nav-groups.tsx` (new grouped nav renderer), `components/app-sidebar.tsx` (rewritten: QC brandmark + ENERGY DSS/Quezon City Hall, role workspace box, 5 nav groups / 18 items), `components/app-sidebar-header.tsx` (rewritten: 70px topbar, uppercase title, avatar menu; breadcrumbs row only when >1).
- `pages/dashboard.tsx` (rewritten per frame: eyebrow/title/subtitle, Building select + latest-verified-period input, 4 KPI cards, trend card, monthly operating routine, pilot-building readiness table, notice, footer).
- `app/Http/Controllers/DashboardController.php` (new; data-driven props, all derived from DB — currently real empty states), `routes/web.php` (dashboard → controller), Wayfinder regenerated via `npm run build`.

**Deliberate deviations from the frame (recorded, not silent):**
1. Demo-only elements **excluded**: 20 HOTSPOT overlays, "INTERACTIVE DESIGN DEMO" badge, "SIMULATED ROLE" select, "CAPSTONE / PROTOTYPE" badge, demo disclaimers ("All data stays within this browser session", "Experimental standalone HTML…").
2. Role workspace box shows the **user's name** until P2 introduces roles (frame shows simulated role "Admin"; dev user is coincidentally named Admin).
3. Notice card copy adapted to a **data-integrity notice** (frame copy was demo-session-specific).
4. Non-Dashboard nav items render **disabled (non-clickable)** until their module phases ship; role-driven filtering lands in P2.
5. "Upload monthly bill" / "Load synthetic demo" buttons render **disabled** (Data Management = P4).
6. KPI "Pilot buildings" caption lists registered building names from the DB; empty state reads "No pilot buildings registered yet" (frame shows demo value 2).

**Verification:**
- `npm run types:check`: clean. `npm run build`: ✓ (Wayfinder actions/routes regenerated, `dashboard()` route present).
- `composer test`: Pint passed, PHPStan 0 errors, PHPUnit 39 tests / 36 passed / 3 skipped.
- Visual check vs frame `2024:3`: Playwright (Chromium, temp install) logged in as local dev user `admin@example.com` (created via tinker; id 2; password `password`) and captured 1440×1203 screenshot — layout, tokens, empty states, and routine step-1 highlight match the frame.

**Open:** APP_NAME typo fix still needs user go-ahead (unchanged).

## 2026-11-13 — P0 Environment verification (verified)

**Scope:** baseline checks + environment fixes. No domain features added.

**Environment verified:**
- `./vendor/bin/sail up -d` → `capstone-qc-beneficiary-mysql-1` + `capstone-qc-beneficiary-laravel.test-1` running.
- MySQL reachable via Sail: `./vendor/bin/sail artisan migrate:status` → 3 default migrations Ran; `db:show` OK.
- Constraint discovered: host PHP cannot resolve docker hostname `mysql` → **artisan/PHPUnit must run via `./vendor/bin/sail`**.

**Fixes applied (all reversible/minimal):**
1. `composer.json` `types:check` → `phpstan analyse --memory-limit=1G` (PHPStan crashed at 128M default).
2. `composer.json` `test` phpunit step → `./vendor/bin/sail artisan test` (host can't reach dockerized MySQL).
3. `npm install` re-run after removing `node_modules` + `package-lock.json` (vite-plus native bindings missing — npm optional-deps bug; build crashed before this).
4. Enabled Fortify `Features::emailVerification()` in `config/fortify.php` + `implements MustVerifyEmail` in `app/Models/User.php` — the starter ships verify-email pages/profile UI/tests that import `@/routes/verification`, which only exists with the feature enabled; this made the build impossible before. Kit inconsistency resolved in the kit's intended direction.
5. Wayfinder regeneration: **`./vendor/bin/sail artisan wayfinder:generate` produces older-format actions (no `.form()` variants) and clobbers Vite-generated files — do NOT use it.** Delete `resources/js/{actions,routes}` and run `npm run build` to regenerate with `formVariants: true` (vite.config.ts).

**Baseline results (all green):**
- Pint: passed. PHPStan: 0 errors. PHPUnit: 39 tests, 36 passed, 3 skipped (2FA-related), 100 assertions.
- `npm run types:check`: clean. `npm run build`: ✓ 2291 modules, built in 6.04s.

**Deferred:** initial admin user seeder → P2 (roles land there; seeding an "admin" now would pre-decide role storage).

**Open:** APP_NAME typo fix (`DSS Enegy QC` → `QC Energy DSS`) still needs explicit user go-ahead (touches `.env`) — conflict C3.

## 2026-11-13 — Documentation & environment baseline (documentation task)

**Scope:** documentation only — no application code changed.

**Verified (this session):**
- Project audit completed; findings in `docs/PROJECT_CONTEXT.md` §4 (fresh starter kit; Laravel 13/Fortify/Inertia 3/Wayfinder/shadcn; no domain code).
- Technical proposal read in full (28 pages): `docs/references/Proposed_QC_City_Hall_Solution.pdf` (extracted via pypdf).
- Figwright MCP server verified working: stdio JSON-RPC handshake OK, `@figwright/mcp` v0.7.0; plugin bridge `http://127.0.0.1:3055` reachable. OpenCode tools require **session restart**.
- Original capstone codebase located at `~/Documents/capstone-dss-clone` (external reference; migrations + `Python/algo/` inspected for naming/structure only).

**Files created:**
- `AGENTS.md`, `README.md`
- `docs/`: PROJECT_CONTEXT, SYSTEM_ARCHITECTURE, ROLES_AND_PERMISSIONS, FIGMA_DESIGN_GUIDE, MODULES_AND_FEATURES, DATABASE_DESIGN, ML_AND_DSS_RULES, IMPLEMENTATION_PLAN, IMPLEMENTATION_PROGRESS, AI_HANDOFF
- `opencode.json` (project Figwright MCP config)

**Checks run:** none against application code (documentation task; no code changed).

**Open items created this session:** conflicts C1–C4 in `docs/PROJECT_CONTEXT.md` §3.2; blocked items in `docs/ROLES_AND_PERMISSIONS.md` §5.

## Phase status ledger

| Phase | Status | Evidence |
| --- | --- | --- |
| P0 Env verification | **Complete** | 2026-11-13 entry above: Sail+MySQL verified; Pint/PHPStan/PHPUnit/types/build all green |
| P1 Layout/branding | **Complete** | 2026-11-13 P1 entry above: tokens+Inter mapped; sidebar/topbar/dashboard rebuilt to frame 2024:3; types/build/composer test green; Playwright visual check |
| P2 Auth/roles | Not started | — |
| P3 Registry | Not started | — |
| P4 Data/verification | Not started | — |
| P5 Preprocessing/analytics | Not started | — |
| P6 Forecasting/evaluation | Not started | — |
| P7 DSS/solar | Not started | — |
| P8 Reports/alerts | Not started | — |
| P9 Integration/security | Not started | — |
