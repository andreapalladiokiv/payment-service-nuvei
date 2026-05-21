<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Omnipay\Common\Message\AbstractResponse;

final class CreateCardResponse extends AbstractResponse
{
    public function isSuccessful(): bool
    {
        return isset($this->data['status']) && $this->data['status'] === 'SUCCESS'
            && isset($this->data['ccTempToken']);
    }

    public function getTransactionReference(): ?string
    {
        return isset($this->data['ccTempToken'])
            ? (string) $this->data['ccTempToken']
            : null;
    }

    public function getSessionToken(): ?string
    {
        return $this->data['sessionToken'] ?? null;
    }

    public function getMessage(): ?string
    {
        return $this->data['reason'] ?? $this->data['errCode'] ?? null;
    }
}
