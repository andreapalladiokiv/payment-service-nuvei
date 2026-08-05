<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Override;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Omnipay\Common\Message\AbstractRequest;

/**
 * No-op update for Nuvei — the user already exists; address changes are not pushed.
 * Returns the existing customerReference as the transaction reference.
 */
final class UpdateCustomerRequest extends AbstractRequest
{
    use NuveiRequestParameters;

    #[Override]
    public function getData(): array
    {
        return [
            'customerReference' => $this->getParameter('customerReference') ?? '',
        ];
    }

    #[Override]
    public function sendData($data): CreateCustomerResponse
    {
        return new CreateCustomerResponse($this, [
            'reference' => $data['customerReference'],
        ]);
    }
}
