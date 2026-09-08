<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Service\UserService;
use Techork\PaymentService\Common\Contract\CustomerIdentifier;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Throwable;

/**
 * Registers a Nuvei user — `createUser` — so that stored instruments have an owner to hang off.
 *
 * `userTokenId` is OUR customer id, and the operation answers with it rather than with anything
 * Nuvei minted — the reference for a Nuvei user genuinely is the token we chose, because Nuvei
 * documents the field as the id that "uniquely identifies your consumer/user in your system" and
 * requires it to reuse a stored `userPaymentOptionId`.
 *
 * It used to be the payer's **email**, which made the field a value that changes: a customer who
 * changed address became a different customer and their stored payment options were orphaned,
 * while two people sharing an address were one customer. A UUID is what the field always wanted.
 *
 * `$identity` supplies the person and `$billingAddress` supplies the address. Both are here
 * because Nuvei's `createUser` takes both, and separate because they answer different questions.
 */
final readonly class CreateCustomer
{
    public function __construct(
        private NuveiSettings $settings,
        private CustomerIdentifier $customerId,
        private ?CustomerIdentity $identity = null,
        private ?BillingAddress $billingAddress = null,
    ) {}

    /**
     * Read off the billing address rather than seven discrete keys. None of those keys had a
     * setter, so Omnipay dropped every one of them and the defaults below were what actually went
     * to Nuvei: every customer registered as "N/A N/A" in the US, whatever their real name and
     * country.
     *
     * The defaults stay, because Nuvei marks firstName and lastName required and a placeholder is
     * the honest answer when a name is genuinely unknown. They are now the last resort they were
     * meant to be rather than the normal case.
     *
     * @return array<string, string>
     */
    public function payload(): array
    {
        $address = $this->billingAddress;
        $identity = $this->identity;
        // The identity answers first, the address is the fallback: the address is where the
        // payer's name and email used to live, one copy per card, so it is still the honest
        // answer when nobody has been named — and it stops being consulted once somebody has.
        $email = (string) ($identity?->email ?? $address?->email ?? '');
        $state = $address?->state;

        return array_filter([
            'userTokenId' => $this->customerId->toString(),
            'clientRequestId' => uniqid('cust_', true),
            'email' => $email,
            'firstName' => $identity->firstName ?? ($address?->firstName ?: 'N/A'),
            'lastName' => $identity->lastName ?? ($address?->lastName ?: 'N/A'),
            'countryCode' => $address !== null ? (string) $address->country : 'US',
            'address' => $address?->line,
            'city' => $address?->city,
            'zip' => $address?->postalCode,
            'state' => $state === null ? null : (string) $state,
        ]);
    }

    public function create(): GatewayResult
    {
        $data = $this->payload();

        try {
            $result = new UserService($this->settings->restClient)->createUser($data);
        } catch (Throwable $e) {
            return GatewayResult::failed($e->getMessage());
        }

        if (($result['status'] ?? '') !== 'SUCCESS') {
            return GatewayResult::failed((string) ($result['reason'] ?? 'Nuvei createUser failed'));
        }

        // The token is what identifies the user, so it is what comes back. An empty one used to
        // be reachable — `array_filter` dropped an empty email and the token was the email — and
        // the answer was an unsuccessful result carrying no reference and no message. It is no
        // longer reachable from the routed operation, which refuses an empty customer id before
        // getting here, so the branch stays only for a direct caller of this class.
        $reference = $data['userTokenId'] ?? null;

        return $reference === null || $reference === ''
            ? new GatewayResult(false, $reference, null)
            : GatewayResult::succeeded($reference);
    }
}
