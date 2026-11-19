# P9 — Integration and acceptance evidence

**Updated:** 2026-11-19. **Status:** final scoped technical integration/security audit Existing and verified; P9 operational readiness is **Blocked-requires human/City Hall acceptance and deployment evidence**. The repository-wide formatting gate also remains failing. This is repository evidence, not proof that City Hall approved deployment or that synthetic metrics describe real buildings. Dated checkpoints below retain their original results.

## Final integration and operational-readiness audit — 2026-11-19

Read the original 28-page proposal, current code and prior P9/DSS UAT evidence. Completed DSS development and synthetic UAT were retained, not reimplemented or rerun in a browser. The scope was integration boundaries, permissions, privacy, deployment prerequisites and documentation. No UI/Figma, calculation, migration, policy activation, normal-runtime data or Git operation was changed/performed.

### Confirmed defect and bounded fix

`EnsureUserHasRole` correctly rejected unauthorized evaluation/configuration requests but omitted `decision-support.evaluate` and `dss.*` from its existing denial-audit allowlist. A new regression test first failed because `POST decision-support.evaluate` returned403 with an empty audit table. The middleware now records only actor, event and method/named route for those denials. Permission grants, response status, evaluator/approval services and source data are unchanged. Tests check Budget mutation denial, Viewer configuration-page denial, absence of submitted private references from logs and no recommendation/configuration/action writes.

`SecurityBoundaryTest` also checks that every registered domain-controller route retains auth and a valid module mapping, and extends real-CSRF coverage to the evaluator:30 mutation endpoints,60 missing/forged-token requests plus the genuine-token positive control. Personal settings/password-setup are deliberately outside the domain module catalogue and keep their existing authentication/setup rules.

### Audit against proposal and verified implementation

| Area / proposal reference | Existing and verified technical evidence | Remaining gap and classification |
| --- | --- | --- |
| Monthly integration — §11.1, Table14 | `MonthlyCycleIntegrationTest` covers HTTP upload→Draft→independent confirmation→processing→real worker→report/notice→actual/correction retraction. Completed `DSS_UAT.md` covers review→decision→one action per acceptance. Both are included in retained/current test evidence. | Full operating routine is **Blocked-requires approval**: actual bill receipt, office responsibility/timing, automatic scheduling and budget-cycle use are not established by synthetic tests. |
| RBAC/module integration — Table4, §12.2 | Fresh persisted account/access-version checks; route and policy gates; all domain routes mapped to module restrictions. Tests cover all five roles, auxiliary endpoints, account deactivation, last-Admin protection and revocation during forecast/report work. Denial audit omission fixed above. | Office permission matrix, public registration/email-verification enforcement, account deletion/retention and confirmation-window defaults still require owner/DPO decisions. No new grant or auth policy was adopted. |
| Building/cost scope — §6.2/§9.1 | Facilities assigned-building queries/policies, empty assignment behavior, cross-building/run denial, cost/account-reference exclusions, current-source exports and final locked permission check. See access/export/workflow tests and completed DSS UAT. | Real Treasury coverage, building metadata and account/service associations are **Blocked-requires confirmation/authorization**. Existing demo fixtures are not institutional data acceptance. |
| Accessibility/responsiveness | Prior P9:73 expanded cases plus24 P8 cases; M16:18 cases; completed DSS UAT:22 scans/28 screenshots, keyboard checks and130 contrast samples. Their scope and inconclusive checks remain explicitly documented. No frontend change required another browser scan in this audit. | AT-01–AT-07 below remain **Pending human review**. Actual spoken VoiceOver/NVDA behavior, office usability/training and wider browser/theme/zoom acceptance are not certified by axe. |
| Validation, secrets and exports — Table16 | Real CSRF, registration privilege-injection resistance, atomic version/source checks, minimal shared/auth DTOs, restricted account references and diagnostics. PDF remote/PHP/JS disabled; Blade escaped; spreadsheet text written explicitly; downloads INTERNAL/private/no-store and audited before release. | DPO-approved privacy notice/lawful basis/retention, production session/transport/secrets configuration, independent security review and incident/log ownership remain **Blocked-requires approval/evidence**. |
| Runtime/dependencies — §8.1/Table16 | Sail PHP8.5.12 satisfies installed non-dev Composer platform requirements; isolated pinned Python imports/worker tests pass, `pip check` finds no broken requirements. Composer locked audit: no advisories; npm audit:0 vulnerabilities. | These are local, point-in-time checks. Production OS/image patching and Python security-advisory review remain **Existing but unverified / operating evidence pending**; package consistency is not a vulnerability scan. |
| Deployment/recovery — §7.4/§8.3/§12.2 | Source inspection shows a development Sail bind mount, published app/Vite/MySQL ports and no repository-owned production deployment/backup/restore automation. Migrations through000016 are applied locally (`migrate:status`, read only). Health explicitly distinguishes local probes from operational readiness. | Approved hosting target, production web root/image/runtime/proxy, HTTPS redirects/cookies, firewall, least-privilege app/DB credentials, protected secrets, encrypted backups and a successful isolated restore are **Blocked-requires ICT/DPO decisions and environment-specific evidence**. Development Compose must not be represented as a hardened deployment. |
| Scheduling/delivery — Table12/§11.1 | Current-source in-app inbox/read receipts work independently of SMTP; forecasting/weather are explicit requests. `routes/console.php` has no domain schedule. | SMTP/queue/scheduler, overdue/error-tolerance/high-priority alerts remain **Proposed/unconfigured or policy-blocked**, not a broken completed module. No worker/schedule/email was activated. |
| Documentation/handover — §7.4/§15/§16 | This audit reconciles stale M16/current architecture/P7/P9 summaries with implemented code and adds this evidence matrix. Proposal Table20#9 PDF/Excel verification is technically satisfied by real export tests. | Named sponsor/business/technical contacts, role-based user guide/monthly checklist, training attendance, target-specific deployment/recovery runbook and2–3 real pilot cycles with measured success baselines remain **Pending operational handover/acceptance**. |

