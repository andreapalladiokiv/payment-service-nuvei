<?php

declare(strict_types=1);

use Nuvei\Api\Environment;
use Nuvei\Api\Exception\ConfigurationException;
use Nuvei\Api\Exception\ConnectionException;
use Nuvei\Api\Exception\ResponseException;
use Nuvei\Api\Exception\ValidationException;
use Nuvei\Api\RestClient;
use Techork\PaymentService\Nuvei\NuveiPaymentService;

/**
 * PROBE, not a regression test. Sibling of ConnexPayThreeDSFieldProbeTest and it
 * borrows that file's discipline: controls that make a positive result readable,
 * a repeated baseline, and every hold released.
 *
 * WHY NUVEI NEEDS ONE. Their docs answer part of the MIT chain and are silent on
 * the rest, and the silent part is what our adapter has to send.
 *
 *  Q1 Is our registration a valid anchor? Nuvei requires relatedTransactionId on a
 *     subsequent MIT — "the transactionId from the response to the initial
 *     transaction" — and separately blesses a zero-amount Auth as the way to store
 *     a card, and NEVER joins the two. Every rebilling example they publish uses a
 *     real-value payment. So: does a zero-amount Auth's transactionId work as the
 *     anchor, or not?
 *
 *  Q2 Where does the rebilling block nest? Their pages show isRebilling at the
 *     request root; our storedCredentials sits at the root of paymentOption. A
 *     field at the wrong level is IGNORED, not rejected, so guessing produces a
 *     transaction that looks fine and is not in a chain.
 *
 *  Q3 Should we be sending storedCredentialsMode at all? The REST 1.0 reference
 *     says merchants "using Nuvei's tokenization feature should not send this
 *     parameter", and we do use it — every payment goes out on a
 *     userPaymentOptionId.
 *
 *  Q4 Does /payment accept externalMpi together with the rebilling flags? A 3RI
 *     cryptogram on a renewal needs both in one body.
 *
 * THE CONTROL THAT MAKES Q1 READABLE. "Approved with our anchor" proves nothing on
 * its own: it is also what happens if Nuvei never validates the anchor. So the same
 * request goes out with a BOGUS relatedTransactionId. If that is approved too, the
 * approval carries no information and the probe says so instead of claiming a
 * result.
 *
 * SAFETY. Sandbox only (Environment::TEST). One zero-amount Auth to register, then
 * 1.00 USD `Auth` transactions — authorizations, never settled — each voided
 * immediately. Every request carries its own clientUniqueId (NUVREBILL-<n>-<ts>)
 * so anything left behind is findable, and the run prints a ledger of every
 * transactionId it created with its void result.
 *
 * Run:
 *   NUVEI_MERCHANT_ID=... NUVEI_MERCHANT_SITE_ID=... NUVEI_SECRET_KEY=... \
 *   vendor/bin/pest src/Nuvei/tests/Integration/NuveiRebillingProbeTest.php
 *
 * If the baseline declines, set NUVEI_TEST_CARD to a card number this sandbox
 * account approves — the probe is uninterpretable until the baseline is approved,
 * and it says so rather than reporting findings from failed calls.
 */
const NUVEI_PROBE_SKIP = 'Set NUVEI_MERCHANT_ID / NUVEI_MERCHANT_SITE_ID / NUVEI_SECRET_KEY to run the Nuvei rebilling probe.';

const NUVEI_PROBE_AMOUNT = '1.00';

const NUVEI_PROBE_CURRENCY = 'USD';

/** A transactionId that is well-formed and belongs to nobody. */
const NUVEI_PROBE_BOGUS_ANCHOR = '1110000000000001';

function nuveiProbeConfigured(): bool
{
    return (getenv('NUVEI_MERCHANT_ID') ?: '') !== ''
        && (getenv('NUVEI_MERCHANT_SITE_ID') ?: '') !== ''
        && (getenv('NUVEI_SECRET_KEY') ?: '') !== '';
}

