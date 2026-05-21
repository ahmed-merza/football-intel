<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Services\Match\MatchShotEventsTextPreprocessor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Phase-2 extractor: parses the AGCFF "Shot Details" section into a list
 * of per-shot events with full buildup chains. Runs in parallel with
 * other Phase-2 extractors after the main MatchReportExtractor has
 * populated match_reports.raw_extracted.
 *
 * Receives only the Shot Details section text (sliced by
 * {@see MatchShotEventsTextPreprocessor}), so the
 * total token cost is much smaller than the main extractor — typically
 * 3K input + 6K output for a 20-shot match.
 *
 * Model selection: defaults to haiku (8K output is plenty for ~20 shots
 * × ~250 tokens each). Override via AI_MODEL_MATCH_SHOT_EVENTS.
 */
class MatchShotEventsExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    public function provider(): string
    {
        return config('ai.football_intel.providers.extractor');
    }

    public function model(): string
    {
        return config('ai.football_intel.models.match_shot_events');
    }

    public function timeout(): int
    {
        return 300;
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You parse the "Shot Details" section of an AGCFF/Wyscout football match report.

        The section lists every shot taken in the match. For each shot the report shows:
          - A sequence number (1, 2, 3, ...)
          - The minute (e.g. "07'", "45'+3'", "90'+6'")
          - The taker as "<jersey>. <name>" (e.g. "6. Ali Muneam")
          - The body part used (Right Foot / Left Foot / Header / Other Body Part)
          - The outcome — pinned visually but described in the section header:
              * Goals
              * Shots On Target
              * Blocked Shots / Keeper Rush-Outs
              * Shots / Missed Shot
              * Opponent Own Goals
              * P (Penalty Kicks)
          - A "Shot Details" buildup chain — a sequence of players who handled the
            ball before the shot, each tagged with their action(s) like
            "Buildup Start", "Recoveries", "Successful Take-Ons",
            "Passes Succeeded", "Buildup End", etc.

        Emit ONE entry per shot in the shots array. For each:
          - sequence: the 1-based number printed next to the shot.
          - minute: integer minutes. For stoppage time, sum: "45'+3'" → 48, "90'+6'" → 96.
          - team_side: "home" or "away" matching where the player appears in the source
            (the header tells you which team each subsection covers).
          - jersey_number: integer.
          - reported_name: name AS WRITTEN.
          - body_part: verbatim string from the report.
          - outcome: one of:
              "goal"      — Goals + Opponent Own Goals
              "on_target" — Shots On Target
              "blocked"   — Blocked Shots / Keeper Rush-Outs
              "missed"    — Shots / Missed Shot
              "other"     — anything else
          - is_penalty: true if marked "P".
          - is_own_goal: true for Opponent Own Goals (still emit team_side as the
            team that scored the goal, not the one that took the shot).
          - buildup_chain: array of {jersey_number, reported_name, actions[]} entries,
            in the order they appear. actions[] is a list of the "#Tag" labels (strip
            the leading "#", keep multi-word labels intact). If the buildup chain
            isn't listed for a shot, return an empty array.

        Skip shots where you can't confidently identify minute + jersey + outcome.
        Better to miss a shot than to invent one.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'shots' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s): array => [
                    'sequence' => $s->integer()->required(),
                    'minute' => $s->integer()->required(),
                    'seconds' => $s->integer(),
                    'team_side' => $s->string()->enum(['home', 'away'])->required(),
                    'jersey_number' => $s->integer()->required(),
                    'reported_name' => $s->string()->required(),
                    'body_part' => $s->string(),
                    'outcome' => $s->string()->enum([
                        'goal', 'on_target', 'blocked', 'missed', 'other',
                    ])->required(),
                    'is_penalty' => $s->boolean(),
                    'is_own_goal' => $s->boolean(),
                    'buildup_chain' => $s->array()->items(
                        $s->object(fn (JsonSchema $b): array => [
                            'jersey_number' => $b->integer(),
                            'reported_name' => $b->string(),
                            'actions' => $b->array()->items($b->string()),
                        ]),
                    ),
                ]),
            )->required(),
        ];
    }
}