### Checks executed in this audit

| Check | Result |
| --- | --- |
| Targeted security/monthly-cycle/role/building/user-management/report regressions | **39 passed /769 assertions** |
| `SecurityBoundaryTest` final targeted confirmation | **4 passed /219 assertions** |
| `COMPOSER_PROCESS_TIMEOUT=1200 composer test` | **362 total /359 passed /3 existing optional-2FA skips /5390 assertions**; Pint passed, PHPStan0 errors |
| `composer test:forecast` | **7 passed** |
| `npm run types:check`, `npx vp lint`, `npm run build` | **Passed**; build regenerated Wayfinder. Optional font-fallback advisory is non-blocking. |
| `composer audit --locked --no-interaction`, `npm audit --json` | No Composer advisories /0 npm vulnerabilities |
| `vendor/bin/sail composer check-platform-reqs --no-dev` | All installed runtime requirements passed in Sail |
| Sail `.venv/bin/python -m pip check` | No broken requirements |
| `vendor/bin/sail artisan migrate:status` | All19 migration files through000016 already applied; no migration executed |
| Normal-runtime preservation recheck | All19 monitored table counts/hashes still match the recorded UAT baseline; empty activations/null fingerprint; UAT listener/forwarder remain stopped |
| `npm run check` | **Fails** on the same existing formatting in13 Markdown files and `resources/js/components/user-management.tsx`; broad formatter cleanup was not performed. Consequently the aggregate `composer ci:check` formatting gate is not green. |

### Operational acceptance sequence — not executed

1. Sponsor/EM confirm the data owner, permitted source records, encoding/review responsibilities and pilot scope. Validate Treasury coverage and metadata before real-data acceptance.
2. Nominated reviewers complete AT-01–AT-07 and role-based office walkthroughs; record actual findings and training/handover attendance. Route only concrete defects for fixes.
3. ICT/DPO approve a target and privacy/retention/access policy, including registration, verification, account lifecycle and session defaults. Record responsible/recovery contacts and support/patch ownership.
4. In an approved isolated staging target, prepare locked PHP/Node/Python runtime and assets; verify web root, restricted secrets, debug-off configuration, TLS/proxy/cookie behavior, network/DB least privilege, migration plan and rollback compatibility. Keep recommendation activations empty. Never reset verified data or apply local Sail defaults as production controls.
5. Record encrypted backup scheduling, off-host/access/key ownership and a successful restore into an isolated target, including source/audit integrity and agreed recovery objectives. A passing local System Health probe is insufficient.
6. Obtain release/privacy/office approval before approved real-data import or deployment. Observe2–3 actual monthly cycles and agree success measures (proposal §15). Scheduling/delivery and any operational policy activation require their own approved implementation and verification.

