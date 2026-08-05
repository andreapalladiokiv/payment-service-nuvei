<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Omnipay\Common\Message\AbstractResponse;
use Override;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;

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
final class CreatePaymentMethodResponse extends AbstractResponse implements CardChecksProvider
{
    #[Override]
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

    #[Override]
    public function getTransactionReference(): ?string
    {
        $reference = $this->data['userPaymentOptionId']
            ?? $this->data['paymentOption']['userPaymentOptionId']
            ?? null;

        return $reference === null || $reference === '' ? null : (string) $reference;
    }

    #[Override]
    public function getMessage(): ?string
    {
        return $this->data['reason']
            ?? $this->data['gwErrorReason']
            ?? $this->data['errCode']
            ?? $this->data['transactionStatus']
            ?? null;
    }

    #[Override]
    public function getAddressLineCheck(): ?CheckResult
    {
        return $this->avs()[0];
    }

    #[Override]
    public function getPostalCodeCheck(): ?CheckResult
    {
        return $this->avs()[1];
    }

    #[Override]
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
