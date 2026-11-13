# FIGMA_DESIGN_GUIDE.md

**Repo:** `capstone-qc-beneficiary` · Last updated: 2026-11-13

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

**Implementation status:** Design-only / pending implementation. Placeholder page exists at `resources/js/pages/dashboard.tsx`.

## 3. Pending-inspection frames

The design includes sections for these roles. Frame names and node IDs are **not yet documented** — mark all as pending inspection until read via Figwright:

| Section / role | Frames | Status |
| --- | --- | --- |
| Admin | Dashboard (verified above) + unknown others | Partially verified |
| Energy Manager | Unknown | Pending inspection |
| Facilities Personnel | Unknown | Pending inspection |
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

## 7. Change log

| Date | Event |
| --- | --- |
| 2026-11-13 | Figwright MCP server verified (stdio handshake, v0.7.0); plugin bridge reachable; OpenCode project config added; Dashboard frame specs recorded from prior Codex session |