Until those artifacts exist, the technical audit is complete but P9 is **not an accepted production deployment**. Missing institutional/operating evidence is not resolved by adding modules or enabling policies.

## Verified technical checkpoint

| Area | Evidence | Boundary |
| --- | --- | --- |
| Monthly integration | `tests/Feature/MonthlyCycleIntegrationTest.php`,1 test/165 assertions | Synthetic isolated history/weather; no live NASA sync or real Meralco import |
| Intake/review | Actual HTTP CSV upload → Draft, Facilities denied verification, personal acknowledgment leaves Draft, independent EM confirmation | Bill-receipt/real-office review is not simulated approval |
| Preprocessing/forecast |16 complete observations → new retained dataset → real Linear worker → one issued next-eligible run, repeat reuses it | No scheduling/alternate models/promotions |
| Policy/budget boundary | Default-null C5 throughout; no recommendations/decisions/actions created; budget explicitly unavailable | Recommendation acceptance/budget planning steps are blocked, not counted complete |
| Distribution | Budget receives only current forecast notice and evidence XLSX; Viewer gets INTERNAL PDF; preparation audited and counted for Admin | Preparation is not proof of recipient receipt or City Hall distribution |
| Actuals/correction | Verified actual reconciles error; training correction returns Draft, retracts dependent source/error/report/notice, retains run/revision/audit, independent Admin reverify | No automatic energy-savings attribution |
| Backend checks | Final `composer test`:243 total,240 passed,3 existing skips,2718 assertions; Pint/PHPStan passed | Three existing optional-2FA skips retained |
| Python | `composer test:forecast`:7 passed; real health diagnostic covered by Laravel test | No runtime reinstallation or model change |
| Frontend | Types/build/scoped lint passed, Wayfinder regenerated; `git diff --check` passed | Scope is touched/verified files, not a claim of all-repo lint cleanup |
| Dependencies | `composer audit`: no advisories; `npm audit`:0 vulnerabilities | Point-in-time package advisory evidence, not a penetration test |

## System Health / Settings verification

- `SystemHealthTest`:8 tests/91 assertions, including actual isolated Python imports/pin/estimator diagnostic. Admin-only route/Gate/fresh actor, on-demand6/minute read endpoint, no-store, sanitized independent DB/runtime/storage failures; no source/config/job writes. Storage is a permission inspection, not an I/O/restore test. Auth depends on DB; this is not independent outage monitoring.
- `SystemSettingsTest`:5 tests/108 assertions. Admin/EM read-only allowlist/building/latest complete verified period; null policy parameters, no save route, existing Fortify account routes retained. Demo15% and saved PV defaults excluded; arbitrary policy hash cannot claim generation enabled.
- Guarded testing-DB browser scripts: `p8-health-smoke.js`, `p8-settings-smoke.js`; role denials, real diagnostic success/error/retry, read-only fields/filter/account/scenario links,1440px/390px/no overflow/JS errors. Screenshots reviewed.
- All temporary browser containers stopped before the final suite. Dev22 public weather rows,0 billing/datasets/forecasts/recommendations/actions/notification receipts; policy null.

## Scoped accessibility checkpoint

Temporary harness: `/var/folders/w8/rl34qjp5287dp_9_dvvcc2zc0000gn/T/opencode/pw/p9-accessibility.js` with temp-only `@axe-core/playwright` (no application dependency added).

