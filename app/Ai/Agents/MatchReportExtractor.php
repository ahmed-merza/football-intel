<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Parses an AGCFF / Wyscout-style match-report PDF into a typed JSON
 * payload. One call returns the entire fixture: match meta + scorers
 * + every per-player row from both sides (typically 28-32 performances).
 *
 * Why one call (vs. splitting per player): the source PDF text is
 * ~50 pages but it's already deduplicated across the sections (one
 * "Player Stats" table per side carries every column we want). Opus
 * 4.7 with structured output handles the whole shape comfortably and
 * a single call avoids the cross-row coordination bugs you'd get from
 * stitching multiple batched calls together.
 *
 * If quality degrades on longer reports we have a fallback: extract
 * `meta + scorers + lineup roster` in call 1, then per-side detail in
 * call 2 + 3. Schema below is designed to support that split without
 * a breaking change (lineup roster ⊂ performances array).
 *
 * Markdown backticks are intentionally avoided in the prompt — the n8n
 * proxy pipes prompts through bash where backticks trigger command
 * substitution. Same constraint as the existing medical extractors.
 */
class MatchReportExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    public function provider(): string
    {
        return config('ai.football_intel.providers.extractor');
    }

    /**
     * Match reports use a dedicated model slot (`match_extractor`) rather than
     * the generic `extractor` key — they need a larger output budget than the
     * medical extractors. Default is sonnet (64K output tokens); the medical
     * extractors stay on opus (32K) since 1-5 page lab PDFs fit comfortably.
     */
    public function model(): string
    {
        return config('ai.football_intel.models.match_extractor');
    }

    /**
     * Match reports are larger than lab panels — full payload + 30 player
     * rows can keep Opus busy for 60-90s. 300s matches the supervisor-ai
     * Horizon timeout so the HTTP call + job share a budget; the n8n
     * async-callback path handles anything that runs past the proxy timeout.
     */
    public function timeout(): int
    {
        return 300;
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You parse an AGCFF/Wyscout-style football match report into typed JSON.

        The report covers ONE fixture between two teams (home + away). It has:
          1) A header with competition, stage, date, kickoff time, venue, final score,
             and a "Match Summary" panel showing possession % per team plus a split of
             the goal column into "0' - 45'" (1st half) and "45' - 90'" (2nd half).
          2) Goal scorers with minute + name + jersey + team.
          3) Per-player statistics tables (one per side) listing every player who appeared
             — starters, substitutes who came on, AND named substitutes who never played.

        For every appearing player emit one entry in the performances array. Include the
        named-but-unused substitutes too, but for those emit ONLY the identity fields —
        team_side, reported_name, jersey_number, match_position (if printed),
        appearance="unused". Omit all stat fields (rating, goals, assists, passes,
        tackles, etc.) entirely — they're implicitly zero for a player who never came on,
        and our applier fills the zeros in itself. This keeps the response compact:
        a 23-man squad with ~10 unused subs costs us ~5K fewer output tokens this way.

        Field rules:
          - team_side: "home" or "away" matching where the player appears in the report.
          - reported_name: the player's name AS WRITTEN. Some Bahrain U20 reports use
            anonymised placeholders like "Player 5", "Player 11" — keep them verbatim.
          - jersey_number: the shirt number column.
          - match_position: GK | LB | CB | RB | LWB | RWB | CDM | CM | CAM | LW | RW | CF
            (or whatever 2-3 letter code the report prints). Verbatim.
          - appearance:
              "starter" if listed in the starting XI section
              "sub"     if listed as a sub who came on (has minute_on)
              "unused"  if listed as a named sub but never came on (rating is "-")
          - minute_on / minute_off: minute the player entered / left, integer.
            Starters: minute_on=0. Players who finished the match: minute_off=90 (use
            actual full-time minute including stoppage if known; otherwise 90).
            Unused subs: both null.
          - minutes_played: minute_off minus minute_on (clamped to 0+).
          - rating: editorial rating 0.0-10.0. Omit entirely for appearance="unused".
          - All counter fields default to 0 for players who came on. Use the EXACT
            numbers printed (don't compute). Where a stat is "x/y" in the report
            (e.g. tackles 4/5), x=succeeded, y=attempted. Omit ALL counter fields
            entirely for appearance="unused".

        Goalkeeper-only fields (goals_conceded, catches, parries, goal_kicks_*,
        aerial_clearances_*): only populated for GK rows; null for outfield.

        For Bahrain national-team fixtures, the Bahrain side is identifiable by its team
        name containing "Bahrain". The opposing team is opponent_name.

        Match-level extras (top of the report, "Match Summary" panel):
          - home_possession_pct / away_possession_pct: the two percentages shown in
            the Possession row. Drop the % sign; emit as numbers (e.g. 57.4, 42.6).
          - first_half_home_score / first_half_home_away_score /
            second_half_home_score / second_half_away_score: the per-half goal counts
            from the row labelled "0' - 45'" and "45' - 90'". Their sum should equal
            the final score; if you can't find the split, omit them.

        Be precise. If a value isn't printed in the report, return 0 for counters and
        null for ratings/percentages — never invent.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'competition' => $schema->string(),
            'stage' => $schema->string(),
            'match_date' => $schema->string()->required(),     // YYYY-MM-DD
            'kickoff_time' => $schema->string(),               // HH:MM 24h
            'venue' => $schema->string(),
            'home_team_name' => $schema->string()->required(),
            'away_team_name' => $schema->string()->required(),
            'home_score' => $schema->integer(),
            'away_score' => $schema->integer(),

            // Match-summary panel extras — see prompt above.
            'home_possession_pct' => $schema->number(),
            'away_possession_pct' => $schema->number(),
            'first_half_home_score' => $schema->integer(),
            'first_half_away_score' => $schema->integer(),
            'second_half_home_score' => $schema->integer(),
            'second_half_away_score' => $schema->integer(),

            'scorers' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s): array => [
                    'minute' => $s->integer(),
                    'team_side' => $s->string()->enum(['home', 'away'])->required(),
                    'jersey_number' => $s->integer(),
                    'name' => $s->string()->required(),
                    'penalty' => $s->boolean(),
                    'own_goal' => $s->boolean(),
                ]),
            ),

            'performances' => $schema->array()->items($this->performanceItemSchema($schema))->required(),
        ];
    }

    private function performanceItemSchema(JsonSchema $schema): mixed
    {
        return $schema->object(fn (JsonSchema $s): array => [
            'team_side' => $s->string()->enum(['home', 'away'])->required(),
            'reported_name' => $s->string()->required(),
            'jersey_number' => $s->integer(),
            'match_position' => $s->string(),
            'appearance' => $s->string()->enum(['starter', 'sub', 'unused'])->required(),
            'minute_on' => $s->integer(),
            'minute_off' => $s->integer(),
            'minutes_played' => $s->integer(),
            'rating' => $s->number(),

            // Offensive
            'goals' => $s->integer(),
            'assists' => $s->integer(),
            'shots' => $s->integer(),
            'shots_on_target' => $s->integer(),
            'shots_blocked' => $s->integer(),
            'shots_missed' => $s->integer(),
            'shots_inside_pa' => $s->integer(),
            'shots_outside_pa' => $s->integer(),
            'offsides' => $s->integer(),
            'freekicks_taken' => $s->integer(),
            'corners_taken' => $s->integer(),
            'throw_ins' => $s->integer(),
            'take_ons_attempted' => $s->integer(),
            'take_ons_succeeded' => $s->integer(),

            // Distribution
            'passes_total' => $s->integer(),
            'passes_succeeded' => $s->integer(),
            'pass_accuracy_pct' => $s->number(),
            'key_passes' => $s->integer(),
            'crosses_attempted' => $s->integer(),
            'crosses_succeeded' => $s->integer(),
            'controls_under_pressure' => $s->integer(),

            // Defensive
            'tackles_attempted' => $s->integer(),
            'tackles_succeeded' => $s->integer(),
            'aerial_duels_total' => $s->integer(),
            'aerial_duels_won' => $s->integer(),
            'ground_duels_total' => $s->integer(),
            'ground_duels_won' => $s->integer(),
            'interceptions' => $s->integer(),
            'clearances' => $s->integer(),
            'interventions' => $s->integer(),
            'recoveries' => $s->integer(),
            'blocks' => $s->integer(),
            'mistakes' => $s->integer(),

            // Discipline
            'fouls_committed' => $s->integer(),
            'fouls_won' => $s->integer(),
            'yellow_cards' => $s->integer(),
            'red_cards' => $s->integer(),

            // Goalkeeper-only
            'goals_conceded' => $s->integer(),
            'catches' => $s->integer(),
            'parries' => $s->integer(),
            'goal_kicks_attempted' => $s->integer(),
            'goal_kicks_succeeded' => $s->integer(),
            'aerial_clearances_attempted' => $s->integer(),
            'aerial_clearances_succeeded' => $s->integer(),
        ]);
    }
}