/**
 * `sessionToken` is deliberately never passed: PaymentService::createPayment fetches
 * one itself when the key is absent, so the probe cannot fail on a token it fetched
 * differently from production.
 */
function nuveiProbeClient(): RestClient
{
    static $client = null;

    return $client ??= new RestClient([
        'environment' => Environment::TEST,
        'merchantId' => (string) getenv('NUVEI_MERCHANT_ID'),
        'merchantSiteId' => (string) getenv('NUVEI_MERCHANT_SITE_ID'),
        'merchantSecretKey' => (string) getenv('NUVEI_SECRET_KEY'),
    ]);
}

function nuveiProbeUserTokenId(): string
{
    static $id = null;

    return $id ??= 'probe-'.bin2hex(random_bytes(6)).'@example.com';
}

/**
 * The two blocks the SDK marks mandatory on /payment, in the shape
 * NuveiRequestParameters::formatBillingAddress builds — so a probe request differs
 * from a production one only by the field under test.
 *
 * @return array<string, mixed>
 */
function nuveiProbeEnvelope(): array
{
    return [
        'deviceDetails' => ['ipAddress' => '127.0.0.1'],
        'billingAddress' => [
            'firstName' => 'Probe',
            'lastName' => 'Tester',
            'email' => 'foundation-tests@example.com',
            'address' => '1 Test St',
            'city' => 'NYC',
            'country' => 'US',
            'zip' => '10001',
        ],
    ];
}

/** @return array<string, mixed> */
function nuveiProbeCard(): array
{
    return [
        'cardNumber' => (string) (getenv('NUVEI_TEST_CARD') ?: '4111111111111111'),
        'cardHolderName' => 'Probe Tester',
        'expirationMonth' => '12',
        'expirationYear' => '2030',
        'CVV' => '123',
    ];
}

/**
 * How a Nuvei response reports itself. `status` only says the API accepted the
 * request; `transactionStatus` is the outcome, and reading the former alone takes a
 * decline for a success — the same trap CreatePaymentMethodResponse documents.
 *
 * @param  array<string, mixed>  $result
 * @return array{outcome: string, detail: string, transactionId: ?string}
 */
function nuveiProbeRead(array $result): array
{
    $status = (string) ($result['status'] ?? '(none)');
    $transactionStatus = $result['transactionStatus'] ?? null;
    $transactionId = isset($result['transactionId']) && $result['transactionId'] !== ''
        ? (string) $result['transactionId']
        : null;

    $reason = $result['reason'] ?? $result['gwErrorReason'] ?? $result['errCode'] ?? $result['gwErrorCode'] ?? null;

    $outcome = match (true) {
        $status !== 'SUCCESS' => 'API-ERROR',
        $transactionStatus === 'APPROVED' => 'APPROVED',
        $transactionStatus === null => 'NO-TRANSACTION-STATUS',
        default => 'NOT-APPROVED:'.$transactionStatus,
    };

    return [
        'outcome' => $outcome,
        'detail' => trim(sprintf(
            'status=%s transactionStatus=%s%s',
            $status,
            $transactionStatus === null ? '(none)' : (string) $transactionStatus,
            $reason === null || $reason === '' ? '' : ' reason='.preg_replace('/\s+/', ' ', (string) $reason),
        )),
        'transactionId' => $transactionId,
    ];
}

/**
 * Registers the card exactly as CreatePaymentMethodRequest::verifyCard() does, so
 * the anchor under test is the one production would actually have.
 *
 * @return array{upo: ?string, anchor: ?string, read: array{outcome: string, detail: string, transactionId: ?string}}
 * @throws ConfigurationException
 * @throws ConnectionException
 * @throws ResponseException
 * @throws ValidationException
 */
