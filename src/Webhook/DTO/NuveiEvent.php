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
     * This is the same value `NuveiTransactionResponse::getTransactionReference()`
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
        $raw = $this->clientUniqueId();
        $colon = strpos($raw, ':');

        return $colon === false ? $raw : substr($raw, 0, $colon);
    }

    private function parseMoney(string $raw): Money
    {
        $code = $this->currency();
        if ($code === '') {
            throw new RuntimeException(
                sprintf('Nuvei DMN names no currency; refusing to assume one for amount "%s".', $raw),
            );
        }

        return new DecimalMoneyParser(new ISOCurrencies)->parse($raw, new Currency($code));
    }
}
