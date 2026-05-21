<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Omnipay\Common\Message\AbstractResponse;

final class CreatePaymentMethodResponse extends AbstractResponse
{
    public function isSuccessful(): bool
    {
        return ($this->data['status'] ?? '') === 'SUCCESS'
            && ! empty($this->data['userPaymentOptionId']);
    }

    public function getTransactionReference(): ?string
    {
        return isset($this->data['userPaymentOptionId'])
            ? (string) $this->data['userPaymentOptionId']
            : null;
    }

    public function getMessage(): ?string
    {
        return $this->data['reason'] ?? $this->data['errCode'] ?? null;
    }
}
