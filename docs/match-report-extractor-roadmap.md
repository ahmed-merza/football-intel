# Match-report extractor — roadmap

How the match-report ingestion pipeline grows from "per-player aggregate stats"
(today) to "every data point in the AGCFF PDF" (eventual end state), without
rewriting what's already in place.

---

## Phase 1 — where we are today (✅ shipped)

```
                 ┌─────────────────────────────────────────────┐
                 │  upload → submission + attachment           │
                 │  ↓                                          │
                 │  ExtractTextFromAttachmentJob               │
                 │     (smalot/pdfparser → attachment.text)    │
                 │  ↓                                          │
                 │  ExtractMatchReportJob                      │
                 │     ├─ MatchReportTextPreprocessor (NEW)    │
                 │     │     trims input to ~10% — keeps the   │
                 │     │     header + Team Data + Player Stats │
                 │     │     sections, drops the formation/    │
                 │     │     shot-detail/pass-network noise    │
                 │     ├─ TextPreprocessor (existing)          │
                 │     └─ AgentRouter → n8n → Claude (sonnet)  │
                 │  ↓                                          │
                 │  MatchReportApplier::applyExtraction        │
                 │     stores raw_extracted on match_reports   │
                 │  ↓                                          │
                 │  admin resolution UI                        │
                 │  ↓                                          │
                 │  MatchReportApplier::applyResolution        │
                 │     writes match_performances + per-player  │
                 │     PlayerRecord rows + record_metrics      │
                 └─────────────────────────────────────────────┘
```

**Captured today**:

- `match_reports`: competition, stage, date, venue, teams, score, scorers, raw payload
- `match_performances`: per-player aggregate stats (rating, mins, goals, assists, passes,
  tackles, duels, recoveries, fouls, cards, GK stats)
- `player_records` (category=`match_performance`): one per resolved Bahrain player —
  feeds the existing timeline + metric charts + future cross-domain agent
- `record_metrics`: the chartable subset of the per-player stats fanned out as
  `match_rating`, `match_pass_accuracy_pct`, etc.

**NOT captured today** (the ~80% of the PDF that the preprocessor drops):

