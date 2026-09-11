<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use DateTimeImmutable;
use Nuvei\Api\Environment;
use Nuvei\Api\Service\PaymentService;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Techork\PaymentService\Nuvei\Concern\PaymentBody;
use Throwable;

/**
 * Takes a payment outright — Nuvei's `Sale`, which authorizes and settles in one message, so no
 * `settleType` is declared and the acquirer's default never comes into it.
 *
 * Two routes, and only the first touches the REST API. An ordinary instrument goes to
 * `payment.do` through {@see PaymentBody}. A {@see HostedPayment} instead produces the form the
 * buyer's browser POSTs to Nuvei Cashier: no call is made from here at all, the cardholder enters
 * their card on Nuvei's page, and the outcome arrives asynchronously through the DMN webhook that
 * {@see Webhook\Handler\SaleHandler} reads.
 *
 * {@see payload()} is pure and says which of the two it built; {@see charge()} makes the call and
 * maps the answer where it is still in hand. There is no request object to construct and send, and
 * no response object wrapping the payload for something else to re-read.
 */
final readonly class Purchase
{
    use NuveiRequestParameters;

    private const string CASHIER_URL_TEST = 'https://ppp-test.nuvei.com/ppp/purchase.do';

    private const string CASHIER_URL_PROD = 'https://secure.safecharge.com/ppp/purchase.do';

    private const string CASHIER_VERSION = '4.0.0';

    private PaymentBody $body;

    /**
     * @param  string  $customerReference  Nuvei's `userTokenId`, resolved by the gateway from this
     *   command's instrument before the operation was built.
     */
    public function __construct(
        private NuveiSettings $settings,
        GatewayInfrastructure $infrastructure,
        private PlacementCommand $command,
        private string $customerReference = '',
    ) {
        $this->body = new PaymentBody($settings, $infrastructure, $command, 'purchase', $customerReference);
    }

    /**
     * The REST body, or — for a hosted payment — the Cashier form and where to post it. Two
     * shapes, because they are two different things to send and the instrument decides which.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $instrument = $this->command->instrument;

        if ($instrument instanceof HostedPayment) {
            return $this->cashierForm($instrument);
        }

        return $this->body->build('Sale', null);
    }

    public function charge(): AuthorizationResult
    {
        if ($this->command->instrument instanceof HostedPayment) {
            return $this->redirectToCashier($this->payload());
        }

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

    /**
     * Hosted-payment flow via Nuvei Cashier (Hosted Payment Page). The browser POSTs these fields
     * to {@see purchase.do}; Nuvei collects the card data server-side.
     *
     * @return array{cashier_url: string, form_fields: array<string, string>, reference: string}
     */
    private function cashierForm(HostedPayment $hosted): array
    {
        $money = $this->command->amount;

        $totalAmount = $this->formatMoney($money);
        $currency = $money->getCurrency()->getCode();

        // The documented format for `time_stamp` is `YYYY-MM-DD.HH:MM:SS` (GMT, 24h) — the
        // compact `YmdHis` the REST API takes is refused by Cashier's checksum check.
        $timeStamp = new DateTimeImmutable()->format('Y-m-d.H:i:s');

        $clientUniqueId = $this->command->clientUniqueId ?? Uuid::uuid4()->toString();

        // Cashier's checksum rule (docs.nuvei.com, "Quick start for Payment Page", purchase.do
        // 4.0.0): SHA-256 over the secret key CONCATENATED FIRST, then the values of ALL the
        // input parameters in the exact order they are sent in the request. So the field array
        // is built complete — user_token_id included, since it rides in the same POST — and the
        // checksum is the only field appended afterwards. The earlier version here signed five
        // chosen fields, secret last, which Cashier refuses as an invalid checksum.
        $formFields = [
            'merchant_id' => $this->settings->merchantId,
            'merchant_site_id' => $this->settings->merchantSiteId,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'time_stamp' => $timeStamp,
            'version' => self::CASHIER_VERSION,
            'item_name_1' => 'Payment',
            'item_amount_1' => $totalAmount,
            'item_quantity_1' => '1',
            'success_url' => $hosted->successUrl,
            'error_url' => $hosted->cancelUrl,
            'pending_url' => $hosted->successUrl,
            'back_url' => $hosted->cancelUrl,
            'clientUniqueId' => $clientUniqueId,
        ];

        if ($this->customerReference !== '') {
            $formFields['user_token_id'] = $this->customerReference;
        }

        $formFields['checksum'] = hash('sha256', $this->settings->secretKey.implode('', array_values($formFields)));

        return [
            'cashier_url' => $this->settings->environment === Environment::LIVE
                ? self::CASHIER_URL_PROD
                : self::CASHIER_URL_TEST,
            'form_fields' => $formFields,
            'reference' => $clientUniqueId,
        ];
    }

    /**
     * A hosted payment is `requires_action` by construction: nothing has been asked of an acquirer
     * yet, the buyer has merely been sent somewhere. The reference is our own clientUniqueId,
     * because Nuvei has issued no transaction id to name at this point — the DMN supplies one later
     * and correlates by exactly this value.
     *
     * The opening reference is recorded here for the same reason every other placement records it:
     * `reference` is overwritten on transition, and a later settle or refund still has to be able
     * to name what opened the intent.
     *
     * @param  array<string, mixed>  $payload
     */
    private function redirectToCashier(array $payload): AuthorizationResult
    {
        /** @var string $reference */
        $reference = $payload['reference'];

        /** @var array<string, string> $formFields */
        $formFields = $payload['form_fields'];

        $challenge = new RedirectChallenge(
            transactionId: $reference,
            url: (string) $payload['cashier_url'],
            formFields: $formFields,
        );

        return AuthorizationResult::requiresAction($reference, $challenge)
            ->withMetadata(['opening_transaction_reference' => $reference]);
    }
}
