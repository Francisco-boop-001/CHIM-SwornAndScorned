<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

final class DecisionBackendError extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly bool $retryable,
        ?\Throwable $previous = null
    ) {
        parent::__construct($reasonCode, 0, $previous);
    }
}

// A core switch preserves the job without spending a provider retry.
final class PlaythroughPausedException extends \RuntimeException {}
