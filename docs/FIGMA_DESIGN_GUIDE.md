# FIGMA_DESIGN_GUIDE.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-14

## 1. File and tooling

| Item | Value | Status |
| --- | --- | --- |
| Figma file | https://www.figma.com/design/qrM4ARYcW0bu16ooetbkmg/Untitled?node-id=0-1 | Primary UI reference |
| MCP server | Figwright — `npx -y @figwright/mcp@latest` (v0.7.0, protocol 0.1.0) | Verified working 2026-11-13 via stdio handshake |
| OpenCode wiring | Project `opencode.json` (`mcp.figwright`) | Added 2026-11-13; needs **session restart** to expose tools |
| Codex wiring | `~/.codex/config.toml` `[mcp_servers.figwright]` | Pre-existing |
| Official Figma MCP | `https://mcp.figma.com/mcp` | **Do not use** — Starter-plan quota exhausted |
| Plugin bridge | Local Figma Desktop plugin, relay `http://127.0.0.1:3055` | Reachable on 2026-11-13; plugin zip at `~/Downloads/figwright-plugin-v0.7.0.zip` |

**Usage rules:** run Figwright's `ping` before any design read; read ONE selected frame per task with `get_design_context`; never assume a Figma button has a backend; never redesign verified screens without documented reason.

## 2. Verified frames

### 2.1 Dashboard — `HTML EXACT / Admin / Dashboard`

| Property | Value | Status |
| --- | --- | --- |
| Node ID | `2024:3` | Verified (prior Codex session, recorded by user) |
| Canvas | Desktop 1440 × 1203 | Verified |
| Sidebar width | 262px | Verified |
| Topbar height | 70px | Verified |
| Font | Inter | Verified |
| Primary navy | `#173A7A` | Verified |
| Page background | `#F5F7FB` | Verified |

**Content inventory (verified from the frame):**
- QC Energy DSS branding; Admin workspace
- Grouped sidebar navigation; building selector; latest verified period filter
- Four KPI cards; monthly electricity trend; monthly operating routine
- Pilot-building readiness table; notices/status indicators
- `Upload Monthly Bill` button; `Load Synthetic Demo` button

**Initial empty states shown in the mockup (design values only — never hardcode as real statistics):**
- Pilot buildings: 2 · Verified months: 0 · Next forecast: Not issued · Pending verification: 0
- Highrise & Treasury readiness: Insufficient · Trend: "No verified values to chart"

**Implementation status:** **Implemented in P1 (2026-11-13).** Deviations from the mockup are documented in `docs/IMPLEMENTATION_PROGRESS.md` (P1 entry): demo hotspots/badges/role-select excluded, disabled future-module nav items, disabled upload/demo buttons, adapted notice copy, real empty states instead of demo values (no pilot buildings exist until P3).

### 2.2 Admin / Users & Access

| Property | Value | Status |
| --- | --- | --- |
| Node ID | `2024:2179` | Verified via Figwright `get_design_context` (layout + full detail per region, 2026-11-14) |
| Canvas | Desktop 1440 × 980 | Verified |

**Content inventory (verified from the frame):**
- Page header: eyebrow `QC ENERGY INTELLIGENCE • WORKSPACE`, title `Users & Access`, subtitle "Manage five proposed operational roles, least privilege and building assignment."
- 4 role status cards (white, border `#DFE6F0`, radius 12): Admin→Existing, Viewer/Executive→Existing, Energy Manager→Proposed, Facilities/Budget→Proposed (proposal §6.1 Table 3)
- Card "Role-based permission matrix" (radius 13): 7 capability rows × columns Capability / Admin / Energy Manager / Facilities / Budget-Viewer; th bg `#EFF4FB`, row text Inter Regular 12 `#1C2B40`
- Notice (bg `#FFF5E3`, radius 9): title Inter SB 14 `#173A7A`, body Inter 12 `#52647E`

**Implementation status:** **Implemented in P2 (2026-11-14).** Deviations (documented in the P2 progress entry): demo elements excluded (INTERACTIVE DESIGN DEMO badge, PROPOSED ENHANCEMENT badge, SIMULATED ROLE select, Load synthetic demo / Reset demo buttons, sidebar ROLE-BASED DESIGN DEMO sidenote, demo footer); the frame's "Real authentication is not implemented here" demo notice adapted to a real proposed-matrix/authorization/DPO notice. The frame contains **no user CRUD/role-assignment UI** — the page is a role reference; user management UI would need a new frame.

