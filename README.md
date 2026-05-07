# Football Intelligence System

A private, single-admin AI system for managing football player data — built for Dr. Fadhel Al-Sabbagh's work with the Bahrain Football Federation.

The federation does not log in. The admin uploads player documents (blood tests, body composition, GPS, nutrition plans, coach feedback), and an AI "Nutritionist Assistant" extracts structured data, analyses blood + body composition together, and produces football-specific performance recommendations grounded in a private nutrition knowledge base.

## Scope

- Single admin login
- Manual file upload against a chosen player
- Automatic PDF text extraction (with OCR fallback for photos)
- AI classification + structured extraction per category (blood, InBody, GPS, nutrition plan, hydration/supplement, coach feedback, match activity)
- **Nutritionist Assistant** agent combining blood + body data with RAG over the knowledge base
- Per-player timeline, trend charts, comparison views
- Individual + team PDF reports

## Tech stack

| Layer | Choice |
|---|---|
| Backend | Laravel 13, PHP 8.3+ |
| Frontend | Inertia v3 + React 19 + TypeScript + Vite 8 |
| Styling | Tailwind CSS v4.1 + shadcn/ui + Lucide icons |
| Database | PostgreSQL 18 with `pgvector` |
| Cache / queue | Redis + Laravel Horizon |
| File storage | Laravel local disk (`storage/app/private/attachments`) |
| AI | Anthropic Claude via Laravel AI SDK (Opus 4.7 + Haiku 4.5) |
| OCR | Claude vision primary, Tesseract fallback |
| Charts | Apache ECharts (React bindings) |
| PDF generation | `spatie/laravel-pdf` (Browsershot / Chromium) |
| Auth | Passkey (WebAuthn) + TOTP backup |
| Testing | Pest |
| Package managers | Composer + pnpm |

## Quickstart

### Requirements

- PHP 8.3+ (PHP 8.4 tested)
- Composer 2.8+
- Node 20+ (Node 22 tested)
- pnpm 10+
- PostgreSQL 18 with `pgvector` + `pg_trgm` extensions
- Redis 7+
- Chromium (for PDF generation at runtime)

### Setup

```bash
# Install dependencies (already done on first clone)
composer install
pnpm install

# Environment
cp .env.example .env
php artisan key:generate

# Configure .env — at minimum:
#   DB_CONNECTION=pgsql
#   DB_HOST=127.0.0.1
#   DB_DATABASE=football_intel
#   DB_USERNAME=...
#   DB_PASSWORD=...
#   ANTHROPIC_API_KEY=...     (for Claude via Laravel AI SDK)
#   VOYAGE_API_KEY=...        (for embeddings — or OPENAI_API_KEY)

# Database
php artisan migrate

# Dev server (starts Vite + PHP server + queue worker concurrently)
composer run dev
```

The app will be available at http://localhost:8000.

## Development workflow

- `composer run dev` — Vite + PHP + queue worker
- `composer run test` — Pest test suite
- `vendor/bin/pint` — Laravel Pint (PHP formatter)
- `pnpm run build` — production build
- `pnpm run types` — TypeScript check
- `pnpm run lint` — ESLint + Prettier

## Privacy

This system contains sensitive medical data (blood tests, body composition, health status). Attachments and extracted text are encrypted at rest. The app is single-admin; nothing is auto-shared. Reports are export-gated — the admin chooses which sections enter a PDF before it is generated.
