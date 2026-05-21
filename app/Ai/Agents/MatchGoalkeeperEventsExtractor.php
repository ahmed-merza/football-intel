<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Services\Match\MatchGoalkeeperTextPreprocessor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Phase-2 extractor for the AGCFF "Goalkeeper" section. Reads the
 * per-save event list each keeper has — minute, opponent who took the
 * shot, body part — plus the save outcome bucket.
 *
 * Input is sliced by {@see MatchGoalkeeperTextPreprocessor}; output is
 * typically <2K tokens for a match with 5-10 saves, so haiku is fine.
 */
class MatchGoalkeeperEventsExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    public function provider(): string
    {
        return config('ai.football_intel.providers.extractor');
    }

    public function model(): string
    {
        return config('ai.football_intel.models.match_goalkeeper_events');
    }

    public function timeout(): int
    {
        return 300;
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You parse the "Goalkeeper" section of an AGCFF/Wyscout football match report.

        The section has one subsection per keeper (one per team). Each subsection has:
          - A keeper summary row: jersey, name, minutes played, save rate %, goal kick %
          - A "Details" panel: counts of Catches, Parries, Goals Conceded, Own Goals
          - An "Event List" table — one row per save event with columns:
              * Num. (the shot's sequence in the match)
              * Time (minute, e.g. "57'")
              * Opponent (jersey + name of the shot taker)
              * Body Part (Right Foot / Left Foot / Header)
            Each row is visually colored according to the outcome (catch / parry /
            conceded / own goal) — the legend at the top of the section maps the
            colors. If you can't read the color, infer the outcome from the
            "Details" counts (e.g. if the keeper has 3 parries and the entry is in
            the parries-coloured zone, it's a parry).

        Emit ONE entry per save event in the events array. Fields:
          - sequence: 1-based, the order saves appear FOR THIS KEEPER (not the whole match).
          - minute: integer minutes. "45'+3'" → 48, "90'+6'" → 96.
          - team_side: "home" or "away" — the KEEPER's team.
          - jersey_number, reported_name: the keeper's.
          - opponent_jersey_number, opponent_reported_name: the shot taker's.
          - body_part: verbatim from the report.
          - outcome: one of:
              "catch"     — keeper held the ball
              "parry"     — punched or palmed clear
              "conceded"  — goal allowed
              "own_goal"  — credited as an opponent own goal
              "other"     — anything else
          - is_penalty: true if marked "P" or shown as a penalty kick.

        Skip events you can't identify with confidence — prefer omission to invention.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'events' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s): array => [
                    'sequence' => $s->integer()->required(),
                    'minute' => $s->integer()->required(),
                    'team_side' => $s->string()->enum(['home', 'away'])->required(),
                    'jersey_number' => $s->integer()->required(),
                    'reported_name' => $s->string(),
                    'opponent_jersey_number' => $s->integer(),
                    'opponent_reported_name' => $s->string(),
                    'body_part' => $s->string(),
                    'outcome' => $s->string()->enum([
                        'catch', 'parry', 'conceded', 'own_goal', 'other',
                    ])->required(),
                    'is_penalty' => $s->boolean(),
                ]),
            )->required(),
        ];
    }
}
