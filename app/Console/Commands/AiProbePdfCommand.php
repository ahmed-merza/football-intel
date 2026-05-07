<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Agents\BloodTestExtractor;
use App\Ai\Agents\DocumentClassifier;
use App\Ai\Agents\InBodyExtractor;
use App\Ai\Agents\NutritionPlanExtractor;
use App\Models\RecordCategory;
use App\Services\Ai\AgentRouter;
use App\Services\Ai\CallbackPendingException;
use App\Services\Ai\TextPreprocessor;
use Illuminate\Console\Command;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Manual / dev-iteration helper. Walks a single PDF through the same
 * stages the queue chain runs (text extraction → classifier → category-
 * specific extractor) and prints a structured report. Hits the live
 * provider — not for CI; useful for verifying real-world PDFs against
 * extractor prompts before they go through the queue.
 *
 * Usage:
 *   php artisan ai:probe-pdf "/path/to/blood_test.pdf"
 *   php artisan ai:probe-pdf --no-extract "/path/to/coach_note.pdf"
 */
class AiProbePdfCommand extends Command
{
    protected $signature = 'ai:probe-pdf
        {path : Absolute path to the PDF}
        {--no-extract : Stop after classification, do not run the structured extractor}
        {--max-text-preview=400 : Characters of extracted text to show in the report}';

    protected $description = 'Run a PDF through the live extraction + classification + extractor chain and print the result. Hits the live provider — not for CI.';

    public function handle(PdfParser $parser, AgentRouter $router, TextPreprocessor $preprocessor): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $this->line('<fg=cyan;options=bold>'.basename($path).'</>');
        $this->line('<fg=gray>'.$path.'</>');
        $this->newLine();

        // Stage 1 — text extraction
        try {
            $document = $parser->parseFile($path);
            $text = trim($document->getText());
            $pageCount = count($document->getPages());
        } catch (Throwable $e) {
            $this->error('Text extraction failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($text === '') {
            $this->error('PDF has no text layer (would need OCR — not yet shipped).');

            return self::FAILURE;
        }

        $charCount = mb_strlen($text);
        $this->line("<fg=green>1. text:</>     {$charCount} chars across {$pageCount} page(s)");
        $preview = (int) $this->option('max-text-preview');
        $this->line('<fg=gray>'.mb_substr($text, 0, $preview).($charCount > $preview ? '…' : '').'</>');
        $this->newLine();

        // Stage 2 — classification
        $classifierAgent = new DocumentClassifier;
        $classifierInput = $preprocessor->forClassifier($text);
        $this->line("<fg=green>2. classifier:</> provider={$classifierAgent->provider()} model={$classifierAgent->model()}");

        try {
            /** @var array{category: string, confidence: float, reasoning: string} $classification */
            $classification = $router->send($classifierAgent, $classifierInput);
        } catch (Throwable $e) {
            $this->error('Classifier call failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $confidenceColor = $classification['confidence'] >= 0.7 ? 'green' : 'yellow';
        $this->line("   <fg=cyan>category:</>   <options=bold>{$classification['category']}</>");
        $this->line("   <fg={$confidenceColor}>confidence:</> {$classification['confidence']}");
        $this->line('   <fg=gray>reasoning:</>  '.mb_substr($classification['reasoning'], 0, 220));
        $this->newLine();

        if ($this->option('no-extract')) {
            return self::SUCCESS;
        }

        // Stage 3 — structured extraction (only for categories we ship)
        $extractor = match ($classification['category']) {
            RecordCategory::BLOOD_TEST => new BloodTestExtractor,
            RecordCategory::INBODY => new InBodyExtractor,
            RecordCategory::NUTRITION_PLAN => new NutritionPlanExtractor,
            default => null,
        };

        if ($extractor === null) {
            $this->warn("3. extractor:   no extractor for category '{$classification['category']}' yet — would mark pending.");

            return self::SUCCESS;
        }

        $extractorInput = $preprocessor->forExtractor($text);
        $this->line('<fg=green>3. extractor:</>   '.$extractor::class);

        try {
            /** @var array<string, mixed> $payload */
            $payload = $router->send($extractor, $extractorInput, [
                // Tag the pending row with the detected category so
                // a stalled probe is diagnosable from
                // `pending_extractions` alone. No record_id (probe
                // doesn't persist a player_record).
                'kind' => $classification['category'],
            ]);
        } catch (CallbackPendingException $e) {
            $this->warn('   sync timed out at proxy — n8n will deliver via callback.');
            $this->line('   <fg=cyan>correlation_id:</> '.$e->correlationId);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Extractor call failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // Pretty print top-level scalars + first 5 metric/lab rows so the
        // report fits on screen even for big lab panels.
        foreach ($payload as $key => $value) {
            if (in_array($key, ['labs', 'metrics', 'meals', 'supplements', 'segmental'], true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $this->line("   <fg=cyan>{$key}:</> ".($value ?? '<null>'));
            }
        }

        $rows = $payload['labs'] ?? $payload['metrics'] ?? null;
        if (is_array($rows)) {
            $count = count($rows);
            $this->line("   <fg=cyan>rows:</> {$count}");
            foreach (array_slice($rows, 0, 8) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $this->line(sprintf(
                    '     - %-30s key=%-22s value=%s %s flag=%s',
                    mb_substr((string) ($row['name'] ?? ''), 0, 30),
                    (string) ($row['key'] ?? ''),
                    (string) ($row['value'] ?? ''),
                    (string) ($row['unit'] ?? ''),
                    (string) ($row['flag'] ?? ''),
                ));
            }
            if ($count > 8) {
                $this->line('     <fg=gray>… '.($count - 8).' more</>');
            }
        }

        if (is_array($payload['meals'] ?? null)) {
            $this->line('   <fg=cyan>meals:</> '.count($payload['meals']));
        }
        if (is_array($payload['supplements'] ?? null)) {
            $this->line('   <fg=cyan>supplements:</> '.count($payload['supplements']));
        }
        if (is_array($payload['segmental'] ?? null)) {
            $this->line('   <fg=cyan>segmental:</> '.json_encode($payload['segmental']));
        }

        return self::SUCCESS;
    }
}