Scanned System Health, System Settings, Reports, Notifications, Audit Logs and Action Tracker at1440px/390px:12 scans, WCAG2A/AA+2.1AA tags. Fixed measured secondary-text contrast and keyboard-inaccessible horizontal scrolling. Final results:0 automated violations, no overflow/JS errors; focus+ArrowRight scrolling succeeded for overflowing named regions.

**Page-only checkpoint boundary:** axe inconclusive color-contrast/empty-table items and broader P1–P7/populated/dialog/error/screen-reader coverage were not resolved by these first12 scans. The follow-up below adds populated/dialog coverage; remaining manual work is still explicit. These scans are not full accessibility certification. Preserve the documented Figma accessibility deviations in `FIGMA_DESIGN_GUIDE.md`.

### Populated/dialog follow-up (same date)

`p9-populated-accessibility.js` adds12 scans: populated notice/action/audit lists and action-follow-up/action-history/audit-evidence dialogs at1440/390. All now have0 automated violations/overflow/JS errors; Tab containment and Escape→originating-button focus return passed for each dialog at both sizes. An actual missing focus-return defect was fixed through explicit trigger refs/autofocus callbacks. Shared dialog description/action secondary text uses the existing accessible body palette. Stable scans await finite animations rather than measuring transient overlay opacity.

The follow-up changed only frontend focus/contrast; types/build/3-file scoped lint passed afterward. The243-test/Python7/dependency checkpoints remain valid and were not repeated. The later guarded populated fixture uses a synthetic accepted-action setup contract restored to null before requests; container stopped, data remains isolated in testing until a future suite refresh. Combined checkpoint:24 scoped P8 scans. Inconclusive contrast/aria-hidden/empty-table results and broader core/error/screen-reader coverage still require manual work; this is not a blanket accessibility certification.

## Remaining gates / next work

**Continuation review (2026-11-15):** no further unblocked application implementation task was identified in the current handoff/phase scope, and no new human-review findings or approval evidence were supplied. The unblocked documentation preparation is completed below. P9 remains **reviewer/production acceptance pending**. Existing passed checks are retained; this documentation-only continuation did not run tests, browser scans, dependency audits or infrastructure probes. Source inspection confirms `config/recommendations.php` still declares a null policy fingerprint; this is not a new runtime/config-cache verification.

1. **Next acceptance work:** reviewer-operated VoiceOver/NVDA (or office-selected assistive technology) walkthrough of navigation, tables/charts, form errors and dialog transitions. Browser accessibility-tree/keyboard/computed-color evidence below is verified, but is not evidence of spoken announcements on a real screen reader. Follow up on any reviewer-reported defect; do not repeat successful automated checkpoints without a change or new concern. Coverage is the listed light-theme states, not every role/browser/theme/state or a blanket WCAG certification.
2. **C5 operational activation:** evaluator development and synthetic UAT are complete (§5.11 of `ML_AND_DSS_RULES.md`; `DSS_UAT.md`). Named operational sources/methods/values/priorities/effective windows and deployment authorization remain pending. R7 is already resolved as a read-time notice. Keep activations empty and the legacy fingerprint null; do not reopen evaluator development to fill an approval gap.
3. **Planning parameters:** tariff source/demand charges/budget cycle, target ownership/effective dates, overdue timings and error-tolerance contract before mutation/alerts. Read-only status pages do not satisfy these decisions.
4. **Delivery/operations:** SMTP and monitored scheduler/queue/backup/restore evidence before email/scheduled workflow claims.
5. **Production acceptance:** authorized real-data use, Treasury coverage, office permission confirmation, hosting/HTTPS/firewall/least-privilege/backup and DPO/ICT sign-off. Do not infer these from tests, config values or Figma.

No production database reset/config changes, real billing import, C5 activation or commit was performed.

## Expanded P9 local review (2026-11-15 continuation)

### New accessibility coverage

All harnesses/artifacts remain in the temporary Playwright project named above; no axe/application dependency was added. The guarded `p9-core-router.php` serves only `APP_ENV=testing` + database `testing`, asserts null C5, disables SSR and selects a temporary nonexistent hot-file path **only in that test process**. The user's `public/hot` and running-development configuration are preserved. Existing synthetic populated fixtures were reused; invalid CSV/manual/weather requests saved no billing data and did not perform a NASA sync.

