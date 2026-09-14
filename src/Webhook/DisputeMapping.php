<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook;

/**
 * Nuvei's dispute vocabulary onto the two axes the aggregate is driven on.
 *
 * ## Why the table lives here
 *
 * Because nothing else can hold it. `GatewayDisputeRecorder` is handed a `GatewayId` and nothing
 * else, and `src/Laravel/composer.json` requires no provider package, so the recorder that reads a
 * snapshot cannot see this class — nor ConnexPay's, nor Stripe's. A recorder handed Nuvei's raw
 * `FC-M-RJCT` would either invent a status or refuse every case, and `'FC-M-RJCT'` is not a status
 * in any vocabulary. So the package that owns the provider's words owns the sentence that turns
 * them into ours, which is the same argument {@see \Techork\PaymentService\ConnexPay\Dispute\CaseMapping}
 * makes for itself and the same one {@see \Techork\PaymentService\Stripe\Dispute\StatusMapping} does.
 *
 * **What this class does not do is state a `DisputeStage` or a `DisputeStatus`.** It answers the
 * domain's own *spellings* — `'chargeback'`, which is `DisputeStage::Chargeback->value` — and
 * nothing more. Declaring either enum here would be a second copy of a vocabulary `Domain` owns,
 * which `§0.4` forbids; a string is not a vocabulary.
 *
 * ## The authoritative column is the one the reference calls "Stage"
 *
 * The reference's own table for these codes is headed `| Code | Stage / meaning |`, so the stage
 * half is documented per code and is not inferred from the code's shape: `FC` is a chargeback,
 * `IPA` is pre-arbitration, `INQ*` is an inquiry. That is why the stage is read from the unified
 * code **first** and from `Chargeback.Type` only as a fallback — reading it from `Type` alone
 * would file every escalated case as `chargeback` forever, because a case that was raised as a
 * chargeback keeps that `Type` when it moves into pre-arbitration, and the aggregate would never
 * learn it had moved. The two axes are read from one code and reported beside it.
 *
 * ## `Chargeback.Type` is the fallback, and only the fallback
 *
 * The reference documents both its values, so this half is complete:
 *
 * | `Chargeback.Type` | Stage |
 * |---|---|
 * | `Retrieval` | `inquiry` |
 * | `Chargeback` | `chargeback` |
 *
 * It is a fallback rather than the rule because a case's *kind at origin* is not its current
 * position, and it is a fallback rather than nothing because a delivery that carries the unified
 * code is a newer shape than the one the docs' own second example shows. A delivery with the
 * fallback and no unified code places the stage and no status, which the recorder refuses on
 * opening and leaves alone on a move — see {@see \Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot}.
 *
 * ## What is refused, and why refusing is the answer
 *
 * An unmapped code answers `null`, and `null` is a statement about this table rather than about the
 * case: Nuvei publishes more codes than are listed below, the reference lists several of them only
 * as families and two of them as prose rather than as a stage, and the honest answer for those is
 * that we cannot place the case. The caller must refuse the delivery loudly rather than default,
 * which the router turns into a retry and then a visibly `Failed` webhook row — a case an operator
 * can see and a table entry away from being placed, as against a case filed on a guessed phase with
 * another phase's deadline and evidence set. Deliberately **not** mapped, and this is the list to
 * grow first:
 *
 * - `FC-SPCSE` — "dispute response not allowed", which says what we may do, not where the case is.
 * - `MCC*` — Mastercard Collaboration. The reference names the *process*, not a stage, and
 *   collaboration is not one of the four stages; mapping it would be our inference rather than a
 *   quotation, and the same reading of ConnexPay's `CaseType` 24 is already a decision its own
 *   package owns.
 * - `RDR` — resolved by Rapid Dispute Resolution. A case that never became a chargeback and is
 *   already over has no stage in this cycle, and `DisputeStage` has no value for it.
 *
 * ## The status half is a reading, and the rows that are inferences say so inline
 *
 * Nuvei documents each code's *meaning*, and for most of these that meaning is the status: a
 * chargeback requiring our response is `needs_response`, one the network has taken up after our
 * response is `under_review`, a case closed in our favour is `won`. Two rows are readings rather
 * than quotations, and they are marked as such where they are written. The reference's own note is
 * that `DisputeUnifiedStatusCode` is "correlated with `Chargeback.Status`" — which is exactly the
 * correlation this table cannot verify from the documentation alone, and why the codes it does not
 * list are refused rather than extrapolated into.
 */
