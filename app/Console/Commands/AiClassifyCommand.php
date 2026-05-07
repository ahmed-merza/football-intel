<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Agents\DocumentClassifier;
use App\Services\Ai\AgentRouter;
use Illuminate\Console\Command;

/**
 * Manual / dev-iteration helper. Pipe some extracted text in and watch the
 * classifier pick a category — useful for quickly validating prompt tweaks
 * against the live Ollama (or Claude) endpoint without building a full
 * test fixture.
 *
 * Usage:
 *   cat docs/samples/blood_test.txt | php artisan ai:classify
 *   php artisan ai:classify --text="Ferritin 33 ng/mL, Hb 14.2..."
 */
class AiClassifyCommand extends Command
{
    protected $signature = 'ai:classify
        {--text= : Text to classify (if omitted, reads from STDIN)}';

    protected $description = 'Run the DocumentClassifier against given text. Hits the live provider — not for CI.';

    public function handle(AgentRouter $router): int
    {
        $text = $this->option('text') ?: $this->readStdin();

        if ($text === '' || $text === null) {
            $this->error('No text to classify. Pass --text=... or pipe via STDIN.');

            return self::FAILURE;
        }

        $agent = new DocumentClassifier;

        $this->line("<fg=gray>provider:</> {$agent->provider()}  <fg=gray>model:</> {$agent->model()}");
        $this->line('<fg=gray>input:</> '.mb_substr($text, 0, 140).(mb_strlen($text) > 140 ? '…' : ''));
        $this->newLine();

        /** @var array{category: string, confidence: float, reasoning: string} $result */
        $result = $router->send($agent, $text);

        $this->line("<fg=green>category:</>   <options=bold>{$result['category']}</>");
        $this->line("<fg=green>confidence:</> {$result['confidence']}");
        $this->line("<fg=green>reasoning:</>  {$result['reasoning']}");

        return self::SUCCESS;
    }

    private function readStdin(): ?string
    {
        if (stream_isatty(STDIN)) {
            return null;
        }

        return stream_get_contents(STDIN) ?: null;
    }
}
