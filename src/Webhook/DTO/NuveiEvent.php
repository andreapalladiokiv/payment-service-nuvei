<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook\DTO;

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

    public function pppTransactionId(): string
    {
        return (string) ($this->payload['PPP_TransactionID'] ?? '');
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
     * Processor fee for this transaction in major-currency units (e.g.
     * "0.30" for 30¢ on a USD transaction). Nuvei DMN docs name the
     * field `feeAmount`. Returns 0.0 when absent or not yet booked.
     */
    public function feeAmount(): float
    {
        return (float) ($this->payload['feeAmount'] ?? 0);
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
}
