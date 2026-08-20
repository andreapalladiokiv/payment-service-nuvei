<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Override;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Nuvei\Api\RestClient;
use Nuvei\Api\Service\UserService;
use Omnipay\Common\Message\AbstractRequest;
use Throwable;

/**
 * Creates a Nuvei user via UserService::createUser().
 * Expects: email, country, restClient (+ optional address, city, postal_code, state).
 * Returns the email (userTokenId) as the transaction reference.
 */
final class CreateCustomerRequest extends AbstractRequest
{
    use NuveiRequestParameters;

    #[Override]
    public function getData(): array
    {
        // Read off the billing address rather than seven discrete keys. None of those keys had
        // a setter, so omnipay dropped every one of them and the defaults below were what
        // actually went to Nuvei: every customer registered as "N/A N/A" in the US, whatever
        // their real name and country.
        //
        // The defaults stay, because Nuvei marks firstName and lastName required and a
        // placeholder is the honest answer when a name is genuinely unknown. They are now the
        // last resort they were meant to be rather than the normal case.
        $address = $this->getBillingAddress();
        $identity = $this->getCustomerIdentity();
        $email = (string) ($identity?->email
            ?? ($this->getEmail() !== '' ? $this->getEmail() : $address?->email));
        $state = $address?->state;

        // The token is our customer id when we have one. It used to be the email, which Nuvei
        // documents as the field that "uniquely identifies your consumer/user in your system" —
        // so a change of address made a different customer of the same person and orphaned their
        // saved cards. See {@see \Techork\PaymentService\Nuvei\NuveiGateway} for the migration
        // this cannot ship without.
        return array_filter([
            'userTokenId' => $this->getUserTokenId() !== '' ? $this->getUserTokenId() : $email,
            'clientRequestId' => uniqid('cust_', true),
            'email' => $email,
            // The identity first: Nuvei marks these required, and reading them off whatever
            // address rode along with a payment is how customers came to be registered as
            // "N/A N/A". The placeholders stay as the last resort they were meant to be.
            'firstName' => $identity?->firstName ?: ($address?->firstName ?: 'N/A'),
            'lastName' => $identity?->lastName ?: ($address?->lastName ?: 'N/A'),
            'countryCode' => $address !== null ? (string) $address->country : 'US',
            'address' => $address?->line,
            'city' => $address?->city,
            'zip' => $address?->postalCode,
            'state' => $state === null ? null : (string) $state,
        ]);
    }

    #[Override]
    public function sendData($data): CreateCustomerResponse
    {
        try {
            /** @var RestClient $client */
            $client = $this->getParameter('restClient');

            $result = new UserService($client)->createUser($data);

            if (($result['status'] ?? '') === 'SUCCESS') {
                return new CreateCustomerResponse($this, [
                    'reference' => $data['userTokenId'],
                ]);
            }

            return new CreateCustomerResponse($this, [
                'reference' => null,
                'error' => $result['reason'] ?? 'Nuvei createUser failed',
            ]);
        } catch (Throwable $e) {
            return new CreateCustomerResponse($this, [
                'reference' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function setCustomerIdentity(?CustomerIdentity $value): self
    {
        return $this->setParameter('customerIdentity', $value);
    }

    public function getCustomerIdentity(): ?CustomerIdentity
    {
        $identity = $this->getParameter('customerIdentity');

        return $identity instanceof CustomerIdentity ? $identity : null;
    }

    public function setUserTokenId(?string $value): self
    {
        return $this->setParameter('userTokenId', $value);
    }

    public function getUserTokenId(): string
    {
        $token = $this->getParameter('userTokenId');

        return is_string($token) ? $token : '';
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