final class DisputeMapping
{
    /**
     * The unified status codes the reference names literally, each onto the two spellings it
     * stands in.
     *
     * One table rather than two, so that the halves cannot drift apart: a code whose stage says
     * `chargeback` and whose status says `won` is a shape this vocabulary does not have, and two
     * tables would make it writable.
     *
     * @var array<string, array{stage: string, status: string}>
     */
    private const array BY_UNIFIED_STATUS = [
        // The first chargeback, initiated by the issuer — and the DMN's own description of itself
        // is "a new chargeback/dispute, which requires your response", so the status is quoted
        // rather than read.
        'FC' => ['stage' => 'chargeback', 'status' => 'needs_response'],

        // Merchant responses. The case is with the network and nothing is waiting on us, which is
        // `DisputeStatus::UnderReview`'s own definition.
        'FC-M-RJCT' => ['stage' => 'chargeback', 'status' => 'under_review'],
        'FC-M-PART' => ['stage' => 'chargeback', 'status' => 'under_review'],

        // Our reading, grounded in `DisputeStatus::Accepted`'s docblock rather than in Nuvei's
        // page: that case is reached by our own deliberate close and says *why* we stopped fighting,
        // and "the provider will report the same case as `lost` afterwards, because its API knows
        // only that the merchant stopped fighting". A provider code cannot express `accepted` —
        // `DisputeAggregate::open()` refuses it as a provider signal — so a merchant's acceptance
        // arriving as a code is `lost`, which is what the ledger reads an acceptance as anyway.
        'FC-M-ACPT' => ['stage' => 'chargeback', 'status' => 'lost'],

        // "No response, chargeback expired" — quoted. Note that this lands on a transition the
        // status table deliberately does not allow from `under_review`: a case we responded to and
        // the issuer then let lapse is refused by `DisputeAggregate` and reported `Skipped`. That
        // guard is the domain's own decision (see `DisputeStatus`), not something to route around
        // by mapping the code to something reachable.
        'FC-A-EPRD' => ['stage' => 'chargeback', 'status' => 'expired'],

        // Closed in the merchant's favour / the cardholder's.
        'FC-CLSD-MF' => ['stage' => 'chargeback', 'status' => 'won'],
        'FC-CLSD-CHF' => ['stage' => 'chargeback', 'status' => 'lost'],

        // Pre-arbitration, initiated by the issuer: the case has escalated out of the chargeback
        // phase and a response window is ours again.
        'IPA' => ['stage' => 'pre_arbitration', 'status' => 'needs_response'],

        'PA-CLSD-MF' => ['stage' => 'pre_arbitration', 'status' => 'won'],
        'PA-CLSD-CHF' => ['stage' => 'pre_arbitration', 'status' => 'lost'],
    ];

    /**
     * The families the reference prints with a wildcard rather than enumerating.
     *
     * `INQ*`, `IPA-M-*` and `MPA-I-*` are the docs' own spelling, so a prefix match is the only
     * faithful implementation of those rows — the alternative is to wait for a captured payload to
     * name each member and refuse the whole family until then. It is a prefix and not a shape: the
     * source names the family, and this matches what the source names.
     *
     * Ordered longest-first is not needed — {@see self::row()} tries the exact table first, so
     * `IPA` cannot swallow `IPA-M-…` — but the rows must not overlap each other, and they do not.
     *
     * @var array<string, array{stage: string, status: string}>
     */
    private const array BY_UNIFIED_STATUS_PREFIX = [
        // An inquiry: a request for information, so the response window is ours.
        'INQ' => ['stage' => 'inquiry', 'status' => 'needs_response'],

        // Pre-arbitration *responses* — ours to the issuer's, and the issuer's to ours. Either way
        // the case has been answered and is with the network, which is the same reading as the
        // `FC-M-*` rows above.
        'IPA-M-' => ['stage' => 'pre_arbitration', 'status' => 'under_review'],
        'MPA-I-' => ['stage' => 'pre_arbitration', 'status' => 'under_review'],
    ];

    /**
     * `Chargeback.Type`, the case's kind at origin — the fallback for a delivery that carries no
     * unified status code. Both values are the reference's own, so this half is complete.
     *
     * @var array<string, string>
     */
    private const array STAGES_BY_TYPE = [
        'Retrieval' => 'inquiry',
        'Chargeback' => 'chargeback',
    ];

    /**
     * The stage the case is in, read from the unified code where it settles it and from the case's
     * own type otherwise — or null when neither places it.
     *
     * The two arguments are both the payload's, and the order between them is the whole of the
     * escalation rule: a case raised as a `Chargeback` that has moved into pre-arbitration is
     * `pre_arbitration` here, which the `Type` alone could never say.
     */
    public static function stage(string $type, string $unifiedStatusCode): ?string
    {
        $row = self::row($unifiedStatusCode);

        if ($row !== null) {
            return $row['stage'];
        }

        return self::STAGES_BY_TYPE[$type] ?? null;
    }

    /**
     * The status the unified code means, in the domain's spelling — or null when this table does
     * not cover the code, which is a mapping gap and not a case with no status.
     */
    public static function status(string $unifiedStatusCode): ?string
    {
        return self::row($unifiedStatusCode)['status'] ?? null;
    }

    /**
     * The row a code answers, exact match first and the documented families second — or null.
     *
     * An empty code answers null rather than matching a prefix by accident: `str_starts_with()`
     * with an empty needle is true for every haystack, so a delivery with no
     * `DisputeUnifiedStatusCode` would otherwise be placed in the first family in the list.
     *
     * @return array{stage: string, status: string}|null
     */
    private static function row(string $unifiedStatusCode): ?array
    {
        if ($unifiedStatusCode === '') {
            return null;
        }

        if (isset(self::BY_UNIFIED_STATUS[$unifiedStatusCode])) {
            return self::BY_UNIFIED_STATUS[$unifiedStatusCode];
        }

        foreach (self::BY_UNIFIED_STATUS_PREFIX as $prefix => $row) {
            if (str_starts_with($unifiedStatusCode, $prefix)) {
                return $row;
            }
        }

        return null;
    }
}
