# Analytics Page — Changes Documentation

## Overview

Comprehensive restructure of the analytics page (`admin-analytics.php`) with a sidebar-driven layout, dual interactive charts, live readings, intelligent polling, and per-room filtering.

---

## Files Modified

| File | Purpose |
|------|---------|
| `pages/admin-home/admin-analytics.php` | Main analytics page — layout, rooms sidebar, summary, live readings, charts, guide |
| `css/admin-analytics.css` | Page-specific styles — grid, rooms expand, live readings, metric info bar, responsive |
| `script/admin-analytics.js` | Chart instances, filter helpers, polling control, live fetch, history table, export |
| `php/handlers/analytics-handler.php` | Backend — fetches rooms (with `room_size`, description, light_status, schedule) |
| `css/admin-faculty-management.css` | Referenced for `.stat-card`, `.search-highlight`, `.dept-member-filter-*` patterns |
| `css/admin-room-manage.css` | Referenced for `.room-info-label`, `.room-info-val`, `.light-dot` |
| `css/faculty-timetable.css` | Referenced for `.timetable-btn`, `.timetable-panel` overlay behavior |
| `css/tooltip.css` + `script/tooltip.js` | Bootstrap tooltip styling for rooms deselect button |
| `css/global.css` | Sticky topbar with gradient fade |

---

## Layout

### Two-Column Grid (`analytics-grid`)
- **Left sidebar** (`280px`): Rooms card + Summary live-card
- **Right main** (`minmax(0, 1fr)`): Live Readings + Charts + History table

### Rooms Sidebar (`.rooms-card`)
- Stat-cards per room showing name + door icon
- **Hover expand**: Description, Dimension (`room_size`: small/medium/large), Faculty, Time/Next class, Lighting (ON/OFF with dot)
- **Click**: Highlights card (`.active-room` with purple border/shadow) and updates `#tabSubheading`
- **Deselect button** (`&times;`): Resets to "All Rooms Selected"
- Data fetched from `analytics-handler.php` (includes `room_size` field)

### Summary Live-Card
- Fields: Total Energy (kWh), Total Occupied (hrs), Avg Voltage, Avg Current, Peak Power, Est. Cost
- **`#sumSessions` removed** — Total Sessions card eliminated
- **Date range label**: Shows single date for Today, range (`Jun 30 – Jul 6, 2026`) for multi-day periods

---

## Live Readings

### Layout
- Split into two groups inside `.live-readings-row`:
  - **Left group** (`#vawGroup`): Voltage, Current, Power
  - **Right group** (`.vaw-group`): Energy (session), Light Status — separated by `border-left`
- On mobile: groups stack vertically, `border-left` becomes `border-top`

### Metric Filter Enlargement
- Selecting a specific metric (Voltage/Current/Power) via Filter by Metrics:
  - Selected card: `.metric-active` (`flex: 3`, `order: -1`, `font-size: 4rem`) — moves leftmost
  - Other cards: `.metric-dimmed` (`flex: 0.6`, `opacity: 0.5`, `font-size: 1.8rem`)
- Selecting "All Metrics": all cards return to equal sizing

### Formula Info Bar (`.metric-info`)
- Below the live readings row
- **Default/All Metrics**: *"Voltage, Current, and Power readings are used to compute Energy (Wh) over time. `Energy (Wh) = Power (W) × Time (h)`"*
- **Voltage**: *`Voltage (V) = Energy (J) ÷ Charge (C)`*
- **Current**: *`Current (A) = Power (W) ÷ Voltage (V)`*
- **Power**: *`Power (W) = Voltage (V) × Current (A)`*

---

## Charts

### Line Graph (`#lineChart`)
- 3 datasets: Voltage (purple), Current (amber), Power (green)
- 3 left y-axes (y, y1, y2) — each paired to one dataset
- Legend click: toggles dataset visibility + hides/shows the corresponding y-axis
- `maintainAspectRatio: false`, `responsive: true`

### Vertical Bar Graph (`#barChart`)
- 3 clustered datasets: Voltage (purple), Current (amber), Power (green)
- Single shared y-axis
- Legend click: toggles dataset visibility

### Syncing
- Legend clicks in both charts call `syncVawFromLegend()` to update V,A,W card states
- When all 3 datasets are hidden in **both** charts → all V,A,W cards become `.metric-active`
- Filter by Metrics buttons are cleared when all legends are hidden

---

## Filtering

### Filter by Period
- Options: Today (1 day), Last 7, 14, 30 days
- **Today default**: Charts show 24 hourly data points (`00:00`–`23:00`)
- **Today history**: 288 rows at 5-minute intervals with Time/Energy/Voltage/Current/Power columns
- Multi-day: history table shows daily summaries (Sessions, Occupied Time, Energy)

### Filter by Metrics
- Options: All Metrics, Voltage, Current, Power
- Affects: both charts (dataset visibility + y-axis toggling), V,A,W card sizing, formula info bar, metric label in chart headers

### Rooms Selection
- `getCid()` returns selected room ID from hidden `#roomSelect`
- API calls include `classroom_id` parameter
- `#tabSubheading` updates to show selected room name or "All Rooms Selected"