### 2.3 Admin / Buildings & Meters

| Property | Value | Status |
| --- | --- | --- |
| Node ID | `2024:176` | Verified via Figwright `get_design_context` (layout + full detail per region, 2026-11-14) |
| Canvas | Desktop 1440 | Verified |

**Content inventory (verified from the frame):**
- Page header: eyebrow `QC ENERGY INTELLIGENCE • WORKSPACE` (Inter Bold 11, `#2E61A1`, tracked), title `Buildings & Meters` (Inter SemiBold 29, `#1C2B40`), subtitle "Portfolio registry: complex → building → meter/account → monthly records." (Inter 14, `#66758B`)
- Header actions: `Add building` (primary navy) + `Register meter` (white outline)
- Filter row: BUILDING select ("All buildings", 185×40) + LATEST VERIFIED PERIOD ("No verified record")
- 4 metric cards (white, border `#DFE6F0`, radius 12): "Registered pilot buildings" (real count, real note), "Meralco accounts" = **Restricted** / "Masked in UI" (static governance label, by design), "Floor-area intensity" = **Conditional** / "Requires m²", "Operating hours" = **Optional** / "For context"
- Card "Building registry" (radius 13): table columns Building / Code / Floor area / Account reference / Data coverage; th bg `#EFF4FB`, text Inter SemiBold 11 `#173A7A`; cells Inter Regular 12 `#1C2B40`
- Notice (bg `#EFF4FB`, radius 9): title `Building-level governance` (Inter SB 14 `#173A7A`), body "Facilities access is assigned per building; account references are restricted. Additional buildings can be onboarded without changing the hierarchy." (Inter 12 `#52647E`) — real governance copy, kept verbatim

**Implementation status:** **Existing and verified in P3 (2026-11-14).** All meters are listed with masked references (`•••• xxxx`; refs of four or fewer characters completely masked), never the raw reference. Demo elements excluded per §6 policy. **Design gap:** frames `2024:3864` (`new_building_modal`) and `2024:4004` (`new_meter_modal`) contain only the base registry page with demo states — no actual forms. Create/edit forms reuse shadcn Dialog/Input/Label/Select; Admin-only building Actions column, per-meter Edit/Remove controls and removal confirmation are implementation extensions for the planned CRUD requirement, not Figma-verified new designs. A blank replacement reference on meter edit retains the existing value. Management controls hidden for read-only roles; working building filter includes an All buildings reset.

### 2.4 Facilities Personnel / Buildings & Meters

| Property | Value | Status |
| --- | --- | --- |
| Node ID | `2024:7413` | Full-detail Figwright read, 2026-11-14 |
| Canvas | Desktop 1440 × 980 | Verified |
| Reuse maps | component/token/icon maps returned no Figma bindings/matches; existing shadcn controls and CSS theme reused | Verified |
| Motion | Complete subtree coverage, 114 visited nodes, 0 animated nodes | Verified |

**Verified content:** same registry heading, 4 metric cards, five-column table and governance notice as Admin; no Add building/Register meter controls; `Assigned Building Operations` role context; `ASSIGNED BUILDING` select; no latest-period filter. Palette/type/radii match §2.3. The imported frame is flattened rectangles/text (no content auto-layout hierarchy), so the existing responsive page containers are reused rather than reconstructing positioning from coordinates.

**Implementation (Existing and verified):** read-only variant uses the assigned-building label/context, omits management controls and latest-period input, and shares registry cards/table. Real backend assignments filter registry **and dashboard** data/counts. **Documented deviations:** frame's two demo pilots/count 2 replaced by authorized rows/counts; role-context text adapted to remove prototype wording; demo badges/hotspots/sidebar disclaimers/footer omitted. The global sidebar retains existing role-filtered disabled future-module entries rather than introducing a new navigation scheme in P3. Browser checked at 1440px with a temporary Facilities user assigned Highrise only; no Treasury row or management controls. Temporary user removed afterward.

### 2.5 Admin / Data Management — `2024:316` (P4, 2026-11-14)

Full-detail read, 1440×1196, 165 nodes; flattened rectangle/text frame (existing responsive containers reused). Complete motion coverage: 0 animated. Component/token/icon maps returned no Figma bindings/matches. Existing palette, Inter typography and shadcn controls reused. Content: header/template/demo actions; building/latest verified filters; four workflow chips; upload card + safeguards; five-column billing table; source notice/footer. Dropzone `#F7FAFF`, border `#ADBFDD`, radius 12; card radius 13; title 17px SB; body 14px; table 11/12px; shared navy/secondary tokens.

