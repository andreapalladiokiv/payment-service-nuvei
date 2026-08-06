<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use Omnipay\Common\Message\AbstractResponse;
use Override;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;
use Techork\PaymentService\Gateway\Contract\ChallengeProvider;
use Techork\PaymentService\Gateway\Contract\ConvertedAmountProvider;

class NuveiTransactionResponse extends AbstractResponse implements CardChecksProvider, ChallengeProvider, ConvertedAmountProvider
{
    #[Override]
    public function isSuccessful(): bool
    {
        return ($this->data['status'] ?? '') === 'SUCCESS'
            && ($this->data['transactionStatus'] ?? '') === 'APPROVED';
    }

    #[Override]
    public function getTransactionReference(): ?string
    {
        return isset($this->data['transactionId']) ? (string) $this->data['transactionId'] : null;
    }

    #[Override]
    public function getMessage(): ?string
    {
        if (! empty($this->data['reason'])) {
            return $this->data['reason'];
        }

        if (! empty($this->data['gwErrorReason'])) {
            return $this->data['gwErrorReason'];
        }

        return isset($this->data['errCode']) && $this->data['errCode'] !== '0'
            ? "Error code: {$this->data['errCode']}"
            : null;
    }

    /**
     * FX-settled amount from Nuvei's DCC/MCP `currencyConversion` block, present
     * only when the transaction crossed a currency boundary (the cardholder
     * converted on the payment page or DCC was applied). Nuvei reports amounts
     * as decimal strings, so parse against the converted currency's ISO scale.
     * Null when no conversion block is present.
     */
    #[Override]
    public function getConvertedAmount(): ?Money
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

        return new DecimalMoneyParser(new ISOCurrencies())
            ->parse($amount, new Currency($currency));
    }

    #[Override]
    public function getChallenge(): ?Challenge
    {
        if (isset($this->data['challenge']) && $this->data['challenge'] instanceof Challenge) {
            return $this->data['challenge'];
        }

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
        $authenticationId = $this->getTransactionReference();

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

    #[Override]
    public function getAddressLineCheck(): ?CheckResult
    {
        $avs = $this->avsLetter();

        if ($avs === null) {
            return null;
        }

        return NuveiSchemeChecks::avsToLineAndPostal($avs)[0];
    }

    #[Override]
    public function getPostalCodeCheck(): ?CheckResult
    {
        $avs = $this->avsLetter();

        if ($avs === null) {
            return null;
        }

        return NuveiSchemeChecks::avsToLineAndPostal($avs)[1];
    }

    #[Override]
    public function getCvcCheck(): ?CheckResult
    {
        $cvv = $this->data['paymentOption']['card']['cvv2Reply'] ?? null;

        if ($cvv === null || $cvv === '') {
            return null;
        }

        return NuveiSchemeChecks::cvvToCheckResult($cvv);
    }

    private function avsLetter(): ?string
    {
        $avs = $this->data['paymentOption']['card']['avsCode'] ?? null;

        return $avs === null || $avs === '' ? null : (string) $avs;
    }
}