---

## Polling Control

### Intervals
- **Live readings** (`fetchLive`): every 3 seconds
- **Data refresh** (`onControlChange`): every 30 seconds

### Pause/Resume Logic (`checkPolling()`)
- Polling **pauses** when any filter is active:
  - Specific metric selected (not "All Metrics")
  - Period other than Today
  - Any chart dataset hidden via legend
  - 2+ rooms selected
- Polling **continues** when:
  - Exactly 1 room selected with no other filters (polls data for that room)
  - All filters cleared (polls data for all rooms)
- `checkPolling()` called from: `setMetric()`, `setPeriod()`, `syncVawFromLegend()`, `deselectRoom()`, room click handler

### Loading State
- `setLoading()` now scoped to `.summary-column .live-stat-val` only — live reading values are never blanked

---

## Analytics Guide Panel

Hover-triggered overlay (`.timetable-panel`) with detailed explanations of:
- Live Readings
- Rooms Sidebar (click/hover)
- Charts (legend toggles + y-axis sync)
- Filter by Period (Today hourly + 5-min history)
- Filter by Metrics (card enlargement + formula)
- Formula Bar
- Polling behavior

---

## Data Flow

```
admin-analytics.php
  └── analytics-handler.php (fetches rooms + schedule + lighting)
  └── admin-analytics.js
        ├── onControlChange()
        │     └── fetch(api/analytics.php?range=&classroom_id=)
        │           ├── renderSummaryCards(data.summary)
        │           ├── renderHistoryTable(data.daily, data.summary, range)
        │           ├── renderEnergyChart(chartLabels)
        │           └── updateLineData(chartLabels)
        └── fetchLive()
              └── fetch(api/live_endpoint?classroom_id=)
                    └── Updates liveVoltage, liveCurrent, livePower, liveEnergy, liveStatus
```

### API Response (`api/analytics.php`)
```json
{
  "summary": {
    "total_energy_kwh": 12.3456,
    "total_minutes": 360,
    "total_sessions": 5,
    "avg_voltage": 220.5,
    "avg_current": 2.345,
    "peak_power": 450.2,
    "total_cost": 45.67
  },
  "daily": [
    { "label": "Jul 1", "energy_kwh": 1.2, "sessions": 2, "occupied_min": 60 },
    ...
  ],
  "heatmap": [...],
  "triggers": [...],
  "per_room": {...},
  "sessions": [...],
  "active_session": {...}
}
```

---

## CSS Key Selectors

| Selector | Purpose |
|----------|---------|
| `.analytics-grid` | Two-column layout: `280px minmax(0, 1fr)` |
| `.rooms-card` | Left sidebar room list |
| `.stat-card.active-room` | Selected room highlight |
| `.room-expand` | Hover-revealed room details |
| `.room-expand-row` | Row within expand section |
| `.live-card` | Container for summary/live readings |
| `.live-readings-row` | Flex row for live stat cards |
| `.live-readings-group` | Sub-group within readings row |
| `.vaw-group` | Right group with `border-left` divider |
| `.live-stat-card` | Individual stat card with `border-top` accent |
| `.metric-active` | Enlarged stat card (`flex: 3`, `order: -1`) |
| `.metric-dimmed` | Shrunken stat card (`flex: 0.6`, `opacity: 0.5`) |
| `.metric-info` | Formula info bar below live readings |
| `.chart-grid` | 2-column chart grid |
| `.chart-wrapper` | Flex-grows to fill card height |
| `.summary-column` | Vertical stack of summary stat cards |
| `.summary-label` | Date range / metric label display |

---

## JS Key Functions

| Function | Location | Purpose |
|----------|----------|---------|
| `onControlChange()` | `line 405` | Fetches analytics data by period/room, updates charts + summary + history |
| `fetchLive()` | `line 207` | Polls live PZEM readings every 3s |
| `setMetric(el, metric)` | `line 310` | Toggles metric filter across charts + V,A,W cards + formula bar |
| `setPeriod(el, days)` | `line 304` | Sets period filter, triggers `onControlChange` |
| `checkPolling()` | `line 278` | Evaluates all filters, pauses/resumes intervals |
| `pausePolling()` | `line 271` | Clears both intervals |
| `resumePolling()` | `line 275` | Restarts both intervals |
| `syncVawFromLegend()` | `line 311` | Updates V,A,W cards when legend items are toggled |
| `syncMetricLabel(chart, id)` | `line 317` | Updates metric label above charts |
| `renderSummaryCards(s)` | `line 479` | Populates summary stat values |
| `renderEnergyChart(labels)` | `line 480` | Renders bar chart with 3 datasets |
| `updateLineData(labels)` | `line 481` | Updates line chart data + labels |
| `renderHistoryTable(daily, summary, range)` | `line 505` | Renders history table (5-min or daily) |
| `getCid()` | `line 202` | Returns selected classroom ID |
| `deselectRoom()` | `admin-analytics.php:390` | Clears room selection |
| `exportCSV()` | `line 600` | Exports history table to CSV |
| `exportPDF()` | `line 611` | Triggers window.print() |