**Existing and verified:** server CSV upload, template download, manual-entry dialog, real authorized/paginated records table, correction/review/history actions and sidebar/dashboard navigation. **Adaptations:** server validation replaces browser-only parser copy; explicit Upload button; missing history warnings support late bills; human review copy avoids claiming unimplemented automated outlier thresholds. Modal designs absent: uses existing shadcn dialog conventions. Demo-only prototype markers excluded; synthetic loader is a separate labeled preview with reset (no record IDs, writes/persistence/official totals). Browser checked on development empty/error states and isolated test-database monthly workflow. No example/mock records persisted in development.

### 2.6 Admin / Verification Queue — `2024:481` (P4, 2026-11-14)

Full-detail read, 1440×980, 126 nodes; no content auto-layout hierarchy, existing responsive shell reused. Component/icon maps no matches; shared file token scan from intake reused. Complete motion inventory: 0 animated. Shared Inter/navy/secondary palette; warning `#FFF5E3`; heading 29px SB, card titles 17px SB, metrics 23px Bold, table headers/cells 11/12px; radii 9/12/13 match earlier screens.

**Content / implementation (Existing and verified):** header with Open uploads action; building/latest-period filters; Draft/Verified/Overdue/Correction-history metric cards; five-column pending verification table; forecast-quality warning/footer. Real metrics/rows replace mock values. Overdue remains `Not assessed / Policy timing TBD` per frame. Review/bill-confirmation, correction and paginated history dialogs extend existing shadcn conventions because the frame has only an empty table and no form details. Different person must verify the latest values; corrections return Draft. Demo badges/role selector/hotspots/disclaimers excluded. Forecast wording adapted to avoid claiming P6 eligibility. 1440px/390px workflow screenshots reviewed; table scrolls internally on mobile.

### 2.7 Admin / Decision Support — `2024:1332` (P7, 2026-11-14)

Full-detail tree + component/token/icon maps (no bindings/matches); complete motion inventory **133 nodes, 0 animated**; reference screenshot reviewed. Flattened rectangle/text content; existing responsive containers reused. Inter 29px heading, 17px card title, 11px table headers/12px cells; navy `#173A7A`, secondary `#EFF4FB`, border `#DFE6F0`, radii 9/12/13. Header, building/latest filters, four metrics (evaluated/high/accepted/solar screening), four-column recommendations table, explanation notice.

**Existing and verified:** scoped/paginated evidence/decision/history/action-summary UI with real empty and policy-unavailable states, Energy Manager review dialog using existing shadcn conventions. Dialog/history/action summary are implementation extensions (no form design in frame). Prototype R7/three-month/high-priority example is not copied as evaluated advice; policy C5 unresolved, production decisions disabled. Prototype settings/action-tracker buttons adapted to an implemented Solar link; independent Action Tracker UI remains P8. Demo badges/role selector/hotspots/footer disclaimers excluded. Browser verified default policy block and synthetic testing-only decisions at 1440px/390px, table internally scrolls without page overflow.

**Other completed P6/P7 inspection checkpoints:** Forecasting `2024:1073`, Model Evaluation `2024:1181`, issued forecast `2024:3230`, Solar & Scenarios `2024:1584` inspected and implemented per their verified progress entries; preserve technical 16-observation guard and empirical-band semantics instead of prototype eligibility/model claims.

### 2.8 Admin / Audit Logs — `2024:2356` (P8, 2026-11-14)

Full-detail tree + component/token/icon maps (no bindings/matches), complete motion **139 nodes, 0 animated**, reference screenshot reviewed. Frame is flattened rectangles/text and a scrolled prototype sidebar; existing responsive shell reused. Inter29px heading/17px title/11px headers/12px cells; navy `#173A7A`, secondary `#EFF4FB`, border `#DFE6F0`, radii9/12/13. Four metrics, three-column activity table and retention notice.

**Existing and verified:** Admin-only stored-event list and lazy before/after detail dialog, event/actor/Manila-date filters, pagination, loading/error/retry/empty states. Filters/details are implementation extensions using existing shadcn conventions (frame contains no forms). Session count → real filtered persisted entry count; Corrections → real filtered count; Downloads → explicitly unimplemented pending Reports; simulated role → actual actor identity (historical role unavailable). Production retention notice retained with accurate attribution/coverage explanation. Prototype badges, role selector, demo/reset/hotspots/footer disclaimers omitted. Desktop1440/mobile390 browser checked; table scrolls internally and empty text wraps outside the horizontal scroll area. No synthetic official events or C5 thresholds added.