- Event-level shot data (every shot's minute, foot, build-up chain)
- Pass network (which players passed to which, count per edge)
- Average-position over time (per-player coordinates per 15-min interval)
- Open-play crosses (each cross's delivery + receiver)
- Goalkeeper events (per-save type + minute)

The PDF stays attached and on disk — nothing in the doc is irrecoverable. Future
extractors below can read it directly without re-uploading.

---

## Phase 2 — textual event extractors (✅ shipped, partial)

The architectural pattern in this phase: each event-level section of the PDF
gets its own preprocessor + agent + applier + migration. Sections that are
**textually extractable** ship in Phase 2. Sections that are **visually
encoded** (pitch diagrams, networks, heatmaps) defer to Phase 3 vision work.

### Architecture (as built)

```
                  ExtractTextFromAttachmentJob
                            ↓
                  ExtractMatchReportJob  (Phase 1)
                            ↓
                  MatchReportApplier::applyExtraction
                            ↓
                  match_reports.status = 'extracted'
                            ↓
                  Bus::chain dispatches Phase-2 extractors serially
                  (serial, not parallel — keeps n8n machine sane)
                            ↓
   ┌────────────────────────────────────────────────────────────────┐
   │ ExtractMatchShotEventsJob       → match_shot_events            │
   │ ExtractMatchGoalkeeperEventsJob → match_goalkeeper_events      │
   └────────────────────────────────────────────────────────────────┘
```

Each Phase-2 job:
- Reads `attachment.extracted_text` (Phase 1 already populated it)
- Runs a section-specific preprocessor to slice down to the relevant pages
- Routes through `AgentRouter` → `N8nClaudeGateway` with full async-callback
  safety net (same `CallbackPendingException` handling as Phase 1)
- Applies to its own dedicated table — failure is **best-effort**, doesn't
  block the main report's `extracted` status or the admin's player-resolution UI

### What shipped in Phase 2

| Extractor | Section | Table | Status |
|---|---|---|---|
| `MatchShotEventsExtractor` | Shot Details (pages 10–21) | `match_shot_events` | ✅ shipped |
| `MatchGoalkeeperEventsExtractor` | Goalkeeper (pages 44–47) | `match_goalkeeper_events` | ✅ shipped |
| (extended `MatchReportExtractor`) | Match Summary panel (page 1) | new columns on `match_reports` | ✅ shipped — possession % + per-half scores |

### What we deferred from Phase 2 (originally planned, didn't ship)

| Originally planned | Why deferred |
|---|---|
| Position intervals (per-player x/y per 15-min bucket) | Visual only in PDF text — needs vision, not text extraction. See Phase 3. |
| Pass network (player-to-player edge graph) | Visual only — needs vision. |
| Cross events (per-cross deliverer + receiver) | Visual map + a textual ranking table; the per-cross detail isn't reliably textual. Defer. |
| Pass details breakdown (by area / direction / length) | Already in `match_performances.raw_extracted` JSON — needs UI surfacing, not a new extractor. Quick win whenever. |

### Per-extractor anatomy (the template, locked in)

The two shipped extractors use the same skeleton — when the next textual one
needs adding, copy and adapt:

```
database/migrations/
  YYYY_MM_DD_create_match_<section>_events_table.php
                                      # FK to match_reports cascade,
                                      # sequence, minute, team_side,
                                      # jersey_number, reported_name,
                                      # outcome, body_part, raw_extracted JSON

app/Models/
  Match<Section>Event.php             # Eloquent + relations
                                      # outcome constants
                                      # scope: bahrain() etc.

app/Services/Match/
  Match<Section>TextPreprocessor.php  # nthOccurrence() pattern: skip TOC
                                      # entry, find section heading, end at
                                      # next major section
  Match<Section>EventsApplier.php     # idempotent wipe + reinsert,
                                      # team_side map from extractor's
                                      # 'home'/'away' to our 'bahrain'/'opponent'

app/Ai/Agents/
  Match<Section>EventsExtractor.php   # prompt + JSON schema
                                      # provider+model from ai.football_intel

app/Jobs/
  ExtractMatch<Section>EventsJob.php  # AgentRouter → CallbackPendingException →
                                      # applier. failed() hook logs only —
                                      # best-effort.

app/Http/Controllers/
  N8nWebhookController                # new applyTo<Section>Events() arm
                                      # dispatched by KIND_MATCH_<SECTION>_EVENTS

app/Services/Match/
  MatchReportApplier::dispatchPhase2  # add the new job to the Bus::chain

tests/Unit/Services/Match/
  Match<Section>EventsApplierTest.php
  Match<Section>TextPreprocessorTest.php
```

### Player linkage (works the same in Phase 1 + Phase 2)

Phase-2 event rows store `(jersey_number, team_side)` only — no `player_id`.
After admin runs Phase-1's resolution UI on the match, the JOIN
`(match_report_id, team_side, jersey_number)` against `match_performances`
gives every event its player. No backfill, no race condition.

### Cost + time per match (actual, post-trim)

| Path | Calls | Wall-clock | Bills |
|---|---|---|---|
| Phase 1 (post-trim, haiku) | 1 | 2–5 min | Claude Code subscription |
| Phase 2 shot events | 1 | 1–2 min | Claude Code subscription |
| Phase 2 GK events | 1 | <1 min | Claude Code subscription |
| **Total per fully-extracted match** | **3** | **~5 min** | one Claude Code subscription |

---

## Phase 3 — vision-based extractors + remaining textual surfacing (deferred)

### Why Phase 3 exists (the spatial gap)

Roughly **30–40% of the PDF's data is visual** — pitch diagrams, network
graphs, location heatmaps. These can't be extracted from `pdftotext` output;
they need vision on the rendered page images. Specifically:

- Formation diagrams (Overview page)
- Average position per player per 15-min interval (pages 2–7)
- Shot location + buildup path coordinates (pages 10–21)
- Pass network player-to-player edges (pages 27–34)
- Cross / duel / save location maps (pages 42–47)

The decision recorded here (2026-05): **vision is deferred until a coach-side
need surfaces.** For the nutritionist's role the per-player aggregate data
captured in Phase 1 + Phase 2 is sufficient. Spatial data is tactical-team
territory.

### Four ways to add vision (when the time comes)

| Option | Mechanism | Cost / friction |
|---|---|---|
| **A** — Anthropic API direct via laravel/ai | Vision-agent uses `provider='anthropic'`, real API key needed; PDFs rendered locally via `pdftoppm` and sent as image inputs | ~$0.05/match in tokens — pays Anthropic separately from the Claude Code subscription. Cleanest integration with existing AgentRouter. |
| **B** — Extend n8n proxy + Claude Code CLI | Image transfer (HTTP receive node → write to `/tmp` on SSH host), CLI invocation with `@/path/to/image.png` reference, cleanup step | 1–2 days of work; CLI version-dependent on the SSH host; bills the same Claude Code subscription |
| **C** — Ollama vision (`llama3.2-vision:11b`) on LAN | Vision-agent uses `provider='ollama'` against the existing LAN box | Free, local, quality much weaker than Claude vision on dense small-text diagrams |
| **D** — Skip vision entirely | Stay with textual extractors; rebuild any specific spatial need with a different data source | Loses the 30–40% spatial gap; acceptable if those needs don't materialise |

Recommended order if the need surfaces: **A first** (clean architectural fit,
low operational risk, small per-match cost), **C only if budget is hard zero
and quality compromises are acceptable**, **B only if A becomes operationally
unviable**, **D as a graceful "we don't need this" stance**.

### Remaining textual surfacing wins (any time)

These don't need new extractors at all — the data is already on the
`match_reports` / `match_performances` rows, just not exposed in the UI:

- **Pass details breakdown** (per-player passes by area × direction × length)
  already lives in `match_performances.raw_extracted['pass_breakdown']`.
  Expose via the timeline `details` payload + a small render component.
- **Team-shape numbers** (compactness, width, third-line depth — `49.2m`,
  `32.5m`, etc. on the Average Position page) are textually present and
  fit on `match_reports` as a JSON column. ~30 min to add to
  MatchReportExtractor's schema + a migration. Limited interpretability
  without AGCFF documentation but the numbers are real.
- **Half-time + half-by-half summary breakdown** already partly captured
  in Phase 2 option C — could surface more of the page-1 Match Summary
  panel (shots/SOT per half, fouls per half) if the report prints them.

### Phase 3 UI surfaces (after vision)

Once vision-based extractors exist, new UI features unlock:

- **Per-player shot map** — render shot x/y as dots on a pitch SVG
- **Pass network graph** — force-directed layout from edge weights
- **Heatmap timeline** — average position aggregated per 15-min bucket
- **Goal/assist build-up chain replay** — walk buildup chain back through
  pass network

None need schema changes beyond what each vision extractor would write —
they're pure render layers.

---

## What to leave alone

- **The Phase 1 backbone** — `match_reports` + `match_performances` + the
  player-record / metric fanout — is stable. Don't restructure it for Phase 2;
  the new tables are siblings, not replacements.
- **The admin resolution UI** — only resolves the per-player rows. Phase 2
  events get auto-linked by jersey lookup against the already-resolved
  `match_performances`.
- **The async-callback path** — `PendingExtraction` already has the
  `match_report_id` owner column, the webhook already dispatches by `kind`.
  Each Phase 2 extractor just registers its own `kind` constant and a
  corresponding `applyToMatchEvents()` branch in `N8nWebhookController`.

---

## When to start Phase 2

Triggers worth waiting for:

- The coach starts asking "where on the pitch is player X taking shots?"
- The medical team wants distance/sprint proxies from positional data
- The Nutritionist Assistant starts referencing match data in its analyses
  and benefits from event-level grounding (e.g. "midfield work-rate was high
  in the last 15 minutes — see pass-network density")

Until any of those is real, Phase 1's aggregate stats give you 80% of the
analytical value for 20% of the build effort. Don't pre-build Phase 2.
