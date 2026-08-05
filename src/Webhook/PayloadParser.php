<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook;

use DateMalformedStringException;
use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\State;

/**
 * Extracts card metadata and billing address from a Nuvei DMN payload. Both
 * the regular Auth handler (for side-effect UPO upsert) and the zero-amount
 * PaymentMethodCreation handler use this.
 */
final readonly class PayloadParser
{
    /**
     * @param array<string, mixed> $payload
     * @return CreditCard|null
     * @throws DateMalformedStringException
     */
    public static function creditCard(array $payload): ?CreditCard
    {
        $last4 = (string) ($payload['last4Digits'] ?? '');
        // Per Nuvei DMN docs, the network is in `cardCompany` (`cardType` is
        // `credit`/`debit`, not a network). Older payload variants used the
        // generic `brand` key — keep it as a fallback.
        $rawBrand = (string) ($payload['cardCompany'] ?? $payload['brand'] ?? '');
        $expMonth = (int) ($payload['ccExpMonth'] ?? 0);
        $expYear = (int) ($payload['ccExpYear'] ?? 0);

        $brand = self::mapNuveiBrand($rawBrand);
        if ($brand === null || $last4 === '' || $expMonth === 0 || $expYear === 0) {
            return null;
        }

        $first6 = (string) ($payload['bin'] ?? '');
        if ($first6 === '') {
            $first6 = str_pad('', 6, '0');
        }

        return new CreditCard(
            number: new Number($first6, $last4, $brand),
            expiration: Expiration::fromMonthAndYear($expMonth, $expYear),
            holder: new Holder((string) ($payload['nameOnCard'] ?? ShreddingStubs::NAME)),
            cvc: new Cvc,
        );
    }

    /**
     * Nuvei DMN documents the `cardCompany` field as one of: `Visa`, `Amex`,
     * `Mastercard`, `Diners`, `Discover`, `JCB`, `LaserCard`, `Maestro`,
     * `Solo`, `Switch` (PascalCase).
     *
     * @see https://docs.nuvei.com/documentation/integration/webhooks/payment-dmns/
     *
     * Six map 1:1 to the domain enum (case-insensitive). `Diners` is
     * shortened. `LaserCard`/`Solo`/`Switch` are deprecated UK/IE networks
     * with no domain counterpart — the parser skips them.
     */
    private static function mapNuveiBrand(string $value): ?CardBrand
    {
        return match ($value) {
            '', 'LaserCard', 'Solo', 'Switch' => null,
            'Diners' => CardBrand::DinersClub,
            default => CardBrand::tryFrom(strtolower($value)),
        };
    }

    /**
     * Missing required pieces are filled with the matching {@see ShreddingStubs}
     * sentinel rather than skipping the row — the address gets persisted with
     * the same "no data" marker GDPR-erased rows carry, keeping downstream
     * consumers on a single code path.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function billingAddress(array $payload): BillingAddress
    {
        $line = (string) ($payload['address'] ?? '');
        $city = (string) ($payload['city'] ?? '');
        $country = (string) ($payload['country'] ?? '');
        $postalCode = (string) ($payload['zip'] ?? '');

        $state = (string) ($payload['state'] ?? '');
        $email = (string) ($payload['email'] ?? '');
        $firstName = (string) ($payload['firstName'] ?? '');
        $lastName = (string) ($payload['lastName'] ?? '');

        return new BillingAddress(
            firstName: $firstName !== '' ? $firstName : ShreddingStubs::NAME,
            lastName: $lastName !== '' ? $lastName : ShreddingStubs::NAME,
            line: $line !== '' ? $line : ShreddingStubs::ADDRESS_LINE,
            city: $city !== '' ? $city : ShreddingStubs::CITY,
            country: new Country($country !== '' ? $country : ShreddingStubs::COUNTRY),
            postalCode: $postalCode !== '' ? $postalCode : ShreddingStubs::POSTAL_CODE,
            lineExtra: '',
            state: $state !== '' ? new State($state) : null,
            email: $email !== '' ? new Email($email) : null,
        );
    }
}