### 2.9 Admin / Action Tracker — `2024:1465` (P8, 2026-11-14)

Full-detail tree + component/token/icon maps (no bindings/matches), complete motion **119 nodes, 0 animated**, reference screenshot reviewed. Flattened rectangles/text → existing responsive shell/shadcn containers. Inter29px heading/17px card title/11px headers/12px cells, navy `#173A7A`, secondary `#EFF4FB`, border `#DFE6F0`, radii9/12/13. Header with Review DSS recommendations; four metrics Tracked/Open/Completed/Overdue; five-column source/owner/due/status/next step table; follow-through notice.

**Existing and verified:** accepted action listing, building/status filters, counts, EM-only follow-up/completion dialog and lazy paginated history; terminal completed record, stale-version errors preserve entered text/dialog, scope/read-only states, loading/error/retry and real empty UI. Filters/dialog/history are functional extensions (no forms or update permissions in this empty frame). Working lifecycle/permissions grounded separately in proposal Table13/Table14 step8 and documented in `ROLES_AND_PERMISSIONS.md`; role mockup is not backend authority. Overdue remains **To be configured**, no automatic savings attribution. Prototype badges/simulated role/hotspots/footer disclaimer excluded; empty message now describes accepted-action creation rather than uploads alone. Desktop1440/mobile390 browser verified; table internally scrolls, historical source is labeled rather than presented as current advice.

### 2.10 Admin / Reports — `2024:1870` (P8, 2026-11-15)

Full-detail tree + component/token/icon maps (no matches/bindings); complete motion150/no animation; reference screenshot reviewed. Flattened content reuses responsive energy workspace/shadcn/theme. Inter29/17/11–12px, navy/secondary/border and radii9/12/13 match existing tokens. Building/latest-period controls, four metrics, four-column/four-type catalogue, internal/export notice.

**Existing and verified:** executive PDF + billing/evidence Excel downloads, pending/error/retry-refresh/success and missing/stale/scoped states. Prototype browser print/CSV controls adapted to proposal §10.5 PDF/Excel; redundant header exports consolidated in table. Budget export explicitly unavailable, C5 notice preserved, generated content current-source/role scoped. Prototype badges/simulation/footer omitted. Audit-aware availability copy replaces unverified prototype claims. Real two-page PDF and desktop1440/mobile390 UI reviewed; table scrolls internally, status/error text outside wide table. Facilities use assigned buildings/non-cost projection per server policy, not prototype audience labels.

### 2.11 Admin / Notifications — `2024:2020` (P8, 2026-11-15)

Full-detail tree + component/icon maps (no matches), prior file-wide token map reused; complete motion159/no animation; reference screenshot reviewed. Flattened rectangles/text reuse responsive energy workspace/shadcn/theme, Inter29/17/11–12px/navy-secondary-border/radii9/12/13. Four metrics, three-column current inbox, five-rule table and delivery notice.

**Existing and verified:** real draft/current-forecast notice counts per proposal recipients, personal read state/read filters/pagination, source links, per-row/page-only acknowledgment and stale/error/refresh/success/empty states. Prototype all-read becomes Mark page read so unseen notices stay unread; filters/populated source controls extend the empty frame. Mock recipient cards become counts with accurate recipient captions. Rule table distinguishes current in-app vs unconfigured SMTP/overdue/tolerance and disabled C5 alerts. Notice explicitly calls this a live inbox, not a historical delivery ledger; marking read never verifies a bill. Other roles have empty inbox per Table12. Prototype badges/simulation/sidebar scroll/footer omitted. Desktop1440/mobile390 browser checked, tables scroll internally, empty/error text remains outside the scroll area.

### 2.12 Admin / System Health — `2024:2653` (P8, 2026-11-15)

Full-detail tree/component/token/icon maps (no matches/bindings); complete motion158/no animation; reference screenshot reviewed. Flattened frame reuses energy workspace/shadcn/theme, Inter29/17/11–12px/navy-secondary-border/radii9/12/13. Four infrastructure cards, three-column deployment readiness and public-hosting notice retained.

**Existing and verified:** Admin-only page, on-demand real local checks and bounded/redacted JSON; loading/error/retry clears old results, source-time display, explicit Unknown/Unverified/Unconfigured states. Demo/reset replaced by Run checks; diagnostic card is a documented functional extension. Versioned model lifecycle copy reflects implemented P6 rather than prototype Proposed. No green production-ready claim. Hosting/DPO/backup/monitoring/firewall/TLS decisions are not inferred from config. Desktop1440/mobile390 browser checked and screenshots reviewed; wide readiness table scrolls internally.

