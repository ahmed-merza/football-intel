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

## Phase 2 — multi-extractor expansion (when you want event-level analytics)

Each new extractor is **strictly additive**. It reads its own slice of the
already-attached PDF, writes to its own dedicated table, and runs in parallel
with the others. None of them touches `match_performances` or compete with the
existing Phase-1 flow.

### Architecture

```
                  ExtractTextFromAttachmentJob
                            ↓
                  ExtractMatchReportJob  (Phase 1, unchanged)
                            ↓
                  applyExtraction → match_reports.status = 'extracted'
                            ↓
                  (admin still resolves players here, exactly as today)
                            ↓
              ┌─────────────┴──────────────┐
              │                            │
       Bus::batch dispatches              MatchReportApplier::applyResolution
       Phase-2 extractors in parallel     (Phase 1, unchanged — match_performances)
              ↓
   ┌────────────────────────────────────────────────────────────────┐
   │ ExtractMatchShotEventsJob    → match_shot_events                │
   │ ExtractMatchPassNetworkJob   → match_pass_edges                 │
   │ ExtractMatchPositionsJob     → match_position_intervals         │
   │ ExtractMatchCrossEventsJob   → match_cross_events               │
   │ ExtractMatchGoalkeeperJob    → match_goalkeeper_events          │
   └────────────────────────────────────────────────────────────────┘
              ↓
   Each writes to its own table — independent retry, independent failure
```

### Per-extractor design template

Every new extractor follows the same shape — copy the Phase-1 trio and adapt:

```
app/Ai/Agents/
  ExtractMatchShotEventsAgent.php     # provider/model + JSON schema + prompt

app/Jobs/
  ExtractMatchShotEventsJob.php       # mirrors ExtractMatchReportJob:
                                      #  1. load attachment.extracted_text
                                      #  2. pass through a section-specific
                                      #     trimmer that keeps ONLY the shot
                                      #     detail pages (~pages 10-21)
                                      #  3. AgentRouter→n8n→Claude
                                      #  4. apply via applier service
                                      #  Handles CallbackPendingException the
                                      #  same way; gets its own kind constant
                                      #  on PendingExtraction.

app/Services/Match/
  ExtractMatchShotEventsApplier.php   # writes to match_shot_events table
                                      # links back to match_report_id
                                      # nullable player_id (resolved later or
                                      # via a fuzzy match against the
                                      # already-applied match_performances)

database/migrations/
  YYYY_MM_DD_create_match_shot_events_table.php
                                      # match_report_id, minute, second,
                                      # team_side, player_id (FK NULL),
                                      # reported_name (always), body_part,
                                      # outcome, x, y (optional spatial)
```

### Per-extractor: PDF section ↔ DB table

| Extractor | PDF section (page range) | DB table | Estimated output tokens |
|---|---|---|---|
| `MatchShotEventsExtractor` | "Shot Details" (10–21) | `match_shot_events` | ~6K (20–25 shots × ~250 tok) |
| `MatchPassNetworkExtractor` | "Pass Network" / "Passes : Details" (27–37) | `match_pass_edges` | ~4K (top-N edges) |
| `MatchPositionTimelineExtractor` | "Average Position : 15 Minute Intervals" (4–7) | `match_position_intervals` | ~8K (22 players × 6 intervals × ~60 tok) |
| `MatchCrossEventsExtractor` | "Open play crosses" (42–43) | `match_cross_events` | ~2K |
| `MatchGoalkeeperEventsExtractor` | "Goalkeeper" (44–47) | `match_goalkeeper_events` | ~2K |

**Each fits comfortably under 16K output tokens**, even on haiku. None pushes the
CLI's 32K cap. None requires sonnet/opus unless the report scales considerably
beyond AGCFF U20 fixtures.

### Section-specific text preprocessor

`MatchReportTextPreprocessor` (Phase 1) already isolates the Player Stats tables
by marker-based slicing. Each Phase-2 extractor needs the same treatment for its
own section. Two options:

- **Option A** (simpler): one preprocessor per extractor, each looking for its
  own markers (e.g. `ShotEventsPreprocessor` slices from "Shot Details" to the
  next major section). 5 small classes, very readable.
- **Option B** (DRY): single `MatchReportSectionExtractor` service with a `slice($text, $sectionName)`
  method. Markers live in a registry. Slightly more abstract but easier to
  extend if section names drift.

Recommendation: ship Option A for the first two extractors, refactor to Option B
when the third one shows up.

### Player linkage (the cross-cutting concern)

Most Phase-2 tables reference a player — but the AGCFF report identifies players
by `jersey_number` only at the event level (not always by name). Resolving event
rows to player IDs comes for free if we run extractors **after** Phase 1's
`applyResolution` has run:

```php
$playerId = MatchPerformance::query()
    ->where('match_report_id', $matchReport->id)
    ->where('team_side', $eventTeamSide)
    ->where('jersey_number', $eventJersey)
    ->whereNotNull('player_id')
    ->value('player_id');
```

One indexed query per event. The `(match_report_id, team_side)` index from the
existing migration already covers this; only need a small index on
`(match_report_id, team_side, jersey_number)` if it becomes hot.

### Cost + time per match (rough)

Assuming sonnet at $3/MTok input + $15/MTok output:

| Phase | Calls | Input tokens | Output tokens | Cost per match | Wall-clock |
|---|---|---|---|---|---|
| Phase 1 (today, after trim) | 1 | 5K | 25K | $0.39 | 2 min |
| Phase 2 (all 5 new extractors) | 5 | 3K each = 15K | ~22K total | $0.38 | 2 min (parallel) |
| **Total per fully-extracted match** | **6** | **20K** | **47K** | **$0.77** | **~2 min** |

If sonnet stays slow on the n8n CLI, drop to opus for the smaller Phase-2 calls
(8K-16K output fits opus comfortably; haiku probably too).

---

## Phase 3 — analytics & UI surfaces (later)

Once Phase 2 tables exist, new UI features become straightforward:

- **Per-player shot map** — render `match_shot_events.x, y` as dots on a pitch SVG
- **Pass network graph** — force-directed layout from `match_pass_edges`
- **Heatmap timeline** — `match_position_intervals` aggregated into a heatmap per match
- **Goal/assist build-up chain replay** — walk `match_shot_events.buildup_chain`
  back through `match_pass_edges`

None of these need schema changes once Phase 2's tables are in. They're pure
render layers on top of the same JSON-as-events data.

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
