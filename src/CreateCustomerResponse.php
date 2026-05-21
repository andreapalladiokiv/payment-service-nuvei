<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Omnipay\Common\Message\AbstractResponse;

final class CreateCustomerResponse extends AbstractResponse
{
    public function isSuccessful(): bool
    {
        return ! empty($this->data['reference']);
    }

    public function getTransactionReference(): ?string
    {
        return $this->data['reference'] ?? null;
    }

    public function getMessage(): ?string
    {
        return $this->data['error'] ?? null;
    }
}
