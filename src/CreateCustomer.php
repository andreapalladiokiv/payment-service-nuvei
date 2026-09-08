<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Service\UserService;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Throwable;

/**
 * Registers a Nuvei user — `createUser` — so that stored instruments have an owner to hang off.
 *
 * For Nuvei the customer reference IS the email: it is the `userTokenId` every later payment
 * carrying a stored `userPaymentOptionId` has to name, which is why the operation answers with the
 * email rather than with anything Nuvei minted.
 *
 * Reached from {@see NuveiGateway::resolveCustomerReference()} when an instrument has no link yet,
 * so it costs a round trip and only the operations that need a `userTokenId` on the wire pay it.
 *
 * There is no command for this one — it is not a caller's operation, it is the gateway repairing a
 * missing link — so the address and email are plain constructor arguments.
 */
final readonly class CreateCustomer
{
    public function __construct(
        private NuveiSettings $settings,
        private ?BillingAddress $billingAddress = null,
        private string $email = '',
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
        $email = $this->email !== '' ? $this->email : (string) ($address?->email ?? '');
        $state = $address?->state;

        return array_filter([
            'userTokenId' => $email,
            'clientRequestId' => uniqid('cust_', true),
            'email' => $email,
            'firstName' => $address?->firstName ?: 'N/A',
            'lastName' => $address?->lastName ?: 'N/A',
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

        // `array_filter` drops an empty email, so a userTokenId can be absent from a body Nuvei
        // nonetheless accepted.
        //
        // What HEAD did with that: `sendData()` read `$data['userTokenId']` unguarded, which
        // raised an undefined-key Warning and evaluated to null, so the response carried
        // `['reference' => null]` — unsuccessful, no reference, and NO message, because the
        // `error` key was only ever set on the other two branches. The warning is not worth
        // reproducing; the answer is, so this is a failure with a null message rather than a
        // reason invented here.
        //
        // The gateway's caller reads the same thing either way: `resolveCustomerReference()`
        // interpolates the null message into "Nuvei createCustomer failed: " and throws, exactly
        // as it did.
        $reference = $data['userTokenId'] ?? null;

        // `!empty()` was the test, so both null and '' were unsuccessful and both were still handed
        // back as the reference. Passed through rather than normalised, so neither shape changes.
        return $reference === null || $reference === ''
            ? new GatewayResult(false, $reference, null)
            : GatewayResult::succeeded($reference);
    }
}