| Harness / states | Additional cases | Verified result |
| --- | --- | --- |
| `p9-core-accessibility.js`: Dashboard, Users & Access, registry, intake, verification, preprocessing, analytics, comparison, forecasting, evaluation, disabled-policy DSS, scenarios | 12 pages × 1440/390 = 24 | Zero axe WCAG2A/AA+2.1AA violations, page overflow or JS errors after fixes |
| `p9-core-dialogs.js`: add/edit building, register/edit meter, remove-building confirmation, manual entry, verification, correction, correction history, weather range | 10 dialogs × 1440/390 = 20 | Zero violations/overflow/JS errors; Tab containment and Escape→opener return for each |
| `p9-errors-manual.js`: registry validation, blocked removal, invalid CSV, future-month manual entry, injected history failure, reversed weather range, injected health failure | 7 states × 1440/390 = 14 | Zero violations/overflow/JS errors after fixes; errors appear as alerts; history/health failures are browser-injected, other errors use real server validation |
| `p9-auth-accessibility.js` + targeted `p9-settings-recheck.js`: login, registration, forgot/reset-password forms, personal Profile/Security/Appearance | 7 pages × 1440/390 = 14 | Zero violations/overflow; only the four failed Profile/Appearance cases were rescanned after their fix. Reset form uses a synthetic invalid token; no reset email/account deletion was requested |
| `p9-final-boundaries.js`: mobile navigation, 390×844 | 1 | Zero violations; 30 Tab steps stay inside, one Escape closes and restores toggle focus; no hidden tooltip layer |

**73 additional state/viewport cases passed.** The prior 24 P8 cases were regression-checked because shared light tokens/dialog behavior changed (12 page cases in `p9-manual-review.js --remaining`, 12 populated/action/audit cases in the existing harness). They are retained prior coverage, not 24 new features/cases. Previous runtime probes and monthly-cycle/Python/dependency checks were not repeated.

### Verified defects and fixes

- Dashboard/registry filter text was visually adjacent but not an accessible label. Real `Label`/control IDs now name the building selector and latest-period field.
- Core overflow containers lacked a keyboard entry point. Named tables now expose focusable, ring-visible regions through the shared Table; chart/recommendation containers have their own named focusable regions. Actual ArrowRight scrolling passed in 18 overflowing containers across desktop/mobile core pages.
- Muted text failed on page/intake/dialog backgrounds (4.36/4.47:1). The light `--muted-foreground` now consistently uses existing palette `#52647E`; dark token values are retained. Light destructive/error text failed at 4.44:1, so light destructive tokens and shared InputError use `#B91C1C`. Preprocessing badge text changes `#237F6A`→`#1B6B59` after its measured 4.38:1 failure. Profile warning and Appearance inactive text reuse the corrected tokens. These are recorded accessibility deviations from the reference palette, not newly inspected Figma designs.
- Programmatically opened core dialogs and the mobile Sheet lost focus on close. Shared Dialog/Sheet content captures the opener and restores it on close, honoring explicit per-dialog autofocus callbacks. Existing P8 callbacks/fallbacks remain. Hidden sidebar tooltips were still mounted and consumed Escape on mobile: only collapsed desktop icon navigation now mounts them. Long mobile keyboard traversal then closes in one Escape.
- Registry server errors had no live announcement/field association. Each error has an alert ID and its field has `aria-invalid`/`aria-describedby`; an actual required-name failure is exposed as the accessible description. Appearance buttons now expose their selected state with `aria-pressed`.
- Dashboard/analytics/forecast chart accessible names include the actual displayed period/value series and distinguish the issued forecast from observations, rather than hiding numbers behind a generic image name. No new data projection or authorization change.

### Resolution of scoped inconclusive findings

`p9-manual-review.js` records 24 core accessibility-tree snapshots and **178 computed foreground/background contrast samples** after scrolling clipped text into view (all ≥4.5:1), plus two decorative upload-arrow inspections. This covers the scoped partially obscured sidebar/chart labels and dialog descriptions; it is DOM/computed-color inspection, not human screen-reader testing. Repeated source/period values are synthetic.

