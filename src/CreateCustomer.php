<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Service\UserService;
use Techork\PaymentService\Common\ValueObject\Customer;
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
 * The whole {@see Customer} arrives, because Nuvei's `createUser` wants all three parts of one:
 * our id as the token, the person, and their address. They stay distinct inside it because they
 * answer different questions — which is what the three separate optional arguments here were for,
 * and what they could not enforce.
 */
final readonly class CreateCustomer
{
    public function __construct(
        private NuveiSettings $settings,
        private Customer $customer,
    ) {}

    /**
     * Read off the customer rather than seven discrete keys. None of those keys had a setter, so
     * Omnipay dropped every one of them and hardcoded defaults were what actually went to Nuvei:
     * every customer registered as "N/A N/A" in the US, whatever their real name and country.
     *
     * **The `'N/A'` and `'US'` fallbacks are gone with them.** A {@see Customer} has a name and a
     * country, so there is no longer a case to substitute for — where either is genuinely unknown
     * the caller says so with the shredding stub and `ZZ`, which is the same statement made where
     * somebody knows whether it is true. A default here could only be a guess about a payer this
     * class has never seen.
     *
     * @return array<string, string>
     */
    public function payload(): array
    {
        $identity = $this->customer->identity;
        $address = $this->customer->billingAddress;
        $state = $address->state;

        return array_filter([
            'userTokenId' => $this->customer->id->toString(),
            'clientRequestId' => uniqid('cust_', true),
            'email' => (string) $identity->email,
            'firstName' => $identity->firstName,
            'lastName' => $identity->lastName,
            'countryCode' => (string) $address->country,
            'address' => $address->line,
            'city' => $address->city,
            'zip' => $address->postalCode,
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
