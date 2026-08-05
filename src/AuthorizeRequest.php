<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Omnipay\Common\Message\AbstractResponse;
use Override;

/**
 * Pre-authorizes (holds) funds via Nuvei (settleType=0).
 */
final class AuthorizeRequest extends NuveiPaymentRequest
{
    #[Override]
    protected function transactionType(): string
    {
        return 'Auth';
    }

    #[Override]
    protected function settleType(): ?int
    {
        return 0;
    }

    #[Override]
    protected function wrapResponse(array $result): AbstractResponse
    {
        return new AuthorizeResponse($this, $result);
    }
}
