<?php

declare(strict_types=1);

use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Nuvei\Webhook\DisputeMapping;

/**
 * {@see DisputeMapping} is the only place Nuvei's dispute vocabulary becomes ours, and every row of it
 * is a claim about a provider page rather than about this codebase — so the rows are written out here
 * rather than sampled, and each table-wide check below is what a new row has to satisfy to be added.
 *
 * Nothing in this file asserts that Nuvei behaves as its documentation describes. It asserts what this
 * table answers, so that a change to the table is a change to a pinned list rather than a silent one.
 * The `statusCode` correlation with `Chargeback.Status`, and whether the 1.0 Chargeback DMN really
 * carries a unified status code at all, stay on the plan's UNCONFIRMED list; a captured payload is
 * what settles them.
 *
 * Pest helpers are global, so the closure over the row list is a local `$rows`, not a function.
 */

/** Every code the table answers literally, with the two spellings it answers. */
$rows = [
    'FC' => ['FC', 'chargeback', 'needs_response'],
    'FC-M-RJCT' => ['FC-M-RJCT', 'chargeback', 'under_review'],
    'FC-M-PART' => ['FC-M-PART', 'chargeback', 'under_review'],
    'FC-M-ACPT' => ['FC-M-ACPT', 'chargeback', 'lost'],
    'FC-A-EPRD' => ['FC-A-EPRD', 'chargeback', 'expired'],
    'FC-CLSD-MF' => ['FC-CLSD-MF', 'chargeback', 'won'],
    'FC-CLSD-CHF' => ['FC-CLSD-CHF', 'chargeback', 'lost'],
    'IPA' => ['IPA', 'pre_arbitration', 'needs_response'],
    'PA-CLSD-MF' => ['PA-CLSD-MF', 'pre_arbitration', 'won'],
    'PA-CLSD-CHF' => ['PA-CLSD-CHF', 'pre_arbitration', 'lost'],
];

it('places each documented unified status code in the stage and status it stands for', function (string $code, string $stage, string $status) {
    // The code is the authority for both axes — see the class docblock: the reference's own table is
    // headed `| Code | Stage / meaning |`, so the stage is quoted per code rather than read off the
    // case's origin type. `Chargeback.Type` is passed here as the case's kind at origin, which is what
    // it is, so a row that placed the code by its type would answer the same thing and this dataset
    // could not tell the two apart. That separation is the next test's job.
    expect(DisputeMapping::status($code))->toBe($status)
        ->and(DisputeMapping::stage('Chargeback', $code))->toBe($stage);
})->with($rows);

it('reads the stage from the code rather than from the case it was raised as, which is what makes an escalation visible', function () {
    // A case raised as a chargeback keeps `Type: Chargeback` for its whole life — Nuvei's `Type` is the
    // kind at origin, not the current position — so a table that read the stage from `Type` would file
    // every escalated case as `chargeback` forever and the aggregate would never learn it had moved.
    // The two deliveries below are the fixture pair, and they differ in nothing but the code.
    expect(DisputeMapping::stage('Chargeback', 'FC'))->toBe('chargeback')
        ->and(DisputeMapping::stage('Chargeback', 'IPA'))->toBe('pre_arbitration')
        ->and(DisputeMapping::status('IPA'))->toBe('needs_response');
});

it('falls back to the case\'s own type only for a delivery that carries no unified status code', function (string $type, string $stage) {
    // Both `Chargeback.Type` values are documented, so this half is complete — and it is a fallback
    // rather than the rule, for the reason above. It is reached only by the older delivery shape the
    // docs' own second example shows, which carries the type and no unified code.
    expect(DisputeMapping::stage($type, ''))->toBe($stage);
})->with([
    'a chargeback' => ['Chargeback', 'chargeback'],
    'a retrieval' => ['Retrieval', 'inquiry'],
]);

