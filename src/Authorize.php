<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Service\PaymentService;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Nuvei\Concern\PaymentBody;
use Throwable;

/**
 * Pre-authorizes (holds) funds — Nuvei's `Auth` with `settleType` 0.
 *
 * Both halves matter and neither implies the other: `Auth` on its own, with the acquirer's default
 * settle type, captures immediately on some accounts, which would take the cardholder's money for
 * a booking that is only being held. `settleType` 0 is declared explicitly for that reason, and 0
 * being falsy is why the body omits the key on a `null` rather than on a falsy value.
 *
 * It serves two operations. `authorize` places a standalone hold; `authorizeRebilling` places one
 * inside a series, and the only difference on the wire is the rebilling block {@see PaymentBody}
 * adds for a {@see RebillingCommand}. They stay separate roles on the gateway because whether a
 * payment belongs to a series is the caller's to state and no field of an ordinary authorization
 * implies it — but they are one call to Nuvei, so one operation makes it, and the command it was
 * built with says which it is.
 */
final readonly class Authorize
{
    private PaymentBody $body;

    /**
     * @param  string  $customerReference  Nuvei's `userTokenId`, resolved by the gateway from this
     *   command's instrument before the operation was built.
     */
    public function __construct(
        private NuveiSettings $settings,
        GatewayInfrastructure $infrastructure,
        PlacementCommand|RebillingCommand $command,
        string $customerReference = '',
    ) {
        $this->body = new PaymentBody($settings, $infrastructure, $command, 'authorize', $customerReference);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->body->build('Auth', 0);
    }

    public function authorize(): AuthorizationResult
    {
        // The payload is built OUTSIDE the try, and that placement is load-bearing rather than
        // stylistic. Omnipay's `send()` was `$data = $this->getData(); return $this->sendData($data);`
        // — only the SENDING was wrapped — so an exception raised while assembling the body has
        // always propagated. It must keep doing so: {@see \Techork\PaymentService\Gateway\Exception\UnsupportedInstrument}
        // and {@see \Techork\PaymentService\Gateway\Exception\IncompleteAuthentication} carry
        // `UnsupportedByGateway`, which the gateway stack rethrows instead of folding, and folding
        // one here would record a wiring error as an acquirer decline for a request no acquirer saw.
        $data = $this->payload();

        try {
            $result = new PaymentService($this->settings->restClient)->createPayment($data);
        } catch (Throwable $e) {
            return NuveiTransactionOutcome::fromThrowable($e)->toAuthorizationResult();
        }

        return new NuveiTransactionOutcome($result)->toAuthorizationResult();
    }
}
