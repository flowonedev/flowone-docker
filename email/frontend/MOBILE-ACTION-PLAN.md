# FlowOne Pro -- Mobile View Action Plan

> Comprehensive audit and implementation roadmap for making all views fully mobile-ready on iOS (Capacitor).

---

## Table of Contents

1. [Current State Overview](#current-state-overview)
2. [Established Mobile Conventions](#established-mobile-conventions)
3. [View-by-View Audit Results](#view-by-view-audit-results)
4. [Implementation Phases](#implementation-phases)
5. [Phase 1: Foundation Fixes (All Views)](#phase-1-foundation-fixes-all-views)
6. [Phase 2: Core Views Deep Mobile](#phase-2-core-views-deep-mobile)
7. [Phase 3: CRM Pro Mobile](#phase-3-crm-pro-mobile)
8. [Phase 4: Productivity Tools Mobile](#phase-4-productivity-tools-mobile)
9. [Phase 5: Operations & Infrastructure](#phase-5-operations--infrastructure)
10. [Shared Components Checklist](#shared-components-checklist)
11. [Testing Protocol](#testing-protocol)

---

## Current State Overview

### Views Already Mobile-Ready (fully or mostly working)

| View | Status | Notes |
|------|--------|-------|
| **MailboxView** | PARTIAL | Core email works; needs overflow/padding tweaks |
| **SettingsView** | PARTIAL | Recently fixed; sidebar offset needs 4rem |
| **MyWorkView** | PARTIAL | Recently fixed; detail panel uses 3rem instead of 4rem |
| **MobileBottomNav** | READY | Fullscreen More panel, perspective switcher, all nav items |

### Views Needing Work

| View | Status | Severity |
|------|--------|----------|
| **DriveView** | NOT READY | Root overflow locked; bottom padding insufficient |
| **CrmExecutiveView** | PARTIAL | Overflow + padding issues |
| **CrmPipelineView** | PARTIAL | Overflow + padding; kanban columns need mobile treatment |
| **CrmInvoicesView** | NOT READY | Table layout completely breaks on mobile |
| **CrmDashboardView** | PARTIAL | Tab row overflow; overflow + padding |
| **CrmAutomationView** | PARTIAL | Overflow + padding |
| **CrmSequencesView** | PARTIAL | Overflow + padding |
| **CrmSharingView** | PARTIAL | Tab overflow; overflow + padding |
| **BoardsView** | PARTIAL | Sidebar offset wrong; overflow + padding |
| **FinancialsView** | PARTIAL | Table overflow; small tap targets |
| **MoodBoardView** | PARTIAL | Canvas UX needs rethinking for touch; sidebar fixed width |
| **CalendarView** | PARTIAL | Overflow + padding; tap targets |
| **ChatView** | PARTIAL | Bottom padding only |
| **TimeTrackerView** | PARTIAL | Bottom padding; small tap targets |
| **AutomationHubView** | PARTIAL | Stats grid 4-col on mobile; overflow + padding |
| **WorkflowEditorView** | PARTIAL | Canvas editor needs full mobile rethink |
| **CampaignsView** | PARTIAL | Sidebar always visible; no mobile collapse |
| **ClientsView** | PARTIAL | Overflow + padding |
| **ClientsOverviewView** | PARTIAL | Wrong isMobile source; table overflow |
| **ChatInviteView** | NOT READY | No mobile detection at all (redirect page) |
| **MeetingJoinView** | NOT READY | No mobile detection at all (landing page) |

---

## Established Mobile Conventions

These rules are saved in `.cursor/rules/mobile-layout-spacing.mdc` and must be followed for ALL mobile work.

### 1. 4rem Top Offset Rule
- Every full-screen panel, modal, sidebar, or overlay on mobile **must** account for the header/status bar
- **Prefer `padding-top: 4rem`** over `top: 4rem`
- Use `top: 4rem` only when the element should start below the header

### 2. Scrollable Root on Mobile
```html
<div class="h-[100dvh] flex flex-col"
     :class="isMobile ? 'overflow-y-auto' : 'overflow-hidden'">
```
- Desktop: `overflow-hidden` with nested scroll areas
- Mobile: `overflow-y-auto` on root, content flows naturally

### 3. Bottom Padding for MobileBottomNav
- Content areas need `pb-20` to `pb-24` to clear the fixed bottom nav
- Never use `pb-12` (too short, content hidden behind nav)

### 4. isMobile Detection Pattern
```js
const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 768
}
onMounted(() => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
})
onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})
```

### 5. MobileBottomNav Inclusion
```html
<MobileBottomNav v-if="isMobile" />
```
- Must be a direct child of the root flex column
- Placed after main content, before closing `</div>`

### 6. Touch Target Minimums
- All interactive elements: min 44x44px tap area
- Buttons: min `py-2.5 px-3` or `p-2.5` for icon-only
- Use `active:bg-surface-50` for touch feedback

### 7. Icon Sizes on Mobile
- `.material-symbols-rounded` in `.btn-icon`: 27px
- `.material-symbols-rounded.text-lg`: 1.6rem
- Sidebar icons: 22px

---

## View-by-View Audit Results

### MailboxView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes (conditional on no message open)
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **4rem offset**: Sidebar OK via `main.css`
- **Responsive**: Good (grid hides on mobile)
- **Touch targets**: Some small (Add Account modal buttons)
- **Horizontal overflow**: OK

### DriveView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **4rem offset**: Sidebar OK via `main.css`
- **Responsive**: Good
- **Touch targets**: Sidebar items `py-1.5` too small (~24px)
- **Horizontal overflow**: OK (overflow-x-auto on sheets)

### CrmExecutiveView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: Good (multi-breakpoint grids)
- **Touch targets**: Action buttons `p-1.5` too small
- **Horizontal overflow**: OK

### CrmPipelineView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: `hidden md:flex` for velocity stats
- **Touch targets**: Action buttons `p-1.5` too small
- **Horizontal overflow**: Kanban uses `overflow-x-auto` (OK)
- **Special**: Kanban columns need mobile-friendly widths; drag-and-drop needs touch support

### CrmInvoicesView.vue -- CRITICAL
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: Summary cards OK (`grid-cols-2 md:grid-cols-4`)
- **Touch targets**: Filter buttons too small
- **Horizontal overflow**: CRITICAL -- full invoice table (7+ columns) will overflow
- **Special**: Needs a mobile card layout fallback for the table

### CrmDashboardView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: Good grid breakpoints
- **Touch targets**: Tab buttons `px-3 py-2` borderline
- **Horizontal overflow**: Tab row (6 tabs + 2 buttons) overflows on mobile

### CrmAutomationView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: Limited (`max-w-5xl`)
- **Touch targets**: Rule action buttons `p-1.5` too small
- **Horizontal overflow**: OK

### CrmSequencesView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: Limited
- **Touch targets**: Edit/delete `p-1.5` too small
- **Horizontal overflow**: Steps use `overflow-x-auto` (OK)

### CrmSharingView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: `grid-cols-1 sm:grid-cols-2`
- **Horizontal overflow**: Tab row may overflow

### BoardsView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **4rem offset**: BoardSidebar uses `top-14` (3.5rem) -- NEEDS 4rem
- **Touch targets**: Filter buttons `px-2.5 py-1` too small
- **Horizontal overflow**: Boards table in `overflow-x-auto` (OK)
- **Special**: Kanban board columns need touch-friendly widths; card drag needs touch support

### FinancialsView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Touch targets**: View toggle `p-1.5`, quick filter `px-3 py-1` too small
- **Horizontal overflow**: List view table has no `overflow-x-auto` -- NEEDS FIX

### MoodBoardView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes (hidden in presentation mode)
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: Very limited; sidebar `w-72` fixed
- **Special**: Canvas-based tool -- needs full mobile rethink (pinch-zoom, touch-drag, mobile toolbar)

### CalendarView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **4rem offset**: Sidebar OK via `.sidebar-container` in `main.css`
- **Responsive**: Good view toggle responsive classes
- **Touch targets**: View toggle `px-2 py-1` too small
- **Special**: Month/week views need different mobile layouts; event creation needs mobile-friendly modal

### ChatView.vue
- **isMobile**: Yes (breakpoint 640px)
- **MobileBottomNav**: Yes (hidden when conversation active)
- **Root overflow**: No `overflow-hidden` on root (OK)
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: Sidebar conditional width
- **Touch targets**: Main CTA OK
- **Special**: Message input area, attachment picker need mobile-friendly sizing

### TimeTrackerView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: No `overflow-hidden` on root (OK)
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: Good breakpoints
- **Touch targets**: Period buttons `px-3 py-1.5` too small
- **Special**: Timer display/controls need bigger mobile tap targets

### AutomationHubView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: `grid-cols-1 md:grid-cols-2 xl:grid-cols-3`
- **Touch targets**: Workflow card action buttons `p-1.5` too small
- **Special**: Stats row `grid-cols-4` cramped on mobile -- needs `grid-cols-2 sm:grid-cols-4`

### WorkflowEditorView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: Limited; Execution Log button `right-[340px]` fixed position
- **Touch targets**: Zoom controls `p-1.5` too small
- **Special**: Canvas-based node editor -- needs full mobile rethink (collapsible palette, touch-friendly node manipulation, mobile config panel)

### CampaignsView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Inner flex uses `overflow-hidden`
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: Campaign card media queries for 768px/1280px
- **Touch targets**: Delete button `p-1` too small
- **Special**: Sidebar always visible `w-64` -- needs mobile drawer/collapse behavior

### ClientsView.vue
- **isMobile**: Yes
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: Sidebar conditional width via isMobile
- **Touch targets**: Back button `p-2` too small

### ClientsOverviewView.vue
- **isMobile**: Yes (but uses `themeStore.isMobile` -- WRONG SOURCE, should be local ref)
- **MobileBottomNav**: Yes
- **Root overflow**: Always `overflow-hidden` -- NEEDS FIX
- **Bottom padding**: `pb-12` -- NEEDS `pb-20`
- **Responsive**: Good grid breakpoints
- **Touch targets**: Toolbar buttons `px-3 py-1.5` too small
- **Horizontal overflow**: Table in `overflow-x-auto` but many columns still tight
- **Special**: Needs card layout fallback for client table on mobile

### ChatInviteView.vue
- **isMobile**: No -- NOT READY
- **Notes**: Redirect page; centered card layout. Low priority but should add basic mobile detection for consistency.

### MeetingJoinView.vue
- **isMobile**: No -- NOT READY
- **Notes**: Landing page before meeting join. Low priority but buttons are mobile-friendly (`py-4`).

---

## Implementation Phases

### Phase 1: Foundation Fixes (All Views) -- Estimated: 1-2 sessions
Quick mechanical fixes applied to every view. No layout redesigns.

**For every view listed above, apply:**

1. **Root overflow fix**
   ```html
   :class="isMobile ? 'overflow-y-auto' : 'overflow-hidden'"
   ```

2. **Bottom padding fix**
   - Replace `pb-12` with `pb-20` (or conditional `{ 'pb-20': isMobile }`)

3. **Sidebar/panel 4rem offset fix**
   - Change any `top-14` (3.5rem) to use `padding-top: 4rem` pattern
   - Fix MyWorkDetailPanel from `top: 3rem` to `top: 4rem`
   - Fix SettingsView sidebar from `top-14` to `top-16`
   - Fix BoardsView BoardSidebar from `top-14` to `top-16`

4. **Fix ClientsOverviewView isMobile source**
   - Replace `themeStore.isMobile` with local `isMobile` ref + resize listener

**Files to touch (17 views):**
- `MailboxView.vue` -- overflow + padding
- `DriveView.vue` -- overflow + padding
- `CrmExecutiveView.vue` -- overflow + padding
- `CrmPipelineView.vue` -- overflow + padding
- `CrmInvoicesView.vue` -- overflow + padding
- `CrmDashboardView.vue` -- overflow + padding
- `CrmAutomationView.vue` -- overflow + padding
- `CrmSequencesView.vue` -- overflow + padding
- `CrmSharingView.vue` -- overflow + padding
- `BoardsView.vue` -- overflow + padding + sidebar offset
- `FinancialsView.vue` -- overflow + padding
- `MoodBoardView.vue` -- overflow + padding
- `CalendarView.vue` -- overflow + padding
- `AutomationHubView.vue` -- overflow + padding
- `WorkflowEditorView.vue` -- overflow + padding
- `CampaignsView.vue` -- overflow + padding
- `ClientsView.vue` -- overflow + padding
- `ClientsOverviewView.vue` -- overflow + padding + isMobile fix

---

### Phase 2: Core Views Deep Mobile -- Estimated: 2-3 sessions
Views users interact with most on mobile.

#### 2a. DriveView.vue
- [ ] Fix root overflow and padding (Phase 1)
- [ ] Increase sidebar folder item tap targets (min 44px)
- [ ] Ensure file/folder grid is touch-friendly
- [ ] File action menus: use bottom sheet instead of dropdown on mobile
- [ ] File preview: full-screen on mobile
- [ ] Upload button: prominent floating action button on mobile

#### 2b. CalendarView.vue
- [ ] Fix root overflow and padding (Phase 1)
- [ ] Mobile month view: compact day cells, tap to expand
- [ ] Mobile week view: single column scroll, time slots
- [ ] Event creation: full-screen modal on mobile
- [ ] Increase view toggle and nav button tap targets
- [ ] Quick-add: bottom sheet on mobile

#### 2c. ChatView.vue
- [ ] Fix bottom padding (Phase 1)
- [ ] Message input area: auto-grow, attachment picker as bottom sheet
- [ ] Image/file messages: proper mobile sizing
- [ ] Voice message: full-width record button
- [ ] Conversation list: swipe actions (archive, delete)
- [ ] Video call: responsive video grid

#### 2d. ClientsView.vue / ClientsOverviewView.vue
- [ ] Fix overflow, padding, isMobile source (Phase 1)
- [ ] ClientsOverviewView: card layout fallback for table on mobile
- [ ] ClientsView: increase back button tap target
- [ ] Client detail: full-screen on mobile with back navigation

---

### Phase 3: CRM Pro Mobile -- Estimated: 3-4 sessions
Revenue intelligence views adapted for mobile.

#### 3a. CrmExecutiveView.vue
- [ ] Fix overflow and padding (Phase 1)
- [ ] Summary cards: horizontal scroll strip on mobile (like MyWorkView)
- [ ] Increase action button tap targets
- [ ] Board profit cards: 2-column grid on mobile instead of list

#### 3b. CrmPipelineView.vue
- [ ] Fix overflow and padding (Phase 1)
- [ ] Kanban columns: horizontal scroll with snap points
- [ ] Deal cards: mobile-optimized layout (key info visible)
- [ ] New Deal form: full-screen modal on mobile
- [ ] Quick actions: swipe on deal cards
- [ ] Velocity stats: hidden on mobile (already `hidden md:flex`)

#### 3c. CrmInvoicesView.vue -- CRITICAL REDESIGN
- [ ] Fix overflow and padding (Phase 1)
- [ ] **Create mobile card layout** to replace table:
  - Invoice number + status badge
  - Provider name
  - Due date + amount (bold)
  - Tap to expand/open
- [ ] Summary cards: 2-column grid (already responsive)
- [ ] Filter: bottom sheet on mobile
- [ ] Invoice actions: swipe or long-press context menu

#### 3d. CrmDashboardView.vue
- [ ] Fix overflow and padding (Phase 1)
- [ ] Tab row: horizontal scroll with `overflow-x-auto` on mobile
- [ ] Chart widgets: full-width, stacked vertically
- [ ] Increase tab button tap targets

#### 3e. CrmAutomationView.vue
- [ ] Fix overflow and padding (Phase 1)
- [ ] Increase rule action button tap targets
- [ ] Rule cards: ensure text doesn't truncate poorly on mobile

#### 3f. CrmSequencesView.vue
- [ ] Fix overflow and padding (Phase 1)
- [ ] Increase edit/delete button tap targets
- [ ] Sequence steps: vertical timeline layout on mobile

#### 3g. CrmSharingView.vue
- [ ] Fix overflow and padding (Phase 1)
- [ ] Tab row: horizontal scroll on mobile
- [ ] Share cards: full-width on mobile

---

### Phase 4: Productivity Tools Mobile -- Estimated: 2-3 sessions
Boards, moodboards, time tracker, workflows.

#### 4a. BoardsView.vue
- [ ] Fix overflow, padding, sidebar offset (Phase 1)
- [ ] Board sidebar: mobile drawer with 4rem padding-top
- [ ] Kanban columns: horizontal scroll with snap
- [ ] Card drag-and-drop: touch event support (`@touchstart`, `@touchmove`, `@touchend`)
- [ ] Card quick actions: swipe to reveal
- [ ] Increase filter button tap targets
- [ ] Board table view: card layout fallback on mobile

#### 4b. FinancialsView.vue
- [ ] Fix overflow and padding (Phase 1)
- [ ] List view table: wrap in `overflow-x-auto`
- [ ] Increase view toggle and filter tap targets
- [ ] Summary cards: 2-column on mobile

#### 4c. MoodBoardView.vue -- COMPLEX REDESIGN
- [ ] Fix overflow and padding (Phase 1)
- [ ] Sidebar: collapsible drawer on mobile
- [ ] Canvas:
  - Pinch-to-zoom support
  - Touch-drag for items
  - Mobile floating toolbar (bottom)
  - Item resize handles: larger on mobile
- [ ] Board list: grid or list view switchable on mobile
- [ ] File upload: camera option on mobile

#### 4d. TimeTrackerView.vue
- [ ] Fix bottom padding (Phase 1)
- [ ] Period selector buttons: increase tap targets
- [ ] Timer controls: large, prominent on mobile
- [ ] Time entry list: card layout on mobile
- [ ] Stats grid: 2-column on mobile

#### 4e. AutomationHubView.vue
- [ ] Fix overflow and padding (Phase 1)
- [ ] Stats row: `grid-cols-2 sm:grid-cols-4`
- [ ] Workflow card actions: increase tap targets
- [ ] Create workflow: full-screen modal on mobile

#### 4f. WorkflowEditorView.vue -- COMPLEX REDESIGN
- [ ] Fix overflow and padding (Phase 1)
- [ ] This is a canvas-based node editor; full mobile treatment is complex:
  - Collapsible node palette (bottom sheet)
  - Pinch-to-zoom on canvas
  - Touch-drag for nodes
  - Node config: full-screen panel on mobile
  - Execution Log button: repositioned for mobile
  - Zoom controls: larger tap targets
- [ ] Consider a "read-only" or "simplified" mobile mode initially

---

### Phase 5: Operations & Infrastructure -- Estimated: 1-2 sessions

#### 5a. CampaignsView.vue
- [ ] Fix overflow and padding (Phase 1)
- [ ] Sidebar: mobile drawer behavior (slide-out) instead of always visible
- [ ] Campaign cards: full-width on mobile
- [ ] Delete button: increase tap target
- [ ] Campaign editor/preview: full-screen on mobile

#### 5b. ChatInviteView.vue (Low priority)
- [ ] Add basic isMobile detection
- [ ] Add MobileBottomNav (optional -- redirect page)

#### 5c. MeetingJoinView.vue (Low priority)
- [ ] Add basic isMobile detection
- [ ] Buttons already mobile-friendly
- [ ] Video preview: full-width on mobile

---

## Shared Components Checklist

These shared components are used across multiple views and may need mobile fixes:

| Component | Used In | Mobile Status |
|-----------|---------|---------------|
| `AppHeader.vue` | All views | READY -- logo hidden on mobile, hamburger menu |
| `MobileBottomNav.vue` | All views | READY -- fullscreen More panel |
| `NotificationPanel.vue` | Global | READY -- 4rem padding-top |
| `OnboardingPopup.vue` | Global | READY -- scrollable on mobile |
| `ConfirmModal.vue` | Many views | Check: does it use 4rem offset on mobile? |
| `RichTextEditor.vue` | Settings, Chat | Check: mobile keyboard handling, toolbar |
| `UserAvatar.vue` | Headers, lists | OK -- simple component |
| Sidebar components (Board, CRM, Drive) | Multiple | Need 4rem offset + mobile drawer behavior |
| Table components | CRM, Boards, Clients | Need card layout fallbacks on mobile |
| Dropdown/Popover components | Many views | Should use bottom sheet on mobile |

---

## Testing Protocol

For each view after mobile fixes:

1. **iOS Simulator (iPhone 15 Pro)**
   - Open Xcode > Simulator
   - Run via `npx cap run ios` or Vite dev server
   - Test in both portrait and landscape

2. **Safari Web Inspector**
   - Develop > Simulator > inspect
   - Check for overflow issues, hidden content
   - Verify 4rem top offset on all panels

3. **Functional Checks**
   - [ ] Page loads and is fully visible
   - [ ] Content scrolls to bottom (including below fold)
   - [ ] Bottom nav visible and functional
   - [ ] All buttons/links tappable (44px min)
   - [ ] No horizontal scroll (unless intentional like kanban)
   - [ ] Modals/panels appear below status bar
   - [ ] Sidebar opens/closes properly
   - [ ] Forms are usable with mobile keyboard
   - [ ] Touch feedback on interactive elements

4. **Performance Checks**
   - [ ] Scroll performance smooth (60fps)
   - [ ] No layout shifts on load
   - [ ] Images/charts load within viewport

---

## Priority Order

Based on user impact and usage frequency:

1. **Phase 1** -- Foundation fixes (all views, mechanical)
2. **Phase 2b** -- CalendarView (daily use)
3. **Phase 2a** -- DriveView (frequent use)
4. **Phase 2c** -- ChatView (communication)
5. **Phase 3c** -- CrmInvoicesView (critical table redesign)
6. **Phase 3b** -- CrmPipelineView (sales workflow)
7. **Phase 4a** -- BoardsView (project management)
8. **Phase 3a** -- CrmExecutiveView (overview)
9. **Phase 3d** -- CrmDashboardView (analytics)
10. **Phase 4d** -- TimeTrackerView (time tracking)
11. **Phase 4e** -- AutomationHubView (workflows)
12. **Phase 5a** -- CampaignsView (marketing)
13. **Phase 2d** -- ClientsView (client management)
14. **Phase 4b** -- FinancialsView (accounting)
15. **Phase 4c** -- MoodBoardView (complex canvas)
16. **Phase 4f** -- WorkflowEditorView (complex canvas)
17. **Phase 3e-g** -- CRM Automation/Sequences/Sharing (lower frequency)
18. **Phase 5b-c** -- ChatInvite/MeetingJoin (edge cases)

---

## Notes

- All canvas-based views (MoodBoard, WorkflowEditor) are the most complex and may benefit from a simplified "mobile mode" initially
- Consider a `useMobileLayout` composable to standardize isMobile detection, overflow, and padding across all views
- Dropdowns throughout the app should ideally become bottom sheets on mobile for better UX
- Form inputs across all views should be tested with the iOS keyboard (ensure content doesn't get hidden behind keyboard)