### 2.13 Admin / Settings — `2024:2495` (P8, 2026-11-15)

Full-detail tree/component/icon maps (no matches), prior file token map reused; complete motion158/no animation; reference screenshot reviewed. Flattened two-column planning/governance cards, building/latest-period controls and solar warning reuse responsive containers/shadcn/theme; shared type/palette/radii retained.

**Existing and verified read-only scope:** Admin/EM System Settings page, real building/complete verified source period, five readonly Not configured planning fields and allowlisted technical configuration. Prototype15% threshold/Save demonstration settings/browser-memory controls are not policy and are omitted. PV capacity uses actual kWp screening unit, no saved assumptions. Governance note reflects disabled C5 and required versions/effective dates. Account/scenario links and technical card are implementation extensions; Fortify personal settings preserved separately. Desktop1440/mobile390 read-only/filter/role/link/overflow/JS checks passed. Numeric settings writes remain gated, not implemented.

## 3. Pending-inspection frames

**P5 verified (2026-11-14):** Preprocessing `2024:607`, Energy Analytics `2024:776`, Building Comparison `2024:938`. Preprocessing root appearance-budget fallback was followed by scoped card/text reads; no geometry-derived per-child positioning. Full-detail analytics/comparison and complete motion reads (169/162/135 nodes; none animated); maps no bindings/matches. Shared Inter/29px heading/17px card title/11–12px tables/navy/white/secondary palette and 9/12/13px radii reused. Browser desktop/mobile reviewed.

**Production adaptations:** NASA POWER replaces undecided source label; Process/Sync controls and weather/version/JSON-lineage views extend the preprocessing frame; forecast-history table becomes verified evidence until P6; populated chart follows existing chart conventions; comparison adds common-period selection/ranks without comparing mismatched months. No prototype badges/disclaimers. Isolated demo panel remains separate from source data. Conservative all-registered-meter completeness, source missing-value/staleness and privacy states documented in handoff.

The table tracks verified screens and remaining role sections. Any unread frame stays pending until inspected via Figwright:

| Section / role | Frames | Status |
| --- | --- | --- |
| Admin | Dashboard `2024:3`, Users & Access `2024:2179`, Buildings & Meters `2024:176`, Data Management `2024:316`, Verification Queue `2024:481`, Preprocessing `2024:607`, Analytics `2024:776`, Comparison `2024:938`, Forecasting `2024:1073`/`2024:3230`, Model Evaluation `2024:1181`, Decision Support `2024:1332`, Solar `2024:1584`, Audit Logs `2024:2356`, Action Tracker `2024:1465`, Reports `2024:1870`, Notifications `2024:2020`, System Health `2024:2653`, Settings `2024:2495` verified; tariff-dependent Budget Planning remains gated | Unblocked listed screens verified |
| Energy Manager | Unknown | Pending inspection |
| Facilities Personnel | Buildings & Meters `2024:7413` verified (§2.4); other screens pending | Partially verified |
| Budget Officer | Unknown | Pending inspection |
| Viewer / Executive | Unknown | Pending inspection |

To inspect: open the file in Figma Desktop, select the frame in Layers, keep the Figwright plugin connected, then `get_design_context` on that node. Record the frame name + node ID here immediately.

## 4. Design tokens (verified so far)

| Token | Value | Source |
| --- | --- | --- |
| Primary navy | `#173A7A` | Dashboard frame 2024:3 |
| Page background | `#F5F7FB` | Dashboard frame 2024:3 |
| Font | Inter | Dashboard frame 2024:3 |
| Sidebar width | 262px | Dashboard frame 2024:3 |
| Topbar height | 70px | Dashboard frame 2024:3 |

All other colors/typography/spacing must be extracted per-frame via Figwright — **do not invent tokens**. Tailwind 4 theme config (`resources/css/app.css`) should map these tokens when P1 begins.

## 5. Mapping Figma → existing components (reuse before creating)

