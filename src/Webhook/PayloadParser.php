<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook;

use InvalidArgumentException;
use RuntimeException;
use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
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
        // The month is range-checked here, not downstream: createFromFormat does not
        // validate ranges — month 13 normalizes into January of the next year and
        // Expiration::fromMonthAndYear would never see anything wrong.
        if ($brand === null || $last4 === '' || $expMonth < 1 || $expMonth > 12 || $expYear === 0) {
            return null;
        }

        $first6 = (string) ($payload['bin'] ?? '');
        if ($first6 === '') {
            $first6 = str_pad('', 6, '0');
        }

        // What the range check cannot name (a two-digit year the format refuses) reads the same
        // way: the row has no card to record, so null. Throwing here would kill the whole DMN —
        // a webhook whose expiry line is garbage must not orphan the authorization it arrived
        // with.
        try {
            $expiration = Expiration::fromMonthAndYear($expMonth, $expYear);
        } catch (InvalidArgumentException) {
            return null;
        }

        return new CreditCard(
            number: new Number($first6, $last4, $brand),
            expiration: $expiration,
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

        return new BillingAddress(
            line: $line !== '' ? $line : ShreddingStubs::ADDRESS_LINE,
            city: $city !== '' ? $city : ShreddingStubs::CITY,
            country: $country !== '' ? self::country($country) : new Country(ShreddingStubs::COUNTRY),
            postalCode: $postalCode !== '' ? $postalCode : ShreddingStubs::POSTAL_CODE,
            lineExtra: '',
            state: $state !== '' ? new State($state) : null,
        );
    }

    /**
     * An unresolvable code (`ZZZ`, a numeric code no longer in CLDR) is recorded as "no data" —
     * the same treatment the stub philosophy already gives an absent country. Symfony Intl's
     * resolution failures all read as `RuntimeException` from this side of the call, so one
     * catch folds them.
     */
    private static function country(string $code): Country
    {
        try {
            return new Country($code);
        } catch (RuntimeException) {
            return new Country(ShreddingStubs::COUNTRY);
        }
    }

    /**
     * The person Nuvei's billing block names, which is the other half of what
     * {@see billingAddress()} used to return on its own.
     *
     * Same stub treatment and for the same reason: a name Nuvei did not send is recorded as "no
     * data" rather than left out, so a consumer reads one shape whether the field was never
     * given or has since been erased.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function customerIdentity(array $payload): CustomerIdentity
    {
        $firstName = (string) ($payload['firstName'] ?? '');
        $lastName = (string) ($payload['lastName'] ?? '');
        $email = (string) ($payload['email'] ?? '');

        return new CustomerIdentity(
            firstName: $firstName !== '' ? $firstName : ShreddingStubs::NAME,
            lastName: $lastName !== '' ? $lastName : ShreddingStubs::NAME,
            email: $email !== '' ? self::email($email) : null,
        );
    }

    /**
     * An email that fails the filter is recorded as null, which is what {@see CustomerIdentity}'s
     * nullable email already means by "no email on file" — an unparseable one is no email, not a
     * reason to drop the person it belonged to.
     */
    private static function email(string $value): ?Email
    {
        try {
            return new Email($value);
        } catch (RuntimeException) {
            return null;
        }
    }
}
