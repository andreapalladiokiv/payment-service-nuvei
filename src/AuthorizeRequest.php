<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Omnipay\Common\Message\AbstractResponse;

/**
 * Pre-authorizes (holds) funds via Nuvei (settleType=0).
 */
final class AuthorizeRequest extends NuveiPaymentRequest
{
    protected function transactionType(): string
    {
        return 'Auth';
    }

    protected function settleType(): ?int
    {
        return 0;
    }

    protected function wrapResponse(array $result): AbstractResponse
    {
        return new AuthorizeResponse($this, $result);
    }
}
