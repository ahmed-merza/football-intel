<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    // Anthropic for text/vision, VoyageAI for embeddings — see the
    // `football_intel` section below for per-task overrides (each agent
    // picks its own provider + model from env).
    'default' => 'anthropic',
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'voyageai',
    'default_for_reranking' => 'cohere',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        // Embedding calls are idempotent + expensive; cache by default so
        // re-embedding the same chunk on a queued retry doesn't rebill us.
        'embeddings' => [
            'cache' => true,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
            'url' => env('DEEPSEEK_URL', 'https://api.deepseek.com'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
            'url' => env('GROQ_URL', 'https://api.groq.com/openai/v1'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
            'url' => env('MISTRAL_URL', 'https://api.mistral.ai/v1'),
        ],

        // Custom n8n proxy that wraps a Claude CLI inside the workflow.
        // Lets the operator reuse a Claude subscription without buying
        // separate Anthropic API credits. Routed via
        // App\Services\Ai\N8nClaudeGateway, NOT laravel/ai's built-in
        // drivers — the wire format is GET-with-body and the response
        // is a child-process exec result, not the Anthropic API shape
        // laravel/ai expects. Set AI_PROVIDER_* = 'n8n' to use it.
        'n8n' => [
            'driver' => 'n8n',
            'url' => env('AI_N8N_URL'),
            'timeout' => (int) env('AI_N8N_TIMEOUT', 300),
            // Where n8n should POST the result if its sync response is
            // dropped by an upstream proxy (504). Set to the absolute
            // URL of the /webhooks/n8n/extraction endpoint on this app.
            // When unset, async insurance is off and we just rely on
            // the sync HTTP response (legacy mode).
            'callback_url' => env('AI_N8N_CALLBACK_URL', ''),
            // Shared secret for verifying inbound callback POSTs.
            // n8n includes the same value in the `X-Callback-Secret`
            // header (configured on the n8n side); webhook handler
            // checks it before accepting.
            'callback_secret' => env('AI_N8N_CALLBACK_SECRET', ''),
            // How long to keep a pending_extractions row "pending"
            // before the reaper marks it expired. Bigger than any
            // realistic Claude run; smaller than forever so a missing
            // callback eventually lets go.
            'callback_expiry_minutes' => (int) env('AI_N8N_CALLBACK_EXPIRY_MINUTES', 15),
        ],

        'ollama' => [
            'driver' => 'ollama',
            // Ollama's local API doesn't require auth but the driver still
            // wants a non-empty string. "ollama" is the conventional stub.
            'key' => env('OLLAMA_API_KEY', 'ollama'),
            // Override per-machine in .env if Ollama is hosted off the
            // local machine.
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
            'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
            'url' => env('VOYAGEAI_URL', 'https://api.voyageai.com/v1'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
            'url' => env('XAI_URL', 'https://api.x.ai/v1'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Football Intel — per-task provider + model resolution
    |--------------------------------------------------------------------------
    |
    | Every agent in App\Ai\Agents reads its provider + model from here via
    | config('ai.football_intel.providers.*') and config('ai.football_intel.models.*').
    | Override either side in .env without touching PHP — swap Claude for
    | Ollama on the classifier, upgrade Opus 4.7 → Opus 5 when it ships,
    | switch embedding providers, etc.
    |
    | Dimensions matter for `knowledge_chunks.embedding` (vector(1024)).
    | Voyage `voyage-3-large` = 1024 dims; matches the column by default.
    | Swapping embedders to a different dimensionality requires a migration.
    |
    */

    'football_intel' => [
        'providers' => [
            'classifier' => env('AI_PROVIDER_CLASSIFIER', 'anthropic'),
            'extractor' => env('AI_PROVIDER_EXTRACTOR', 'anthropic'),
            'nutritionist' => env('AI_PROVIDER_NUTRITIONIST', 'anthropic'),
            'vision' => env('AI_PROVIDER_VISION', 'anthropic'),
            'embedding' => env('AI_PROVIDER_EMBEDDING', 'voyageai'),
        ],

        // Model values are passed straight through to the n8n workflow as
        // `--model <value>` for the Claude CLI, which only accepts the
        // family aliases `haiku` / `sonnet` / `opus` (NOT full identifiers
        // like `claude-opus-4-7`). Keep these as aliases unless you switch
        // off n8n to a provider that wants full IDs (Anthropic API direct,
        // Ollama, etc.) — at which point override in .env per-task.
        //
        // Why sonnet for match-report extraction specifically: 50-page
        // AGCFF reports produce ~20-30 player rows × ~45 fields each, well
        // past Haiku's 8K output cap and tight against Opus 4.7's 32K. Sonnet
        // 4.6's 64K output limit gives comfortable headroom.
        'models' => [
            'classifier' => env('AI_MODEL_CLASSIFIER', 'haiku'),
            'extractor' => env('AI_MODEL_EXTRACTOR', 'opus'),
            'match_extractor' => env('AI_MODEL_MATCH_EXTRACTOR', 'haiku'),
            // Phase-2 match extractors — each handles a single section of
            // the PDF (smaller input + output than the main extractor), so
            // haiku is the default. Override individually via env if a
            // section's payload grows beyond haiku's output budget.
            'match_shot_events' => env('AI_MODEL_MATCH_SHOT_EVENTS', 'haiku'),
            'nutritionist' => env('AI_MODEL_NUTRITIONIST', 'opus'),
            'vision' => env('AI_MODEL_VISION', 'opus'),
            'embedding' => env('AI_MODEL_EMBEDDING', 'voyage-3-large'),
        ],

        // Dimensionality of the embedding vector the chosen model produces.
        // Must match the vector() column width in the schema; see
        // knowledge_chunks migration if you change it.
        'embedding_dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 1024),

        // Match-report ingestion UX knobs.
        //   stuck_threshold_minutes: when a match_report has been in
        //     `awaiting_callback` for this long without anything moving on it
        //     (updated_at as proxy), the preview page surfaces a "Force retry"
        //     button. Keep below callback_expiry_minutes (default 15) so the
        //     admin can intervene before the reaper marks the pending row
        //     expired.
        'match_report' => [
            'stuck_threshold_minutes' => (int) env('AI_MATCH_REPORT_STUCK_MIN', 10),
        ],

        /*
        | Input preprocessing per agent.
        |
        | strip_arabic — remove Arabic Unicode ranges (U+0600–U+06FF +
        | U+0750–U+077F) before handing the text to the agent. On
        | Bahraini lab reports the Arabic is almost entirely boilerplate
        | (lab addresses, privacy notices, duplicate column headers),
        | and stripping it cuts prompt length + avoids code-switch
        | slowdowns on smaller local models. Turn off if the upstream
        | documents actually carry informative Arabic content (e.g. a
        | coach's hand-written feedback in Arabic).
        |
        | Defaults: strip for classifier (only needs to recognise the
        | type), keep for extractor (may need patient name + metadata).
        */
        'preprocess' => [
            'strip_arabic' => [
                'classifier' => (bool) env('AI_STRIP_ARABIC_CLASSIFIER', true),
                'extractor' => (bool) env('AI_STRIP_ARABIC_EXTRACTOR', false),
            ],

            // Noise stripper — drops lab letterheads, signature blocks,
            // page footers, and repeated multi-page headers. Cuts prompt
            // size 20-40% on lab reports without removing signal. Default
            // ON for both; turn OFF per-stage if you suspect over-strip.
            'strip_noise' => [
                'classifier' => (bool) env('AI_STRIP_NOISE_CLASSIFIER', true),
                'extractor' => (bool) env('AI_STRIP_NOISE_EXTRACTOR', true),
            ],
        ],

        // Optional cap on how much text the classifier sees. Default 0
        // = no cap (send the full document). Set a positive value in
        // .env (e.g. 4000) when running against smaller local models
        // that get slow on long prompts — Claude Haiku doesn't need it.
        // 0 / negative / unset = no truncation.
        'classifier_max_chars' => (int) env('AI_CLASSIFIER_MAX_CHARS', 0),
    ],

];