it('answers nothing for a case whose type it does not know and whose code it does not map', function () {
    // Two independent silences, and the case falls through both: `Type` is not a vocabulary of ours
    // and the code is not in the table. Nothing places this case, and the caller refuses it rather
    // than defaulting — which is the whole point of answering null instead of a plausible stage.
    expect(DisputeMapping::stage('SomethingNew', 'FC-SPCSE'))->toBeNull()
        ->and(DisputeMapping::status('FC-SPCSE'))->toBeNull();
});

it('places the wildcard families the reference prints with an asterisk, and only them', function (string $code, string $stage, string $status) {
    // `INQ*`, `IPA-M-*` and `MPA-I-*` are the docs' own spelling, so a prefix match is the only
    // faithful implementation of those rows: waiting for a captured payload to name each member would
    // refuse a whole documented family until then. The members below are invented spellings — a prefix
    // has no members to quote — which is exactly why this test asserts the prefix and not the code.
    expect(DisputeMapping::status($code))->toBe($status)
        ->and(DisputeMapping::stage('Chargeback', $code))->toBe($stage);
})->with([
    'an inquiry at its root' => ['INQ', 'inquiry', 'needs_response'],
    'an inquiry with a suffix' => ['INQ-1234', 'inquiry', 'needs_response'],
    'a pre-arbitration response of ours' => ['IPA-M-RJCT', 'pre_arbitration', 'under_review'],
    'a pre-arbitration response of the issuer\'s' => ['MPA-I-ACPT', 'pre_arbitration', 'under_review'],
]);

it('answers nothing at all for an empty code, rather than matching the first family by accident', function () {
    // The guard this test exists for: `str_starts_with()` with an empty needle is true for every
    // haystack, so without it a delivery carrying no `DisputeUnifiedStatusCode` would be filed in
    // whichever family happened to be listed first — an inquiry, silently, for every uncode case.
    // The empty code is the one input where the fallback is allowed to answer, and it does.
    expect(DisputeMapping::status(''))->toBeNull()
        ->and(DisputeMapping::stage('Retrieval', ''))->toBe('inquiry')
        ->and(DisputeMapping::stage('', ''))->toBeNull();
});

it('refuses the codes the reference names without placing them in a stage', function (string $code) {
    // `FC-SPCSE` says what we may do rather than where the case is; `MCC*` is a process name; `RDR` is
    // a case that never became a chargeback and is already over. Each is documented and none of them is
    // one of the four stages, so mapping any of them would be our inference rather than a quotation —
    // and `RDR` in particular has no stage in this cycle at all. An unmapped code answers null, the
    // recorder refuses the delivery, and the router turns that into a visibly failed webhook row: a
    // case an operator can see, one table entry away from being placed.
    expect(DisputeMapping::status($code))->toBeNull()
        ->and(DisputeMapping::stage('Chargeback', $code))->toBe('chargeback');

    // …and the refusal is on the status axis, which is where the recorder reads it: the code is stated
    // and no spelling stands beside it, which is the mapping gap `DisputeSnapshot` describes.
})->with([
    'a dispute response that is not allowed' => ['FC-SPCSE'],
    'a Mastercard collaboration step' => ['MCC-1'],
    'a case Rapid Dispute Resolution already settled' => ['RDR'],
    'a code Nuvei has added since' => ['FC-2ND-CB'],
]);

it('answers only spellings the enums it will be read as actually have', function () use ($rows) {
    // The strongest check available from here, and the one that catches a typo rather than a wrong
    // reading: every spelling this table answers is handed to the enum that will read it, and the
    // recorder resolves each one with `tryFrom()`. `'pre-arbitration'` instead of `'pre_arbitration'`
    // is a stage the domain does not have, and it would fail on a live delivery instead of here.
    foreach ($rows as [, $stage, $status]) {
        expect(DisputeStage::tryFrom($stage))->not->toBeNull()
            ->and(DisputeStatus::tryFrom($status))->not->toBeNull();
    }

    // The fallback half too, which is written in a second table and could drift from the first.
    foreach (['Chargeback', 'Retrieval'] as $type) {
        expect(DisputeStage::tryFrom((string) DisputeMapping::stage($type, '')))->not->toBeNull();
    }
});
