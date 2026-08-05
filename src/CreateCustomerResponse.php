<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Omnipay\Common\Message\AbstractResponse;
use Override;

final class CreateCustomerResponse extends AbstractResponse
{
    #[Override]
    public function isSuccessful(): bool
    {
        return ! empty($this->data['reference']);
    }

    #[Override]
    public function getTransactionReference(): ?string
    {
        return $this->data['reference'] ?? null;
    }

    #[Override]
    public function getMessage(): ?string
    {
        return $this->data['error'] ?? null;
    }
}