Radix `aria-hidden-focus` inconclusives were reviewed with accessibility-tree exclusion of the background plus 35 forward and 35 reverse Tab steps inside a representative form dialog at both viewports. All ten core dialogs and retained P8 dialogs also passed containment/return checks. Hidden focus guards do not leak a keyboard stop in these tested states; no axe rule was disabled. Empty-table cases were inspected in Verification Queue, Notifications and Completed Action Tracker: nonempty column headers are exposed, a spanning empty cell matches all five verification columns, and P8 empty `tbody` states have separate descriptive paragraphs. No synthetic empty row is presented as a measured value. This resolves those **scoped** manual items, not every possible runtime state.

At 320×640, eight representative core pages had no page-level horizontal overflow and the Add Building dialog stayed within the viewport (288×576 at x16/y32). Final registry-validation mobile and populated-analytics desktop screenshots were opened/reviewed: `p9-registry-error-mobile.png`, `p9-core-analytics-desktop.png`. This is a narrow reflow check, not a full browser-zoom matrix.

### Additional security evidence / final checks

- New `tests/Feature/SecurityBoundaryTest.php`: **two cases verified in targeted runs**. The CSRF case explicitly disables Laravel's PHPUnit bypass **in memory**, retains the `testing` DB, and rejects missing/forged tokens with 419 on 19 domain mutation routes (38 requests), asserting no domain/audit/job writes. A genuine-token registry request is a positive control; C5 remains null. Final targeted CSRF run: **1 passed / 53 assertions**. The initial positive-control expectation incorrectly assumed a named redirect while the existing controller uses `back()`; the test now supplies the real registry referrer, with no controller change. The registration case passed in the first run: injected Admin role/building assignments/verified timestamp cannot override default Viewer/unverified/no-assignment state, and the resulting account is denied a registry write. Notifications are faked; no email sent.
- Final `npm run build`, `npm run types:check`, scoped lint of all 18 touched TSX files, targeted Pint, `composer types:check` (PHPStan 0 errors), and `git diff --check` passed. The previous full **243 total / 240 passed / 3 skips / 2718 assertions**, Python **7 passed**, integration **1/165**, clean dependency audits remain the retained checkpoints, not a newly run combined suite.
- Temporary `qc-p9-core-check` stopped/auto-removed before PHPUnit. Browser source fixtures and security-test writes were isolated to `testing`; subsequent RefreshDatabase tests refresh only that isolated fixture database. No application backend, migration, Python runtime, production policy or operating configuration was changed.

## Human-review worksheet — prepared, not executed

**Status: Pending human review.** The user/team needs to nominate a reviewer and provide the review environment. Record the actual operating system, browser/version, screen reader/version, viewport or zoom, role and reviewed revision before starting. Use a local or approved review environment with isolated, clearly synthetic fixtures. Earlier testing fixtures may have been refreshed by PHPUnit; absent data means **Not exercised**, not a pass. The presentation-only demo preview is not a substitute for persisted synthetic workflow fixtures. C5 stays disabled throughout; do not enable a test policy to make a blocked decision screen actionable. Credentials and confidential bill/account details do not belong in the review record.

These are expected observations for the existing implementation, not additional completed tests or new City Hall-approved requirements:

