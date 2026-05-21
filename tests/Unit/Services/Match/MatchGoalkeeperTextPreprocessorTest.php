<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Match;

use App\Services\Match\MatchGoalkeeperTextPreprocessor;
use Tests\TestCase;

class MatchGoalkeeperTextPreprocessorTest extends TestCase
{
    private function sampleReport(): string
    {
        return <<<'TXT'
                                  Match Report
                              AGCFF U20 Arab Gulf Cup
                                       1 - 0

                              Table of Contents

        Overview                                     1
        Shot Details                                10
        Goalkeeper                                  44
        Team Data                                   48
        Event Definition                            53

        --- noise — sections we don't care about ---

                              Shot Details
        Shots... [shot detail rows]

                              Distribution : Passes
        [pass network]

                              Goalkeeper - Saves
                                  Iraq U20

        Num.    Name              Mins   Save Rate  Goal Kick %
        12      Sajjad Shuhaib    90'    100.0%     20.0%

        Event List
         Num.   Time   Opponent              Body Part
        10      57'    Khalid Alkhaldi       Right Foot   [Parry]
        11      70'    Player 11             Right Foot   [Parry]

                              Goalkeeper - Saves
                                Bahrain U20

        Num.    Name              Mins   Save Rate  Goal Kick %
        1       Abdulla Abdo      90'    50.0%      76.5%

        Event List
         Num.   Time   Opponent              Body Part
        21      53'    Flayyih Alsuhaibi     Left Foot    [Conceded]
        22      72'    Yasir Abboodi         Right Foot   [Catch]

                              Team Data
        [team totals]

                              Event Definition
        [glossary]
        TXT;
    }

    public function test_keeps_header_and_goalkeeper_section_drops_rest(): void
    {
        $trimmed = (new MatchGoalkeeperTextPreprocessor)->trim($this->sampleReport());

        // Header survives.
        $this->assertStringContainsString('AGCFF U20 Arab Gulf Cup', $trimmed);

        // GK section content survives.
        $this->assertStringContainsString('Sajjad Shuhaib', $trimmed);
        $this->assertStringContainsString('Abdulla Abdo', $trimmed);
        $this->assertStringContainsString('Khalid Alkhaldi', $trimmed);
        $this->assertStringContainsString('Flayyih Alsuhaibi', $trimmed);

        // Out-of-scope sections dropped.
        $this->assertStringNotContainsString('Shots... [shot detail rows]', $trimmed);
        $this->assertStringNotContainsString('pass network', $trimmed);
        $this->assertStringNotContainsString('Team Data', $trimmed);
        $this->assertStringNotContainsString('glossary', $trimmed);
    }

    public function test_falls_back_to_full_text_when_marker_missing(): void
    {
        $text = 'Nothing structural here.';

        $this->assertSame(
            $text,
            (new MatchGoalkeeperTextPreprocessor)->trim($text),
        );
    }
}