function nuveiProbeRegister(): array
{
    $clientId = 'NUVREBILL-reg-'.time();

    $result = new NuveiPaymentService(nuveiProbeClient())->createPayment([
        'clientRequestId' => $clientId,
        'clientUniqueId' => $clientId,
        'userTokenId' => nuveiProbeUserTokenId(),
        'amount' => '0',
        'currency' => NUVEI_PROBE_CURRENCY,
        'transactionType' => 'Auth',
        'paymentOption' => ['card' => [
            ...nuveiProbeCard(),
            'storedCredentials' => ['storedCredentialsMode' => '0'],
        ]],
        ...nuveiProbeEnvelope(),
    ]);

    $read = nuveiProbeRead($result);
    $upo = $result['paymentOption']['userPaymentOptionId'] ?? $result['userPaymentOptionId'] ?? null;

    return [
        'upo' => $upo === null || $upo === '' ? null : (string) $upo,
        'anchor' => $read['transactionId'],
        'read' => $read,
    ];
}

/**
 * A 1.00 Auth on the stored UPO, with whatever the case under test adds.
 *
 * @param  array<string, mixed>  $root  merged into the request root
 * @param  array<string, mixed>  $card  merged into paymentOption.card
 * @return array{outcome: string, detail: string, transactionId: ?string}
 */
function nuveiProbeCharge(string $upo, int $case, array $root = [], array $card = [], bool $withStoredCredentials = true): array
{
    $clientId = sprintf('NUVREBILL-%02d-%d', $case, time());

    $paymentOption = ['userPaymentOptionId' => $upo];

    if ($withStoredCredentials) {
        // Exactly what the repo sends today, so a case that removes it is the only
        // thing that differs from production.
        $paymentOption['storedCredentials'] = ['storedCredentialsMode' => '1'];
    }

    if ($card !== []) {
        $paymentOption['card'] = $card;
    }

    try {
        $result = new NuveiPaymentService(nuveiProbeClient())->createPayment([
                'clientRequestId' => $clientId,
            'clientUniqueId' => $clientId,
            'userTokenId' => nuveiProbeUserTokenId(),
            'amount' => NUVEI_PROBE_AMOUNT,
            'currency' => NUVEI_PROBE_CURRENCY,
            'transactionType' => 'Auth',
            'paymentOption' => $paymentOption,
            ...nuveiProbeEnvelope(),
            ...$root,
        ]);
    } catch (Throwable $e) {
        return ['outcome' => 'EXCEPTION', 'detail' => $e::class.': '.$e->getMessage(), 'transactionId' => null];
    }

    return nuveiProbeRead($result);
}

/** Release the hold. Returns a one-line audit string for the ledger. */
function nuveiProbeVoid(?string $transactionId): string
{
    if ($transactionId === null) {
        return 'nothing to void';
    }

    $clientId = 'NUVREBILL-void-'.$transactionId;

    try {
        $result = new NuveiPaymentService(nuveiProbeClient())->voidTransaction([
            'clientRequestId' => $clientId,
            'clientUniqueId' => $clientId,
            'relatedTransactionId' => $transactionId,
            'amount' => NUVEI_PROBE_AMOUNT,
            'currency' => NUVEI_PROBE_CURRENCY,
        ]);

        return 'void: '.nuveiProbeRead($result)['detail'];
    } catch (Throwable $e) {
        return '!! NOT VOIDED — void by hand (clientUniqueId NUVREBILL-*): '.$e->getMessage();
    }
}

