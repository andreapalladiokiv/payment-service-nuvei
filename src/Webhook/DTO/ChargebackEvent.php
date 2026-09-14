<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook\DTO;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\ReasonCodeNetwork;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot;
use Techork\PaymentService\Nuvei\Webhook\DisputeMapping;

/**
 * One Nuvei Chargeback event DMN, read the way the dispute recorder needs it.
 *
 * ## Why this is a class and not a handful of array reads in the handler
 *
 * A Chargeback delivery is nested three deep — the envelope, `Chargeback`, `TransactionDetails` —
 * and every field in it is a string that may be absent, so a handler reading them inline would be
 * fifteen `?? ''`s in a row with no place to say what any of them mean. The reads are also the only
 * place this package touches Nuvei's dispute vocabulary, and that vocabulary is the thing that
 * changes: the DMN is documented as a *Control Panel event* whose field set has already changed once
 * (the reference's own two examples disagree about `StatusCategory` versus `DisputeId` /
 * `DisputeUnifiedStatusCode`). One reader means one place to look when Nuvei moves.
 *
 * ## The card brand is read off the reason code's namespace, because the payload has none
 *
 * The documented field list carries no card brand anywhere, and {@see DisputeSnapshot} requires
 * one — the evidence requirements are keyed on the `(brand, code)` pair, so a case with no network
 * is not an incomplete case but an unanswerable one. What the payload does carry is
 * `Chargeback.ChargebackReason`, `"10.4 - Other Fraud-Card Absent Environment"`, and the networks
 * issue their reason codes in structurally disjoint namespaces, so {@see ReasonCodeNetwork} can name
 * the network that issues this one.
 *
 * That is a *read*, not a guess, and it is deliberately allowed to fail: a code in no list of ours
 * — a network we do not model, or one of Visa's two dozen VCR codes this table has not caught up
 * with — answers null and the delivery is **refused** by {@see self::snapshot()}, naming the case and
 * the code. A failed webhook row is visible and is one table entry from working; a case filed
 * against the nearest network's evidence requirements is neither, and it is final.
 *
 * ## The reason code is reduced by the documented separator, and nothing else is inferred
 *
 * Nuvei sends the code and its human description as one string, and the documented shape of that
 * string is `"<code> - <description>"`. So the code is what precedes the first `" - "`, and a
 * delivery that does not have that separator keeps the whole trimmed value — which then matches no
 * namespace and is refused. The alternative, trimming to the first whitespace-delimited token, would
 * accept shapes the documentation does not describe and would be a pattern we noticed rather than a
 * format Nuvei prints.
 *
 * ## The two ids in the payload are not the same id
 *
 * `Chargeback.DisputeEventId` is the dispute suite's id for **this delivery** and is what the
 * aggregate compares as its idempotency key — {@see \Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal}
 * names it as Nuvei's value for that. The envelope's `EventId` identifies the delivery to the
 * host's own webhook storage; it is not this field, and using it here would key the aggregate on a
 * different string from the one the domain's own value object documents.
 *
 * ## What this leaves at its default, and why
 *
 * `outcomeCode`, `waitingOnCode`, `hasResponse` and `familyRef` are ConnexPay's position signals and
 * stay null: Nuvei states none of them, and a Nuvei case never changes its reference. `fees` stays
 * empty because the documented field list carries no fee code at all — Nuvei books dispute fees
 * elsewhere, and the `{code, amount, chargedAt}` shape could only hold one by inventing a code.
 *
 * `stageCode` and `statusCode` both carry `DisputeUnifiedStatusCode`, and that is not a duplication:
 * it is the provider's own word for the case's position, speaking to both axes at once, and the
 * aggregate wants it as the code that tells two visits to one stage apart. `stage` and `status` are
 * the spellings {@see DisputeMapping} reads out of it beside the code.
 */
