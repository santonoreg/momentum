# Momentum

A small, self-hosted, mobile-first weight tracker with goal milestones, BMI, statistics
and an exercise log that syncs automatically from Apple Health.

Plain PHP 8 + SQLite — no build step, no dependencies to install.

## Features

- **Weight log** – daily entries, edit/delete, trend, 7/30/90-day change
- **Goal & milestones** – progress ring, configurable number of milestones
- **Statistics** (home page) – chart of weight, steps or both with selectable range, weight-change cards, step statistics, BMI gauge and category ranges
- **Exercise** – workouts synced from Apple Health (see below): weekly totals,
  weekly activity chart, list of recent workouts. Daily steps are shown in Statistics.
- **Languages** – Greek and English (auto-detected, switchable in Settings)
- **Layout width** – *narrow*, *wide* or *wider* (Settings → Appearance)

## Requirements

- PHP 8.1+ with the `pdo_sqlite` extension
- A web server (Apache/nginx) or PHP's built-in server

## Run locally

```bash
php -S localhost:8000
```

Open <http://localhost:8000>. The SQLite database is created automatically in `data/`
(this folder is git-ignored and blocked from web access by `.htaccess` on Apache;
on nginx add an equivalent `deny` rule for `/data` and `/includes`).

## Apple Health sync

Apple Health can only be read by an app running on the iPhone, so a website cannot pull
the data by itself. Instead, an iPhone app **pushes** your workouts to this app:

1. Make the app reachable from your phone (public HTTPS is strongly recommended).
2. Open **Settings → Apple Health connection** and copy the endpoint URL and access key.
3. In the iOS app [Health Auto Export](https://www.healthexportapp.com/) create an
   automation of type **REST API**:
   - URL: the endpoint from the settings page
   - Method: `POST`, format: `JSON`
   - Header: `Authorization: Bearer <your access key>`
   - Data: **Workouts**, with your preferred schedule
4. Run a manual export to test. Workouts appear in the **Exercise** tab.

Re-sending the same workout is safe: entries are de-duplicated by workout id.

**Steps:** create an automation with data type **Health Metrics** → **Step Count**
(keep the aggregation fixed, e.g. *Daily*). Samples are keyed by their timestamp, so
re-sending is safe, but mixing daily and hourly aggregation would count steps twice.
Steps appear in the **Statistics** chart.

### Endpoint

`POST /api/health.php` — authenticate with `Authorization: Bearer <key>`,
`X-API-Key: <key>` or `?token=<key>`. Returns `{"ok":true,"imported":N}`.

Accepted JSON shapes:

```jsonc
// Health Auto Export
{ "data": { "workouts": [ { "id": "…", "name": "Running",
    "start": "2026-09-24 07:30:00 +0300", "end": "2026-09-24 08:10:00 +0300",
    "duration": 2400,                                   // seconds
    "distance": { "qty": 6.2, "units": "km" },
    "activeEnergyBurned": { "qty": 412, "units": "kcal" },
    "avgHeartRate": { "qty": 151, "units": "bpm" } } ] } }

// Health Auto Export – steps (Health Metrics → Step Count)
{ "data": { "metrics": [ { "name": "step_count", "units": "count",
    "data": [ { "date": "2026-09-24 00:00:00 +0300", "qty": 8234 } ] } ] } }

// Simple format (e.g. from an iOS Shortcuts automation)
[ { "type": "Cycling", "start": "2026-09-20T09:00:00",
    "duration_min": 75, "distance_km": 30, "calories": 540, "avg_hr": 128 } ]
```

Only `start` (or `date`) is required. If `id` is missing, one is derived from the
start time, type and duration.

**Shortcuts alternative:** create a Personal Automation (e.g. daily) with
*Find Health Samples* (Workouts) → *Repeat with each* → build a dictionary with the
fields above → *Get Contents of URL* (`POST`, JSON, `Authorization` header).

## Adding a language

Copy `includes/lang/en.php` to `includes/lang/<code>.php`, translate the values, and add
the code to `VAROS_LANGS` in `includes/i18n.php`. Missing keys fall back to Greek.

## Project layout

```
reports.php    exercise.php  logbook.php  settings.php   pages
actions.php                                                       form actions
api/health.php                                                    Apple Health endpoint
includes/                                                         db, helpers, i18n, layout
includes/lang/                                                    translations
assets/                                                           CSS + JS
data/                                                             SQLite database (git-ignored)
```
