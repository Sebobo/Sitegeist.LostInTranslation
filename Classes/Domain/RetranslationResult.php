<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

/**
 * Outcome of a single {@see \Sitegeist\LostInTranslation\ContentRepository\RetranslationService::retranslateNode()}
 * run.
 *
 * Mirrors the Neos 9 result object: it carries the reason a run was skipped (if any) plus the
 * dispatch counts, so callers (CLI, controller) can distinguish "nothing to do" from real work.
 */
final class RetranslationResult
{
    private function __construct(
        public readonly ?string $skippedReason,
        public readonly int $stalePropertyCommandsDispatched,
        public readonly int $variantCommandsDispatched,
    ) {
    }

    public static function skipped(string $reason): self
    {
        return new self($reason, 0, 0);
    }

    public static function dispatched(int $stalePropertyCommandsDispatched, int $variantCommandsDispatched): self
    {
        return new self(null, $stalePropertyCommandsDispatched, $variantCommandsDispatched);
    }

    public function isNoOp(): bool
    {
        return $this->skippedReason === null
            && $this->stalePropertyCommandsDispatched === 0
            && $this->variantCommandsDispatched === 0;
    }
}
