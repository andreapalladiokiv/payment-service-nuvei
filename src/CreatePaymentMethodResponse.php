<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Omnipay\Common\Message\AbstractResponse;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;
use Techork\PaymentService\Gateway\Contract\StoredCredentialReferenceProvider;

/**
 * Reads either shape {@see CreatePaymentMethodRequest} can produce.
 *
 * `addUPOCreditCardByTempToken` answers flat, with `userPaymentOptionId` at the
 * top level. The zero-amount `Auth` answers like any other payment: the id is
 * nested under `paymentOption`, and the outcome is `transactionStatus` rather than
 * `status` — the latter only reports that the API accepted the request, so reading
 * it alone would take a decline for a success.
 *
 * The checks are the reason a card takes the payment route at all, and they exist
 * only on that shape. The vault route reports none, which surfaces here as null
 * rather than as a check that passed.
 */
final class CreatePaymentMethodResponse extends AbstractResponse implements CardChecksProvider, StoredCredentialReferenceProvider
{
    public function isSuccessful(): bool
    {
        if (($this->data['status'] ?? '') !== 'SUCCESS') {
            return false;
        }

        $transactionStatus = $this->data['transactionStatus'] ?? null;

        if ($transactionStatus !== null && $transactionStatus !== 'APPROVED') {
            return false;
        }

        return $this->getTransactionReference() !== null;
    }

    public function getTransactionReference(): ?string
    {
        $reference = $this->data['userPaymentOptionId']
            ?? $this->data['paymentOption']['userPaymentOptionId']
            ?? null;

        return $reference === null || $reference === '' ? null : (string) $reference;
    }

    /**
     * The `transactionId` of the zero-amount `Auth` this registration may have
     * placed — the only candidate anchor a registration can offer for the
     * `relatedTransactionId` a later merchant-initiated payment must quote.
     *
     * Candidate, not confirmed: Nuvei documents the anchor as the initial CIT's
     * transactionId and documents zero-amount authorization as the way to store a
     * card, without ever joining the two. See
     * {@see \Techork\PaymentService\Gateway\Contract\StoredCredentialReferenceProvider}.
     * Note also that `storedCredentialsMode`, which this repo sends, is a different
     * mechanism and is not what marks the chain.
     *
     * Only the payment route has one. `addUPOCreditCardByTempToken` is a vault
     * operation that reaches no issuer and begins no chain, and answers null here
     * rather than borrowing the UPO id, which would anchor the series to something
     * the acquirer has never seen as a transaction.
     */
    public function getStoredCredentialReference(): ?string
    {
        $reference = $this->data['transactionId'] ?? null;

        return $reference === null || $reference === '' ? null : (string) $reference;
    }

    public function getMessage(): ?string
    {
        return $this->data['reason']
            ?? $this->data['gwErrorReason']
            ?? $this->data['errCode']
            ?? $this->data['transactionStatus']
            ?? null;
    }

    public function getAddressLineCheck(): ?CheckResult
    {
        return $this->avs()[0];
    }

    public function getPostalCodeCheck(): ?CheckResult
    {
        return $this->avs()[1];
    }

    public function getCvcCheck(): ?CheckResult
    {
        $cvv = $this->data['paymentOption']['card']['cvv2Reply'] ?? null;

        return $cvv === null || $cvv === '' ? null : NuveiSchemeChecks::cvvToCheckResult($cvv);
    }

    /**
     * @return array{0: ?CheckResult, 1: ?CheckResult}
     */
    private function avs(): array
    {
        $letter = $this->data['paymentOption']['card']['avsCode'] ?? null;

        return $letter === null || $letter === ''
            ? [null, null]
            : NuveiSchemeChecks::avsToLineAndPostal($letter);
    }
}
