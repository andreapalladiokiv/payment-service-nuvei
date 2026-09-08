<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Concern;

use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Common\ValueObject\BillingAddress;

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
     * @return array<string, string>
     */
    protected function formatBillingAddress(?BillingAddress $address): array
    {
        if ($address === null) {
            return [];
        }

        return array_filter([
            'firstName' => $address->firstName,
            'lastName' => $address->lastName,
            'email' => $address->email ? (string) $address->email : null,
            'phone' => $address->phone ? (string) $address->phone : null,
            'address' => $address->line,
            'addressLine2' => $address->lineExtra !== '' ? $address->lineExtra : null,
            'city' => $address->city,
            'country' => (string) $address->country,
            'zip' => $address->postalCode,
            'state' => $address->state ? (string) $address->state : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
