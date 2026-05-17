<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

/**
 * Thrown by N8nClaudeGateway when the sync HTTP call to n8n times out
 * without a definitive failure — either the proxy in front of n8n
 * returned a gateway-timeout status (504/522/524) or our own HTTP
 * client hit its read timeout. In both cases the work is *not* lost:
 * n8n keeps running upstream and will POST the result to our callback
 * endpoint when it finishes. The pending_extractions row carries the
 * correlation id; the caller (job / probe command / etc.) should
 * stamp "awaiting_callback" on whatever owning entity it cares about
 * and exit cleanly without retrying.
 *
 * Distinct from generic RuntimeException so the caller can decide:
 *   timeout here  → callback will save us, no Horizon retry
 *   anything else → propagate, let Horizon's failed() hook handle it
 */
class CallbackPendingException extends RuntimeException
{
    public function __construct(public readonly string $correlationId, ?Throwable $previous = null)
    {
        parent::__construct(
            "Sync call to n8n timed out; awaiting callback for correlation {$correlationId}.",
            previous: $previous,
        );
    }
}
