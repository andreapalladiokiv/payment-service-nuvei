<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Concern;

use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Common\ValueObject\Customer;

/**
 * The formatting two or more Nuvei operations genuinely share.
 *
 * All that is left of a trait that used to be seventeen bag-backed accessors. Every one of those
 * values now arrives through a constructor — the command's, or
 * {@see \Techork\PaymentService\Nuvei\NuveiSettings} — so a value can no longer go missing because
 * a `set…()` was never declared. That was not hypothetical: `setCustomerReference` was written out
 * three times and omitted from the one request whose only parameter it was, and Omnipay's
 * initializer applies an option only where a matching setter exists, so every customer update
 * answered with an empty reference. A constructor argument cannot be forgotten in that direction.
 */
trait NuveiRequestParameters
{
    protected function formatMoney(Money $money): string
    {
        return new DecimalMoneyFormatter(new ISOCurrencies)->format($money);
    }

    /**
     * The body capture and refund share: both name an amount and the transaction they act on, and
     * nothing else. It was a base class the two requests extended; the two lines of difference
     * between them are now in the operations themselves, where they read as the endpoint each one
     * picks rather than as an override.
     *
     * @return array<string, mixed>
     */
    protected function relatedTransactionBody(Money $money, string $transactionReference, ?string $clientUniqueId): array
    {
        return [
            'clientUniqueId' => $clientUniqueId ?? Uuid::uuid4()->toString(),
            'amount' => $this->formatMoney($money),
            'currency' => $money->getCurrency()->getCode(),
            'relatedTransactionId' => $transactionReference,
        ];
    }

    /**
     * Nuvei's `billingAddress` block, which is the payer AND the place — its four person fields
     * sit in the same object as its six address ones. That is why this takes a whole
     * {@see Customer}: the person half used to be read off the {@see \Techork\PaymentService\Common\ValueObject\BillingAddress},
     * which is exactly how an address came to be the record of who was paying, and Nuvei went
     * furthest with it by keying its user on that address's email.
     *
     * @return array<string, string>
     */
    protected function formatBillingAddress(?Customer $customer): array
    {
        if ($customer === null) {
            return [];
        }

        $identity = $customer->identity;
        $address = $customer->billingAddress;

        return array_filter([
            'firstName' => $identity->firstName,
            'lastName' => $identity->lastName,
            'email' => $identity->email ? (string) $identity->email : null,
            'phone' => $identity->phone ? (string) $identity->phone : null,
            'address' => $address->line,
            'addressLine2' => $address->lineExtra !== '' ? $address->lineExtra : null,
            'city' => $address->city,
            'country' => (string) $address->country,
            'zip' => $address->postalCode,
            'state' => $address->state ? (string) $address->state : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
