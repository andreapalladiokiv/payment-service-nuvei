<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Throwable;

/**
 * Reads a Nuvei transaction answer — payment.do, settleTransaction.do, refundTransaction.do,
 * voidTransaction.do all reply in the same shape — and says what it means for a payment.
 *
 * One mapping where there used to be three. A request assembled the array, a response class
 * wrapped it and answered `isSuccessful()` / `getChallenge()` / `getCvcCheck()` through interfaces,
 * and a shared assembler read those back to build the result. Every hop existed so a generic
 * folder could interrogate a homogenised response — but each provider had to write that response
 * itself, so nothing was ever actually shared. The operation knows what it got; it says so here,
 * once.
 *
 * Nothing in this class talks to the network, so an operation can be handed a recorded payload and
 * asked what it would have concluded.
 */
final readonly class NuveiTransactionOutcome
{

    private const string UNSUCCESSFUL = 'Gateway returned an unsuccessful response.';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data) {}

    /**
     * The shape an operation substitutes when the call threw before a reply existed — an
     * unreachable acquirer, a checksum the SDK refused to build. It has to read as a failure with
     * the reason attached, never as a success with nothing in it.
     */
    public static function fromThrowable(Throwable $e): self
    {
        return new self(['status' => 'ERROR', 'reason' => $e->getMessage()]);
    }

    public function isSuccessful(): bool
    {
        return ($this->data['status'] ?? '') === 'SUCCESS'
            && ($this->data['transactionStatus'] ?? '') === 'APPROVED';
    }

    public function reference(): ?string
    {
        return isset($this->data['transactionId']) ? (string) $this->data['transactionId'] : null;
    }

    public function message(): ?string
    {
        if (! empty($this->data['reason'])) {
            return (string) $this->data['reason'];
        }

        if (! empty($this->data['gwErrorReason'])) {
            return (string) $this->data['gwErrorReason'];
        }

        return isset($this->data['errCode']) && $this->data['errCode'] !== '0'
            ? "Error code: {$this->data['errCode']}"
            : null;
    }

    /**
     * FX-settled amount from Nuvei's DCC/MCP `currencyConversion` block, present only when the
     * transaction crossed a currency boundary (the cardholder converted on the payment page or DCC
     * was applied). Nuvei reports amounts as decimal strings, so parse against the converted
     * currency's ISO scale. Null when no conversion block is present.
     */
    public function convertedAmount(): ?Money
    {
        $conversion = $this->data['currencyConversion'] ?? null;
        if (! is_array($conversion)) {
            return null;
        }

        // Cast first, check second. The other order left the guard proving something about
        // the raw payload value while the cast handed a fresh, unchecked string to Currency.
        $amount = (string) ($conversion['convertedAmount'] ?? '');
        $currency = (string) ($conversion['convertedCurrency'] ?? '');

        if ($amount === '' || $currency === '') {
            return null;
        }

        return new DecimalMoneyParser(new ISOCurrencies)
            ->parse($amount, new Currency($currency));
    }

    public function challenge(): ?Challenge
    {
        // Narrowed once: everything below indexes it, and an absent or non-array threeD
        // block behaves identically to an empty one.
        $threeD = $this->data['paymentOption']['card']['threeD'] ?? null;
        $threeD = is_array($threeD) ? $threeD : [];

        $redirectUrl = $this->data['paymentOption']['redirectUrl'] ?? null;

        // `methodUrl` comes first in Nuvei's own flow and was not read here at all, so the device
        // fingerprinting step never reached a client — which costs frictionless approvals, since
        // that step is what the issuer's risk decision is made on.
        $url = $threeD['acsUrl'] ?? $threeD['methodUrl'] ?? $redirectUrl;
        $payload = $threeD['cReq'] ?? $threeD['creq'] ?? $threeD['methodPayload'] ?? null;

        // Nuvei's own transaction id, not a `threeDSServerTransID`. Digging the protocol's
        // identifier out of the base64 method payload was tried and reverted: it exists only on
        // the fingerprint step, so it would name a different thing at each step, and it is not
        // what resumes anything here.
        $authenticationId = $this->reference();

        if ($url === null || $authenticationId === null) {
            return null;
        }

        $isChallenge = ($this->data['transactionStatus'] ?? '') === 'REDIRECT'
            || ($threeD['result'] ?? null) === 'C'
            || isset($threeD['methodUrl']);

        if (! $isChallenge) {
            return null;
        }

        return new ThreeDSChallenge(
            authenticationId: $authenticationId,
            url: (string) $url,
            payload: $payload === null ? null : (string) $payload,
            protocolVersion: ThreeDSVersion::tryFrom((string) ($threeD['version'] ?? '')) ?? ThreeDSVersion::V220,
        );
    }

    public function addressLineCheck(): ?CheckResult
    {
        $avs = $this->avsLetter();

        if ($avs === null) {
            return null;
        }

        return NuveiSchemeChecks::avsToLineAndPostal($avs)[0];
    }

    public function postalCodeCheck(): ?CheckResult
    {
        $avs = $this->avsLetter();

        if ($avs === null) {
            return null;
        }

        return NuveiSchemeChecks::avsToLineAndPostal($avs)[1];
    }

    public function cvcCheck(): ?CheckResult
    {
        $cvv = $this->data['paymentOption']['card']['cvv2Reply'] ?? null;

        if ($cvv === null || $cvv === '') {
            return null;
        }

        return NuveiSchemeChecks::cvvToCheckResult($cvv);
    }

    /**
     * For authorize / charge / rebilling.
     *
     * A challenge is checked before success, and deliberately: a payment awaiting a step-up is not
     * yet successful and not a failure either, and reading success first would drop the challenge
     * of a provider that reports both.
     */
    public function toAuthorizationResult(): AuthorizationResult
    {
        $challenge = $this->challenge();

        if ($challenge !== null) {
            $reference = $this->successReference();

            return $reference === null
                ? AuthorizationResult::failed(GatewayResult::UNNAMED_SUCCESS)
                : $this->withChecks(AuthorizationResult::requiresAction($reference, $challenge))
                    ->withMetadata(self::openingMetadata($reference));
        }

        if (! $this->isSuccessful()) {
            return AuthorizationResult::failed($this->message() ?? self::UNSUCCESSFUL);
        }

        $reference = $this->successReference();

        if ($reference === null) {
            return AuthorizationResult::failed(GatewayResult::UNNAMED_SUCCESS);
        }

        return $this->withChecks(AuthorizationResult::succeeded($reference))
            ->withMetadata(self::openingMetadata($reference))
            ->withConvertedAmount($this->convertedAmount());
    }

    /**
     * For the operations that carry no extra signals — capture, refund, void. They fold without
     * {@see openingMetadata()}, which is what makes it structurally impossible for a settle to
     * write that key and bury the authorization's reference under its own.
     */
    public function toGatewayResult(): GatewayResult
    {
        if (! $this->isSuccessful()) {
            return GatewayResult::failed($this->message() ?? self::UNSUCCESSFUL);
        }

        $reference = $this->successReference();

        if ($reference === null) {
            return GatewayResult::failed(GatewayResult::UNNAMED_SUCCESS);
        }

        return GatewayResult::succeeded($reference)
            ->withConvertedAmount($this->convertedAmount());
    }

    /**
     * The reference a response reporting success must name, or null when it named none. Empty
     * counts as absent: `succeeded()` and `requiresAction()` declare a `string`, and an
     * empty-string handle addresses nothing.
     */
    private function successReference(): ?string
    {
        $reference = $this->reference();

        return $reference === null || $reference === '' ? null : $reference;
    }

    /**
     * Which transaction OPENED the payment intent, recorded because `reference` cannot answer that
     * later: it overwrites on transition, so once a capture lands the row holds the settle
     * reference. Both readings are wanted — Nuvei's settle expects the authorization's
     * transactionId and its refund expects the settle's — so they live side by side.
     *
     * @return array<string, mixed>
     */
    private static function openingMetadata(string $reference): array
    {
        return ['opening_transaction_reference' => $reference];
    }

    private function withChecks(AuthorizationResult $result): AuthorizationResult
    {
        return $result->withChecks($this->addressLineCheck(), $this->postalCodeCheck(), $this->cvcCheck());
    }

    private function avsLetter(): ?string
    {
        $avs = $this->data['paymentOption']['card']['avsCode'] ?? null;

        return $avs === null || $avs === '' ? null : (string) $avs;
    }
}
