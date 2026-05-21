<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Nuvei\Api\RestClient;
use Nuvei\Api\Service\UserService;
use Omnipay\Common\Message\AbstractRequest;

/**
 * Creates a Nuvei user via UserService::createUser().
 * Expects: email, country, restClient (+ optional address, city, postal_code, state).
 * Returns the email (userTokenId) as the transaction reference.
 */
final class CreateCustomerRequest extends AbstractRequest
{
    use NuveiRequestParameters;

    public function getData(): array
    {
        return array_filter([
            'userTokenId' => $this->getEmail(),
            'clientRequestId' => uniqid('cust_', true),
            'email' => $this->getEmail(),
            'firstName' => $this->getParameter('firstName') ?: 'N/A',
            'lastName' => $this->getParameter('lastName') ?: 'N/A',
            'countryCode' => $this->getParameter('country') ?? 'US',
            'address' => $this->getParameter('address'),
            'city' => $this->getParameter('city'),
            'zip' => $this->getParameter('postal_code'),
            'state' => $this->getParameter('state'),
        ]);
    }

    public function sendData($data): CreateCustomerResponse
    {
        try {
            /** @var RestClient $client */
            $client = $this->getParameter('restClient');

            $result = (new UserService($client))->createUser($data);

            if (($result['status'] ?? '') === 'SUCCESS') {
                return new CreateCustomerResponse($this, [
                    'reference' => $data['userTokenId'],
                ]);
            }

            return new CreateCustomerResponse($this, [
                'reference' => null,
                'error' => $result['reason'] ?? 'Nuvei createUser failed',
            ]);
        } catch (\Throwable $e) {
            return new CreateCustomerResponse($this, [
                'reference' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getEmail(): string
    {
        return $this->getParameter('email') ?? '';
    }

    public function setEmail(string $value): self
    {
        return $this->setParameter('email', $value);
    }
}