| Figma element | Existing component | Note |
| --- | --- | --- |
| 262px grouped sidebar | `resources/js/components/ui/sidebar.tsx` + `app-sidebar.tsx`, `nav-main.tsx` | Starter shell already supports collapsible grouped nav |
| Topbar (70px) | `resources/js/components/app-header.tsx` / `app-sidebar-header.tsx` | Adjust height to design |
| KPI cards | `resources/js/components/ui/card.tsx` | 4-up grid |
| Readiness table | None yet; use plain table or add shadcn `table` | Add via shadcn CLI when needed |
| Buttons/dialogs/forms | `button`, `dialog`, `input`, `select`, `label`, `badge`, `alert` | All present |
| Toasts/notifications | `sonner.tsx` | For upload/verify feedback |

## 6. UI states policy (apply to every screen)

- **Empty:** render real data absence (e.g., "No verified values to chart"), never mockup numbers.
- **Loading:** use `skeleton.tsx`/`spinner.tsx`.
- **Error:** use `alert-error.tsx` + backend validation messages.
- **Success:** `sonner` toasts + status badges.
- **Responsive:** preserve verified desktop design (1440px) first; degrade gracefully with Tailwind breakpoints.

**P9 accessibility deviation (2026-11-15):** exact prototype secondary text `#66758B` on page `#F5F7FB` measured4.36:1, below normal-text AA. Shared page descriptions/filter labels/footer use existing palette body `#52647E` in light mode, retaining dark overrides/card text. Disabled sidebar text opacity60→75% fixes measured3.97:1. P8 horizontally scrolling tables now have named focusable regions/visible focus; keyboard ArrowRight verified. Axe WCAG2A/AA+2.1AA,6 P8 pages at1440/390, returned0 violations after these targeted fixes. Inconclusive contrast/empty-table items require manual review; broader P1–P7/populated/dialog/error coverage is not claimed. These are documented accessibility adaptations, not Figma-verified new colors/layouts.

**P9 populated/dialog continuation:**12 additional scans of populated notices/action/audit and follow-up/history/audit-evidence dialogs at1440/390 passed with0 violations/overflow/JS errors. Explicit close-autofocus callbacks return keyboard focus to opening buttons; shared DialogDescription/action secondary text uses the same existing light body palette with dark overrides. Tab containment/Escape/focus return verified on all3 dialogs. Measurements wait for animations to finish; no visual transitions removed. Total24 scoped scans; core/error/screen-reader and axe inconclusive checks remain manual follow-up.

**P9 expanded local review (supersedes the coverage limit above):** 73 additional core/dialog/error/auth/personal-settings/mobile-navigation state/viewport cases passed; prior24 P8 cases regression-checked due to shared fixes. Light muted token now uses existing `#52647E` consistently after repeated4.36/4.47:1 failures on page/intake/dialog backgrounds; light destructive/error token `#B91C1C` replaces4.44:1 red, and preprocessing badge text `#1B6B59` replaces4.38:1 green. Dark token values retained. These darker red/green values are accessibility adaptations, not Figma-extracted colors. Filter labels, named keyboard-scroll regions, actual chart text alternatives, field/error associations and selected Appearance state added. Shared Dialog/Sheet restore opener focus while honoring existing callbacks; hidden tooltips mount only for collapsed desktop icons so they cannot consume mobile Escape. Scoped inconclusive contrast/empty-table/aria-hidden items have DOM/computed-color/keyboard evidence in `P9_VERIFICATION.md`. Existing geometry/components preserved; human-operated screen-reader and broader acceptance remain pending.

## 7. Change log

| Date | Event |
| --- | --- |
| 2026-11-13 | Figwright MCP server verified (stdio handshake, v0.7.0); plugin bridge reachable; OpenCode project config added; Dashboard frame specs recorded from prior Codex session |
| 2026-11-14 | Admin / Users & Access frame `2024:2179` verified + implemented (P2); file inventory shows per-role frames (`HTML EXACT / <Role> / <Screen>`) available for future inspection |
| 2026-11-14 | Admin / Buildings & Meters frame `2024:176` verified + implemented (P3); modal frames `2024:3864`/`2024:4004` contain no form designs (design gap → shadcn Dialog forms); Facilities frame `2024:7413` and Data Operations frames `2024:316`/`2024:481`/`2024:607` identified, pending read |
| 2026-11-14 | P3 completion audit: Facilities registry `2024:7413` full-detail + reuse maps + complete motion read; read-only role variant implemented and browser-verified. Admin edit/remove controls extend existing shadcn conventions to finish planned CRUD; design gap documented in §2.3 |
| 2026-11-14 | P4: Data Management `2024:316` + Verification Queue `2024:481` inspected/implemented, reuse maps/motion checked; server/parser/gap/manual/review/history/demo adaptations documented above; isolated test-DB browser workflow verified |