| ID | Walkthrough | Expected observation | Result |
| --- | --- | --- | --- |
| AT-01 | Navigate the application using the screen reader and keyboard; open/close the mobile sidebar and a form dialog | Navigation/control names are understandable; focus stays within the open modal and returns to its opener on close; hidden content does not create stray navigation stops | Pending |
| AT-02 | Review Dashboard/registry building filters, read-only latest period, and a populated/empty data table | Controls announce their labels/current state; headers, units, rows and absence messages can be understood; horizontal content is keyboard-reachable | Pending |
| AT-03 | Read populated Energy Analytics and Forecasting charts and their evidence views | Period/value alternatives are usable; verified observations are distinguishable from issued predictions; insufficient/stale evidence is described without invented values | Pending |
| AT-04 | In synthetic testing, submit an empty Add Building form; inspect monthly correction/review/history dialogs and a prepared error state | Required-field errors are announced and associated with the affected field; history/loading/failure states are understandable; confirmation and independent-review wording remain clear | Pending |
| AT-05 | As Energy Manager, review Notifications; as a permitted report role, inspect report availability and any prepared success/error state | Read acknowledgment is distinguishable from bill verification; export availability/progress/errors are understandable; preparation is not presented as confirmed recipient receipt | Pending |
| AT-06 | Review personal Appearance and read-only System Settings; inspect Decision Support with null policy | Selected Appearance state is announced; unconfigured settings and disabled-policy explanations are understandable; no numerical defaults/save capability or actionable new recommendation is inferred | Pending |
| AT-07 | Use prepared role-scoped review accounts, including assigned Facilities and Admin audit access | Reviewers can understand the available workspace and restrictions; do not substitute frontend visibility for the retained server-authorization evidence | Pending |

For each exercised row, record **Pass / Issue / Not exercised**, the actual spoken output/interaction, environment and a sanitized evidence reference. A DOM snapshot is not a substitute for the spoken result. If an issue is found, include route/dialog, role, exact reproduction steps, expected/observed behavior and severity/impact; link the resulting fix and targeted verification. No row above is pre-approved. The review covers only the tested environments/states and does not establish blanket WCAG conformity or deployment approval.

## Approval and operating-evidence register

Office responsibilities below follow the existing handoff/permissions/architecture records; named approvers and operating owners still need confirmation by the user/City Hall. No approval date, acceptance signature or deployment verification has been supplied in this continuation.

| Gate | Required input/evidence to resume | Responsible party / coordination | Current status |
| --- | --- | --- | --- |
| Assistive-technology acceptance | Completed worksheet with actual screen-reader/browser evidence; concrete issues routed for fixes | User/team nominates reviewer | Pending human review |
| C5 operational activation | Named authority, operational sources/methods/values/priorities/effective windows and separately authorized deployment activation | User with relevant City Hall offices | Development/UAT Existing and verified; operational activation Blocked-requires approval; activations empty, legacy fingerprint null |
| Tariff/budget and numerical planning settings | Tariff source/demand-charge treatment, budget cycle, target ownership/effective dates/version contract; explicit overdue/error-tolerance definitions before corresponding alerts | Budget Officer / Energy Manager; owners confirmed by user | Blocked-requires decisions; no default tariff/threshold or setting writes |
| Real institutional data and pilot coverage | Written authorization for real Meralco use, confirmed Treasury coverage and relevant building metadata; approved data handling/location | User / City Hall; capstone team for coverage, Facilities for metadata | Blocked-requires authorization/confirmation; synthetic tests are not real-data validation |
| Operational permissions and privacy | Office-confirmed permission matrix/encoding-review responsibilities and DPO review of account/personal-data handling | City Hall offices / Energy Manager / DPO | Blocked-requires confirmation/approval |
| Hosting and production security | Approved on-premise/government-cloud deployment choice; evidence for HTTPS, access/firewall restrictions, least-privilege application/DB access, backup and successful restore; ICT/DPO sign-off as applicable | City Hall ICT/DPO with deployment owner nominated by City Hall | Blocked-requires decisions and environment-specific evidence; local health results do not close this gate |
| Email and scheduled operations | Approved delivery/operating choices and owners, SMTP configuration, monitored scheduler/queue and delivery/recovery evidence before those channels are claimed working | User / City Hall operations owner to be confirmed | Proposed/unconfigured; current in-app inbox remains independently verified |
| Forecast model-set clarification (C1) | Explicit decision if a broader model set is wanted; preserve Linear primary and previous-three-month baseline in the meantime | User | Open clarification; no model change authorized |

**Resumption rule:** obtain the missing reviewer findings or gate-specific decisions/evidence first, then resume only the affected task. Approval of a policy or environment is not proof that its code or deployment is implemented/tested; record those steps separately. Do not activate C5 with an arbitrary hash, import real bills, configure production delivery/security, or mark P7/P8/P9 complete merely to remove a pending status. Optional enhancements/design-only role variants remain outside the current planned acceptance task.