final readonly class ChargebackEvent
{
    /** The separator Nuvei prints between a reason code and its description. */
    private const string REASON_SEPARATOR = ' - ';

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(private array $payload) {}

    public static function from(NuveiEvent $event): self
    {
        return new self($event->payload);
    }

    /**
     * Nuvei's reference for the case — `Chargeback.DisputeId`.
     *
     * Returned verbatim, including when it is blank: {@see DisputeSnapshot} refuses a blank
     * reference with a message about which contract it broke, and pre-checking it here would turn
     * that refusal into a different one further away from the field that was missing.
     */
    public function disputeReference(): string
    {
        return $this->case('DisputeId');
    }

    /** Nuvei's own key for this delivery — see the class docblock on why it is not the envelope's id. */
    public function providerEventKey(): string
    {
        return $this->case('DisputeEventId');
    }

    /** The case's kind at origin — `Chargeback` or `Retrieval`. */
    public function type(): string
    {
        return $this->case('Type');
    }

    /** The unified status code — one code speaking to both axes. See {@see DisputeMapping}. */
    public function unifiedStatusCode(): string
    {
        return $this->case('DisputeUnifiedStatusCode');
    }

    /**
     * The scheme's reason code, reduced from `Chargeback.ChargebackReason` — see the class docblock
     * for what the reduction does and does not infer.
     */
    public function reasonCode(): string
    {
        $raw = trim($this->case('ChargebackReason'));
        $separator = strpos($raw, self::REASON_SEPARATOR);

        return trim($separator === false ? $raw : substr($raw, 0, $separator));
    }

    /** The issuer's own description of the reason — `Chargeback.ReasonMessage`. */
    public function reasonMessage(): string
    {
        return $this->case('ReasonMessage');
    }

    /**
     * `TransactionDetails.ClientUniqueId` — our own value, echoed back, verbatim.
     *
     * The raw value, kept beside {@see self::clientUniqueIdUuid()} rather than instead of it,
     * because the two are the same pair {@see NuveiEvent} exposes and the difference between them is
     * the difference between resolving a transaction and resolving nothing: a follow-up op's id ends
     * in `:<verb>`, and only the UUID portion addresses an aggregate.
     */
    public function clientUniqueId(): string
    {
        return $this->transaction('ClientUniqueId');
    }

    /**
     * The UUID portion of the above, by {@see NuveiEvent::uuidOfClientUniqueId()} — the same rule
     * the payment handlers apply to the same string, at the top level of their payload.
     *
     * This is the value a handler resolves a PaymentIntent by, and it is the more robust of the two
     * the payload offers: it is *our* id, so it does not depend on which of the two transactions the
     * DMN names — an open question the plan carries, and one this path does not have to answer.
     */
    public function clientUniqueIdUuid(): string
    {
        return NuveiEvent::uuidOfClientUniqueId($this->clientUniqueId());
    }

    /** `TransactionDetails.TransactionId` — the gateway-side transaction the case was raised against. */
    public function transactionId(): string
    {
        return $this->transaction('TransactionId');
    }

    /**
     * The disputed amount, from `Chargeback.Amount` and `Chargeback.Currency`.
     *
     * Null when either is missing, which is the DTO's own meaning for "Nuvei did not state it" —
     * `DisputeSnapshot::$disputedAmount` is optional on purpose and `OpenDisputeCommand` refuses to
     * open a case without a positive amount, so an unpriced case is refused loudly at the point
     * where an amount is required rather than guessed at here.
     *
     * The pairing is `Amount` / `Currency` and not `ReportedAmount` / `ReportedCurrency`: the
     * reported pair is what the cardholder's bank stated, which crosses a currency boundary on a
     * cross-border case, while the disputed amount is the one in our settlement currency — the
     * ledger's.
     *
     * **UNCONFIRMED — read `Amount` as major units.** Every other Nuvei amount this package reads is
     * a major-unit decimal string and is parsed as one by {@see NuveiEvent::money()}, and this
     * follows that without evidence that this particular field agrees. If the dispute suite states
     * it in minor units instead, every disputed amount is a hundredfold wrong — which no invariant
     * would catch, since a positive amount is all the aggregate asks. A captured payload settles it,
     * and it is on the plan's UNCONFIRMED list.
     */
    public function amount(): ?Money
    {
        $raw = $this->case('Amount');
        $currency = strtoupper($this->case('Currency'));

        if ($raw === '' || $currency === '') {
            return null;
        }

        return NuveiEvent::money($raw, $currency);
    }

    /**
     * `Chargeback.DisputeDueDate` — the deadline to respond.
     *
     * Null when absent or unreadable, both of which mean the same thing to the recorder: a case can
     * be raised before it has a window, and a deadline nobody can read is not one to invent. Null on
     * a move leaves the deadline the case already holds alone, which is the recorder's own rule for
     * an axis the provider said nothing about.
     */
    public function dueDate(): ?DateTimeImmutable
    {
        return self::instant($this->case('DisputeDueDate'));
    }

    /**
     * The provider's clock — the envelope's `EventDateUTC`, falling back to `EventDate`.
     *
     * The signal's moment is "when Nuvei said it", not when we got round to reading it: a day-old
     * delivery retried today must not be filed as today's, and a projection ordering a case's own
     * history reads these values. UTC is preferred because it is the unambiguous one; `EventDate` is
     * a local spelling and is used only when the UTC field is missing, which is better than the
     * current moment and worse than the real one.
     *
     * The fallback to now is for a payload with no envelope date at all, which is not a shape Nuvei
     * sends; it is there so a hand-built test payload is readable rather than a 1970 timestamp.
     */
    public function observedAt(): DateTimeImmutable
    {
        return self::instant($this->payload('EventDateUTC'))
            ?? self::instant($this->payload('EventDate'))
            ?? new DateTimeImmutable;
    }

    /**
     * The case as Nuvei currently states it, ready for
     * `GatewayDisputeRecorder::onDisputeObserved()`.
     *
     * @throws InvalidArgumentException when the payload cannot be represented — see
     *                                  {@see self::cardBrand()}, and note that a blank reference,
     *                                  reason code or event key is refused by {@see DisputeSnapshot}
     *                                  itself.
     */
    public function snapshot(): DisputeSnapshot
    {
        $unifiedStatusCode = $this->unifiedStatusCode();

        return new DisputeSnapshot(
            gatewayDisputeRef: $this->disputeReference(),
            cardBrand: $this->cardBrand(),
            reasonCode: $this->reasonCode(),
            providerEventKey: $this->providerEventKey(),
            observedAt: $this->observedAt(),
            // Nuvei's own word, and null when it stated none — the older example shape carries no
            // `DisputeUnifiedStatusCode` at all, and that is the provider staying silent rather than
            // a case with no position.
            stageCode: $unifiedStatusCode === '' ? null : $unifiedStatusCode,
            statusCode: $unifiedStatusCode === '' ? null : $unifiedStatusCode,
            // …and beside them the domain's reading, which is null for a code this table does not
            // cover. The recorder refuses that rather than defaulting: a code Nuvei has added and we
            // have not yet mapped must be seen, not filed under a guess. Where the code is absent
            // entirely the stage falls back to `Chargeback.Type` and the status stays null.
            stage: DisputeMapping::stage($this->type(), $unifiedStatusCode),
            status: DisputeMapping::status($unifiedStatusCode),
            disputedAmount: $this->amount(),
            responseDueAt: $this->dueDate(),
        );
    }

    /**
     * The network the case was raised on, read off the reason code's namespace.
     *
     * @throws InvalidArgumentException when the code's network is not one this service holds a list
     *                                  for — see the class docblock. The message names the case and
     *                                  the code, because the fix is a line in
     *                                  {@see ReasonCodeNetwork} and the reader needs to know which
     *                                  one to add.
     */
    private function cardBrand(): CardBrand
    {
        $reasonCode = $this->reasonCode();

        return ReasonCodeNetwork::issuing($reasonCode) ?? throw new InvalidArgumentException(sprintf(
            'Nuvei chargeback "%s" states "%s" as its reason code, and no network list held by %s '
            . 'issues that code. A Chargeback DMN carries no card brand of its own, so the network is '
            . 'read off the code — and the snapshot requires one, because it is half of the '
            . '(brand, code) pair the evidence requirements are keyed on. The delivery is refused '
            . 'rather than filed against the nearest network.',
            $this->disputeReference(),
            $reasonCode,
            ReasonCodeNetwork::class,
        ));
    }

    /** A string field of the `Chargeback` object, or `''` when the object or the field is absent. */
    private function case(string $key): string
    {
        $case = $this->payload['Chargeback'] ?? null;

        return is_array($case) ? (string) ($case[$key] ?? '') : '';
    }

    /** A string field of `TransactionDetails`, or `''` when the object or the field is absent. */
    private function transaction(string $key): string
    {
        $details = $this->payload['TransactionDetails'] ?? null;

        return is_array($details) ? (string) ($details[$key] ?? '') : '';
    }

    /** A string field of the envelope, or `''` when it is absent. */
    private function payload(string $key): string
    {
        return (string) ($this->payload[$key] ?? '');
    }

    /**
     * A Nuvei timestamp, or null when there is nothing readable there.
     *
     * Unreadable is folded into absent rather than thrown on: the envelope's dates are not facts the
     * case depends on — {@see self::dueDate()} and {@see self::observedAt()} both have a defined
     * answer without one — so a delivery whose date line is garbage must not take the case down with
     * it. `DateMalformedStringException` extends `Exception` on 8.3 and up, and the bare `Exception`
     * that older shapes threw is the same class here.
     */
    private static function instant(string $raw): ?DateTimeImmutable
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return null;
        }
    }
}
