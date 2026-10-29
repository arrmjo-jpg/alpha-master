<?php

namespace App\Modules\Core\Errors;

use RuntimeException;

/**
 * A business-rule failure with a stable error code. Rendered as RFC 9457
 * Problem Details by ProblemRenderer; never carries sensitive data.
 */
class DomainError extends RuntimeException
{
    /**
     * @param  string|null  $detail  Already-localized human-readable detail.
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($errorCode->value);
    }

    public static function because(ErrorCode $code, ?string $detail = null): self
    {
        return new self($code, $detail);
    }
}
