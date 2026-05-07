<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * Thrown by N8nClaudeGateway when the sync HTTP call to n8n hits a
 * 504 from the proxy in front of n8n. The work is *not* lost —
 * n8n keeps running upstream and will POST the result to our callback
 * endpoint when it finishes. The pending_extractions row carries the
 * correlation id; the caller (job / probe command / etc.) should
 * stamp "awaiting_callback" on whatever owning entity it cares about
 * and exit cleanly without retrying.
 *
 * Distinct from generic RuntimeException so the caller can decide:
 *   504 here     → callback will save us, no Horizon retry
 *   anything else → propagate, let Horizon's failed() hook handle it
 */
class CallbackPendingException extends RuntimeException
{
    public function __construct(public readonly string $correlationId)
    {
        parent::__construct(
            "Sync call to n8n timed out at the proxy; awaiting callback for correlation {$correlationId}.",
        );
    }
}
