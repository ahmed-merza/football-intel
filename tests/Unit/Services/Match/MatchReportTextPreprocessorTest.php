<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Match;

use App\Services\Match\MatchReportTextPreprocessor;
use Tests\TestCase;

class MatchReportTextPreprocessorTest extends TestCase
{
    /**
     * Synthetic minimal AGCFF-shaped report. Has the three load-bearing
     * structural markers (TOC, Team Data, Event Definition) plus realistic
     * "noise" between them that the preprocessor should strip.
     */
    private function sampleReport(): string
    {
        return <<<'TXT'
                                    Match Report

                                AGCFF U20 Arab Gulf Cup - Group B
                                       Group B Round 1

                                    August 29, 2025 7:00 PM
                                        Damac Stadium

                                        1            0
         Iraq U20                                                        Bahrain U20

        53' 13. Flayyih Alsuhaibi

                                Table of Contents

        Overview                                     1
        Average Position                             2
        Shot Details                                10
        Pass Details                                35
        Team Data                                   48
        Player Stats                                49
        Event Definition                            53

        --- LOTS OF NOISE BETWEEN ---

        Average Position : Full Match
        [pitch diagram with player numbers in positions]
        Iraq U20: 12 GK Shuhaib at 32.5m, 23 LB Mohsin at 24.3m...

        Shot Details
        07' 6. Ali Muneam — Right Foot — Shot
        08' 10. Yasir Abboodi — Right Foot — Buildup chain: 23 → 6 → 10
        [many more shot events]

        Pass : Details
        Pass network for Iraq U20 (Full Match)
        [diagram with edges between players]

        Duels
        Aerial duel won by 5. Sajjad Al Zeyadi at 23'
        [list of duels]

        Goalkeeper detail
        Catches: 0
        [keeper events]

        --- END NOISE ---

                                Team Data

        Offensive Stats           Iraq      Bahrain
        Goals                       1            0
        Assists                     1            0
        Shots                      20            7
        Passes                    435          326
        Pass Accuracy            80.7%        74.5%

                                Player Stats
                                Iraq U20

         12   Sajjad Shuhaib           GK     7.8     90'
         23   Yousif Mohsin            LB     6.7     90'
         13   Flayyih Alsuhaibi        LW     7.2     90'      Goals: 1

                                Player Stats
                                Bahrain U20

          1   Abdulla Abdo             GK     6.4     90'
         12   Ali Husain Naser         LWB    7.1     90'
          6   Mohammed Alyaqoob        CB     6.9     90'

                                Event Definition

        Shot Details: The process of making each shot.
        Shots On Target: Any shot attempt that scores or would have scored.
        [glossary continues]
        TXT;
    }

    public function test_keeps_header_drops_middle_keeps_team_data_and_player_stats(): void
    {
        $raw = $this->sampleReport();
        $trimmed = (new MatchReportTextPreprocessor)->trim($raw);

        // Header content (scorer line, score, competition) survives.
        $this->assertStringContainsString('AGCFF U20 Arab Gulf Cup', $trimmed);
        $this->assertStringContainsString('Flayyih Alsuhaibi', $trimmed);
        $this->assertStringContainsString('Iraq U20', $trimmed);
        $this->assertStringContainsString('Bahrain U20', $trimmed);

        // Tail content (Team Data totals + Player Stats per-player) survives.
        $this->assertStringContainsString('Offensive Stats', $trimmed);
        $this->assertStringContainsString('Sajjad Shuhaib', $trimmed);
        $this->assertStringContainsString('Abdulla Abdo', $trimmed);

        // Middle noise is gone.
        $this->assertStringNotContainsString('Average Position : Full Match', $trimmed);
        $this->assertStringNotContainsString('Buildup chain', $trimmed);
        $this->assertStringNotContainsString('Pass network', $trimmed);
        $this->assertStringNotContainsString('Aerial duel won by', $trimmed);

        // Event Definition glossary also dropped.
        $this->assertStringNotContainsString('The process of making each shot', $trimmed);

        // Trimmed output is meaningfully smaller.
        $this->assertLessThan(strlen($raw) * 0.8, strlen($trimmed));
    }

    public function test_inserts_a_marker_so_the_model_sees_intentional_omission(): void
    {
        // Without a delimiter the header and tail would concatenate jarringly
        // — the marker tells Claude "yes, gap is on purpose, don't try to
        // reconstruct anything from the missing section".
        $trimmed = (new MatchReportTextPreprocessor)->trim($this->sampleReport());

        $this->assertStringContainsString(
            'intermediate sections omitted by preprocessor',
            $trimmed,
        );
    }

    public function test_falls_back_to_full_text_when_markers_are_missing(): void
    {
        $arbitrary = "This is some text that\nlooks nothing like a match report.\nLine three.";

        $trimmed = (new MatchReportTextPreprocessor)->trim($arbitrary);

        $this->assertSame($arbitrary, $trimmed);
    }

    public function test_falls_back_when_tail_marker_comes_before_header_marker(): void
    {
        // Pathological: a tiny doc where "Team Data" appears before "Table
        // of Contents" — the trim would produce a backwards substring, so
        // we punt to the full text instead.
        $weird = "Team Data on page 1\nTable of Contents on page 2";

        $trimmed = (new MatchReportTextPreprocessor)->trim($weird);

        $this->assertSame($weird, $trimmed);
    }

    public function test_handles_missing_event_definition_marker_gracefully(): void
    {
        // Some reports might lack the trailing glossary. Tail should still
        // be captured from "Team Data" to end-of-string.
        $report = $this->sampleReport();
        $reportWithoutGlossary = str_replace(
            ['Event Definition', 'The process of making each shot.'],
            ['<<glossary removed>>', ''],
            $report,
        );

        $trimmed = (new MatchReportTextPreprocessor)->trim($reportWithoutGlossary);

        $this->assertStringContainsString('Sajjad Shuhaib', $trimmed);
        $this->assertStringContainsString('Abdulla Abdo', $trimmed);
    }
}