it('probes the Nuvei rebilling chain: anchor validity, nesting, and storedCredentialsMode', function () {
    $registration = nuveiProbeRegister();

    fwrite(STDERR, "\n=== Nuvei /payment rebilling probe (sandbox) ===\n".str_repeat('-', 118)."\n");
    fwrite(STDERR, sprintf(
        "registration (zero-amount Auth): %s | %s\n  UPO=%s  candidate anchor transactionId=%s\n",
        $registration['read']['outcome'],
        $registration['read']['detail'],
        $registration['upo'] ?? '(none)',
        $registration['anchor'] ?? '(none)',
    ));

    if ($registration['upo'] === null || $registration['anchor'] === null) {
        fwrite(STDERR,
            "\nABORTED — registration produced no UPO and/or no transactionId, so there is nothing to probe with.\n"
            ."If the card was declined, set NUVEI_TEST_CARD to one this sandbox account approves.\n");

        expect($registration['read']['outcome'])->toBe('APPROVED', 'registration failed: '.$registration['read']['detail']);

        return;
    }

    $upo = $registration['upo'];
    $anchor = $registration['anchor'];

    $rebilling = static fn (?string $relatedTransactionId): array => array_filter([
        'isRebilling' => '1',
        'rebillingType' => 'Recurring',
        'relatedTransactionId' => $relatedTransactionId,
    ], static fn ($v): bool => $v !== null);

    /** @var array<int, array{name: string, root: array<string, mixed>, card: array<string, mixed>, storedCredentials: bool}> $cases */
    $cases = [
        1 => ['name' => 'baseline (as we send today)', 'root' => [], 'card' => [], 'storedCredentials' => true],

        // Q3: does dropping the parameter the reference says we should not send
        // change anything at all?
        2 => ['name' => 'Q3 baseline WITHOUT storedCredentials', 'root' => [], 'card' => [], 'storedCredentials' => false],

        // Q1 + Q2: the real thing, at the root level their pages show.
        3 => ['name' => 'Q1 root rebilling + OUR anchor', 'root' => $rebilling($anchor), 'card' => [], 'storedCredentials' => true],

        // THE CONTROL for Q1. If this is approved too, case 3 means nothing.
        4 => ['name' => 'CONTROL root rebilling + BOGUS anchor', 'root' => $rebilling(NUVEI_PROBE_BOGUS_ANCHOR), 'card' => [], 'storedCredentials' => true],

        // Is the anchor enforced at all when the flags are present?
        5 => ['name' => 'Q1 root rebilling, NO anchor', 'root' => $rebilling(null), 'card' => [], 'storedCredentials' => true],

        // Q2: the other candidate placement.
        6 => ['name' => 'Q2 rebilling nested in paymentOption.card', 'root' => [], 'card' => $rebilling($anchor), 'storedCredentials' => true],

        // CONTROL-: unknown root field. Should be ignored, proving that a wrong
        // placement is silence rather than an error — which is why case 6 cannot be
        // read as "accepted, therefore correct".
        7 => ['name' => 'CONTROL- zzNoSuchField at root', 'root' => ['zzNoSuchField' => 'x'], 'card' => [], 'storedCredentials' => true],

        // Q4: a 3RI cryptogram on a renewal needs both blocks in one body.
        8 => [
            'name' => 'Q4 rebilling + externalMpi together',
            'root' => $rebilling($anchor),
            'card' => ['threeD' => ['externalMpi' => [
                'eci' => '05',
                'cavv' => 'AAABBEg0VhI0VniQEjRWAAAAAAA=',
                'dsTransID' => '9f8e7d6c-5b4a-3210-fedc-ba9876543210',
                'challengePreference' => 'NoPreference',
            ]]],
            'storedCredentials' => true,
        ],

        9 => ['name' => 'baseline repeated', 'root' => [], 'card' => [], 'storedCredentials' => true],
    ];

    $report = [];
    $ledger = [];

    foreach ($cases as $number => $case) {
        $read = nuveiProbeCharge($upo, $number, $case['root'], $case['card'], $case['storedCredentials']);
        $read['name'] = $case['name'];
        $report[$number] = $read;

        if ($read['transactionId'] !== null) {
            $ledger[] = sprintf('case %02d %s -> %s', $number, $read['transactionId'], nuveiProbeVoid($read['transactionId']));
        }
    }

    $line = str_repeat('-', 118)."\n";
    fwrite(STDERR, $line);
    foreach ($report as $number => $read) {
        fwrite(STDERR, sprintf("%2d %-42s %-22s %s\n", $number, $read['name'], $read['outcome'], $read['detail']));
    }
    fwrite(STDERR, $line."HOLDS CREATED AND RELEASED:\n");
    fwrite(STDERR, '  '.($ledger === [] ? '(none)' : implode("\n  ", $ledger))."\n".$line);

    // ── Findings ─────────────────────────────────────────────────────────────
    $baseline = $report[1];
    $approved = static fn (int $n): bool => $report[$n]['outcome'] === 'APPROVED';

    fwrite(STDERR, "FINDINGS:\n");

    if ($baseline['outcome'] !== 'APPROVED' || $report[9]['outcome'] !== $baseline['outcome']) {
        fwrite(STDERR, "  !! Baseline is not APPROVED, or drifted between the first case and the last. Nothing below is\n"
            ."     interpretable. Fix the baseline (NUVEI_TEST_CARD) before reading the table.\n");
    } else {
        fwrite(STDERR, '  CONTROL- unknown root field: '.$report[7]['outcome']
            .($approved(7) ? ' — unknown fields are ignored silently, so "accepted" never proves a field was read.' : ' — UNEXPECTED: read the row.')."\n");

        // Q1, and the control decides whether it can be answered at all.
        if ($approved(3) && $approved(4)) {
            fwrite(STDERR, "  Q1 INCONCLUSIVE — our anchor AND a bogus one were both approved. This sandbox does not validate\n"
                ."     relatedTransactionId, so approval carries no information about whether a zero-amount Auth is a\n"
                ."     valid anchor. Ask Nuvei directly; do not infer it from this run.\n");
        } elseif ($approved(3) && ! $approved(4)) {
            fwrite(STDERR, "  Q1 ANSWERED — our zero-amount Auth's transactionId was ACCEPTED as the anchor while a bogus one\n"
                ."     was REFUSED ({$report[4]['detail']}). The registration is a usable genesis, and the anchor is\n"
                ."     validated, so a wrong one would surface rather than pass silently.\n");
        } elseif (! $approved(3)) {
            fwrite(STDERR, "  Q1 ANSWERED, NEGATIVELY — our anchor was refused: {$report[3]['detail']}\n"
                ."     A zero-amount Auth does not anchor the chain; the genesis must be a real-value payment, which is\n"
                ."     what the domain's genesisPaymentIntentId already assumes.\n");
        }

        fwrite(STDERR, '  Q1b anchor enforced when the flags are present: '
            .($approved(5)
                ? 'NO — the flags were accepted with no anchor at all (case 5), so a missing anchor is silent.'
                : 'YES — omitting it was refused: '.$report[5]['detail'])."\n");

        fwrite(STDERR, '  Q2 nesting — root: '.$report[3]['outcome'].' | paymentOption.card: '.$report[6]['outcome']."\n");
        if ($approved(3) && $approved(6) && $approved(7)) {
            fwrite(STDERR, "     Both placements accepted, and so was a nonsense field, so acceptance does not identify the\n"
                ."     bound level. Only a case that REFUSES tells us anything here; if none did, the level has to come\n"
                ."     from Nuvei.\n");
        }

        fwrite(STDERR, '  Q3 storedCredentials removed: '.$report[2]['outcome']
            .($report[2]['outcome'] === $baseline['outcome']
                ? ' — same as baseline. Dropping the parameter the reference says tokenization users should not send changes nothing observable here.'
                : ' — DIFFERS from baseline; read the row before removing it.')."\n");

        fwrite(STDERR, '  Q4 rebilling + externalMpi in one body: '.$report[8]['outcome'].' '.$report[8]['detail']."\n");
    }

    fwrite(STDERR, $line."SCOPE: one sandbox account, Auth transactions only, all voided. A sandbox that does not validate a\n"
        ."       field says nothing about production, and this probe can only ever show what was ACCEPTED — never\n"
        ."       that a chain was actually recorded on the scheme side.\n".$line."\n");

    // Only the baseline is asserted. Everything else is a finding about the vendor.
    expect($baseline['outcome'])->toBe('APPROVED', 'baseline Auth failed, probe not interpretable: '.$baseline['detail']);
})->skip(! nuveiProbeConfigured(), NUVEI_PROBE_SKIP);
