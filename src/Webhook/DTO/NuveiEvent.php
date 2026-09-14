<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook\DTO;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use RuntimeException;

/**
 * Nuvei DMN event DTO. Nuvei's PHP SDK doesn't ship a rich event class, so we
 * wrap the parsed form-data body and expose typed accessors handlers use.
 */
final readonly class NuveiEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function transactionType(): string
    {
        return (string) ($this->payload['transactionType'] ?? '');
    }

    /**
     * The envelope's `EventType` — present on a **Control Panel event DMN** (a Chargeback, a
     * Dispute API callback, an Ethoca alert) and absent from every payment DMN, which carries
     * `transactionType` instead.
     *
     * The two are mutually exclusive in practice and that is why {@see EventParser} can treat this
     * as a discriminator rather than a second field to reconcile: a delivery that carried both
     * would be a shape neither channel documents.
     */
    public function eventType(): string
    {
        return (string) ($this->payload['EventType'] ?? '');
    }

    /**
     * The envelope's `EventId`: Nuvei's own id for **this delivery**, and the half of an event
     * DMN's external id that makes a redelivery recognisable.
     *
     * Not to be confused with `Chargeback.DisputeEventId`, which is the dispute suite's id for the
     * same delivery and is what {@see DisputeSignal} names as the provider's key on the aggregate.
     * They are two of Nuvei's ids for one thing; this one is the envelope's, so it exists for every
     * event type while the other exists only for disputes.
     */
    public function eventId(): string
    {
        return (string) ($this->payload['EventId'] ?? '');
    }

    public function status(): string
    {
        return (string) ($this->payload['Status'] ?? '');
    }

    /**
     * Raw `totalAmount` as Nuvei sends it — a MAJOR-unit value, so "10.50" is
     * $10.50 and not 10 cents. Safe only for presence/zero tests such as
     * telling the zero-amount tokenization Auth apart from a real one; use
     * {@see totalMoney} to build an amount, which applies the ISO scale.
     */
    public function totalAmount(): float
    {
        return (float) ($this->payload['totalAmount'] ?? 0);
    }

    public function currency(): string
    {
        return strtoupper((string) ($this->payload['currency'] ?? ''));
    }

    public function clientUniqueId(): string
    {
        return (string) ($this->payload['clientUniqueId'] ?? '');
    }

    /**
     * The DMN's `PPP_TransactionID`. This is **not** the id the REST API returns
     * as `transactionId`, and the two are not interchangeable — Nuvei numbers them
     * separately (our own wiring fixture shows `778899` against `1110000000123456`).
     *
     * Use it to identify the delivery, never to key a transaction we also reach
     * through the API: {@see transactionId} is the one that matches what an API
     * response gave us, and mixing the families writes references nothing can
     * later resolve.
     */
    public function pppTransactionId(): string
    {
        return (string) ($this->payload['PPP_TransactionID'] ?? '');
    }

    /**
     * The gateway-side transaction reference, DMN field `TransactionID`.
     *
     * This is the same value `NuveiTransactionOutcome::reference()`
     * reads off a synchronous API response, so a transaction we initiated ourselves
     * is already stored under it. `relatedTransactionId` is in this family too,
     * which is the reason a Credit DMN resolves its payment intent while a refund
     * keyed on `PPP_TransactionID` never matches the row the refund call wrote.
     *
     * There is deliberately no fallback to `PPP_TransactionID` when this is absent.
     * Falling back is what let two id families share one slot; a handler that
     * cannot name the transaction should refuse rather than record it under an id
     * nothing else uses.
     */
    public function transactionId(): string
    {
        return (string) ($this->payload['TransactionID'] ?? '');
    }

    public function relatedTransactionId(): string
    {
        return (string) ($this->payload['relatedTransactionId'] ?? '');
    }

    public function userPaymentOptionId(): string
    {
        return (string) ($this->payload['userPaymentOptionId'] ?? '');
    }

    public function userTokenId(): string
    {
        return (string) ($this->payload['userTokenId'] ?? '');
    }

    public function reason(): string
    {
        return (string) ($this->payload['Reason'] ?? $this->payload['ReasonCode'] ?? '');
    }

    /**
     * Transaction total, parsed against the DMN currency's ISO scale.
     *
     * @throws RuntimeException when the DMN names no currency — the amount
     *                          cannot be booked without one, and picking a
     *                          default silently mis-states the ledger.
     */
    public function totalMoney(): Money
    {
        return $this->parseMoney((string) ($this->payload['totalAmount'] ?? '0'));
    }

    /**
     * Processor fee Nuvei booked for this transaction (DMN field `feeAmount`,
     * a major-unit decimal such as "0.30"), or null when absent or zero —
     * there is nothing to record either way.
     *
     * @throws RuntimeException when the DMN names no currency.
     */
    public function feeMoney(): ?Money
    {
        $raw = $this->payload['feeAmount'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        $fee = $this->parseMoney((string) $raw);

        return $fee->isZero() ? null : $fee;
    }

    /**
     * `clientUniqueId` echoed back by Nuvei is the same string we sent
     * when initiating the call. Our convention (since the idempotency
     * refactor) is `<aggregateUuid>` for top-level ops or
     * `<aggregateUuid>:<verb>` for follow-up ops (capture / cancel).
     * This accessor returns the UUID portion only — handlers that
     * resolve aggregate ids should call this, not {@see clientUniqueId}.
     */
    public function clientUniqueIdUuid(): string
    {
        return self::uuidOfClientUniqueId($this->clientUniqueId());
    }

    /**
     * The convention above, as a rule rather than a method — because the same string arrives at two
     * different paths in two different DMN shapes: a payment DMN states it at the top level
     * (`clientUniqueId`, which {@see clientUniqueIdUuid()} reads) while a Chargeback event DMN
     * states it under `TransactionDetails.ClientUniqueId`.
     *
     * It is one rule, so it is one function: a second copy in a dispute DTO would be a second answer
     * to "which aggregate is this", and the two would drift the first time the convention gained a
     * third form.
     */
    public static function uuidOfClientUniqueId(string $raw): string
    {
        $colon = strpos($raw, ':');

        return $colon === false ? $raw : substr($raw, 0, $colon);
    }

    private function parseMoney(string $raw): Money
    {
        return self::money($raw, $this->currency());
    }

    /**
     * The rule every Nuvei amount follows, as a function — because the same rule reads two shapes:
     * a payment DMN states its totals at the top level and a Chargeback event DMN states the
     * disputed amount under `Chargeback`, and both are **major-unit decimal strings** parsed against
     * the currency's ISO scale, so `"10.50"` is $10.50 and not ten cents and a half.
     *
     * `$currency` is taken rather than read, and an absent one is refused rather than assumed:
     * picking a default silently mis-states the ledger, which is worse than a delivery that fails.
     *
     * @throws RuntimeException when no currency was named.
     */
    public static function money(string $raw, string $currency): Money
    {
        if ($currency === '') {
            throw new RuntimeException(
                sprintf('Nuvei DMN names no currency; refusing to assume one for amount "%s".', $raw),
            );
        }

        return new DecimalMoneyParser(new ISOCurrencies)->parse($raw, new Currency($currency));
    }
}
