<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use Omnipay\Common\Message\AbstractResponse;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;
use Techork\PaymentService\Gateway\Contract\ChallengeProvider;
use Techork\PaymentService\Gateway\Contract\ConvertedAmountProvider;

class NuveiTransactionResponse extends AbstractResponse implements CardChecksProvider, ChallengeProvider, ConvertedAmountProvider
{
    public function isSuccessful(): bool
    {
        return ($this->data['status'] ?? '') === 'SUCCESS'
            && ($this->data['transactionStatus'] ?? '') === 'APPROVED';
    }

    public function getTransactionReference(): ?string
    {
        return isset($this->data['transactionId']) ? (string) $this->data['transactionId'] : null;
    }

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
    public function getConvertedAmount(): ?Money
    {
        $conversion = $this->data['currencyConversion'] ?? null;
        if (! is_array($conversion)) {
            return null;
        }

        $amount = $conversion['convertedAmount'] ?? null;
        $currency = $conversion['convertedCurrency'] ?? null;
        if ($amount === null || $amount === '' || $currency === null || $currency === '') {
            return null;
        }

        return (new DecimalMoneyParser(new ISOCurrencies()))
            ->parse((string) $amount, new Currency((string) $currency));
    }

    public function getChallenge(): ?Challenge
    {
        if (isset($this->data['challenge']) && $this->data['challenge'] instanceof Challenge) {
            return $this->data['challenge'];
        }

        $threeD = $this->data['paymentOption']['card']['threeD'] ?? null;
        $redirectUrl = $this->data['paymentOption']['redirectUrl'] ?? null;
        $acsUrl = $threeD['acsUrl'] ?? $redirectUrl;

        if ($acsUrl === null || $this->getTransactionReference() === null) {
            return null;
        }

        $isChallenge = ($this->data['transactionStatus'] ?? '') === 'REDIRECT'
            || ($threeD['result'] ?? null) === 'C';

        if (! $isChallenge) {
            return null;
        }

        return new ThreeDSChallenge(
            transactionId: $this->getTransactionReference(),
            acsUrl: $acsUrl,
            creq: $threeD['cReq'] ?? $threeD['creq'] ?? null,
        );
    }

    public function getAddressLineCheck(): ?CheckResult
    {
        $avs = $this->avsLetter();

        if ($avs === null) {
            return null;
        }

        return NuveiSchemeChecks::avsToLineAndPostal($avs)[0];
    }

    public function getPostalCodeCheck(): ?CheckResult
    {
        $avs = $this->avsLetter();

        if ($avs === null) {
            return null;
        }

        return NuveiSchemeChecks::avsToLineAndPostal($avs)[1];
    }

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
