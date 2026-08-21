<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\RestClient;
use Nuvei\Api\Service\UserService;
use Omnipay\Common\Message\AbstractRequest;
use Override;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Gateway\Exception\RegistrationNeedsCustomer;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Throwable;

/**
 * Creates a Nuvei user via `UserService::createUser()`.
 *
 * Expects **`customerId`** (equivalently `userTokenId` — {@see setCustomerId()} writes the same
 * slot) and a `restClient`; refuses without the first, because `userTokenId` is what Nuvei
 * documents as uniquely identifying a consumer in our system and there is no honest value to
 * default it to. `customerIdentity` supplies the name and email, `billingAddress` the rest;
 * placeholders cover only what Nuvei marks required and nobody supplied.
 *
 * **Returns our customer id as the transaction reference**, because that is the id Nuvei now knows
 * this user under — the caller stores the pair, and both halves are ours. It used to return the
 * email, which is the same sentence read the other way round: the email *was* the token. That is
 * what {@see \Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface::registerCustomer()}
 * and A3 in `docs/customer-domain-plan` exist to leave behind.
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

        // The token is our customer id, and there is no fallback to the email. Nuvei documents
        // this field as what "uniquely identifies your consumer/user in your system", so keying it
        // on an address makes a different person of the same customer whenever the address
        // changes, and orphans their stored cards — see A3 in `docs/customer-domain-plan` for the
        // migration that exists because it used to. Falling back would keep minting the state that
        // migration is for, and invisibly: the response reports the token as its reference, so the
        // caller would store (our id → email) and every later lookup would agree with itself.
        $userTokenId = $this->getUserTokenId() !== ''
            ? $this->getUserTokenId()
            : throw RegistrationNeedsCustomer::toRegisterAt('nuvei');

        return array_filter([
            'userTokenId' => $userTokenId,
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

    /**
     * The same slot under the name the rest of the system uses.
     *
     * `PaymentGatewayRouter` speaks of *our* customer id and must not have to know that Nuvei
     * calls it `userTokenId` — translating is the adapter's job. It matters that this exists
     * rather than being left to the caller: Omnipay applies an option only where a matching setter
     * exists ({@see \Omnipay\Common\Helper::initialize()}), so a key nothing here declares is
     * dropped without a word.
     */
    public function setCustomerId(?string $value): self
    {
        return $this->setUserTokenId($value);
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
