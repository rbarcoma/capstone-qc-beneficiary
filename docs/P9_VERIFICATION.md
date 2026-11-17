# P9 — Integration and acceptance evidence

**Updated:** 2026-11-15. **Status:** technical integration/security and expanded local accessibility checkpoint Existing and verified; human-operated assistive-technology review/production acceptance remains incomplete. This is evidence from this repository, not proof that City Hall approved deployment or that synthetic fixture metrics describe real buildings.

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
2. **C5:** user-reviewed coherent R1–R7 triggers/units/evidence/thresholds/priorities before evaluator or production decisions. The pending approval matrix is in [`ML_AND_DSS_RULES.md` §5.1](ML_AND_DSS_RULES.md#51-c5-decision-matrix--for-approval-not-approved-policy); §5.2 records the R7/no-forecast storage/workflow mismatch. No values or priorities are approved. Keep `recommendations.policy_fingerprint` null.
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
| C5 evaluator/new recommendation decisions | Coherent reviewed R1–R7 mapping, triggers, valid units, thresholds, evidence requirements and priorities; subsequent implementation/verification of that contract | User with relevant City Hall offices | Blocked-requires approval; generator disabled, source policy null |
| Tariff/budget and numerical planning settings | Tariff source/demand-charge treatment, budget cycle, target ownership/effective dates/version contract; explicit overdue/error-tolerance definitions before corresponding alerts | Budget Officer / Energy Manager; owners confirmed by user | Blocked-requires decisions; no default tariff/threshold or setting writes |
| Real institutional data and pilot coverage | Written authorization for real Meralco use, confirmed Treasury coverage and relevant building metadata; approved data handling/location | User / City Hall; capstone team for coverage, Facilities for metadata | Blocked-requires authorization/confirmation; synthetic tests are not real-data validation |
| Operational permissions and privacy | Office-confirmed permission matrix/encoding-review responsibilities and DPO review of account/personal-data handling | City Hall offices / Energy Manager / DPO | Blocked-requires confirmation/approval |
| Hosting and production security | Approved on-premise/government-cloud deployment choice; evidence for HTTPS, access/firewall restrictions, least-privilege application/DB access, backup and successful restore; ICT/DPO sign-off as applicable | City Hall ICT/DPO with deployment owner nominated by City Hall | Blocked-requires decisions and environment-specific evidence; local health results do not close this gate |
| Email and scheduled operations | Approved delivery/operating choices and owners, SMTP configuration, monitored scheduler/queue and delivery/recovery evidence before those channels are claimed working | User / City Hall operations owner to be confirmed | Proposed/unconfigured; current in-app inbox remains independently verified |
| Forecast model-set clarification (C1) | Explicit decision if a broader model set is wanted; preserve Linear primary and previous-three-month baseline in the meantime | User | Open clarification; no model change authorized |

**Resumption rule:** obtain the missing reviewer findings or gate-specific decisions/evidence first, then resume only the affected task. Approval of a policy or environment is not proof that its code or deployment is implemented/tested; record those steps separately. Do not activate C5 with an arbitrary hash, import real bills, configure production delivery/security, or mark P7/P8/P9 complete merely to remove a pending status. Optional enhancements/design-only role variants remain outside the current planned acceptance task.
