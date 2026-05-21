<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Nuvei\NuveiSchemeChecks;

it('maps AVS letters to (line, postal) tuples', function (string $letter, CheckResult $line, CheckResult $postal) {
    expect(NuveiSchemeChecks::avsToLineAndPostal($letter))->toBe([$line, $postal]);
})->with([
    // Both match
    ['Y', CheckResult::Pass, CheckResult::Pass],
    ['X', CheckResult::Pass, CheckResult::Pass],
    ['D', CheckResult::Pass, CheckResult::Pass],
    ['M', CheckResult::Pass, CheckResult::Pass],
    ['F', CheckResult::Pass, CheckResult::Pass],
    // Street match, postal mismatch
    ['A', CheckResult::Pass, CheckResult::Fail],
    ['B', CheckResult::Pass, CheckResult::Fail],
    // Postal match, street mismatch
    ['Z', CheckResult::Fail, CheckResult::Pass],
    ['P', CheckResult::Fail, CheckResult::Pass],
    ['W', CheckResult::Fail, CheckResult::Pass],
    // Neither
    ['N', CheckResult::Fail, CheckResult::Fail],
    ['C', CheckResult::Fail, CheckResult::Fail],
    // Unavailable (issuer/system attempted but failed)
    ['U', CheckResult::Unavailable, CheckResult::Unavailable],
    ['G', CheckResult::Unavailable, CheckResult::Unavailable],
    ['I', CheckResult::Unavailable, CheckResult::Unavailable],
    ['R', CheckResult::Unavailable, CheckResult::Unavailable],
    ['S', CheckResult::Unavailable, CheckResult::Unavailable],
    // Not requested / not eligible
    ['E', CheckResult::Unchecked, CheckResult::Unchecked],
]);

it('treats null/empty AVS letter as Unchecked', function (?string $letter) {
    expect(NuveiSchemeChecks::avsToLineAndPostal($letter))
        ->toBe([CheckResult::Unchecked, CheckResult::Unchecked]);
})->with([null, '']);

it('handles unknown AVS letters gracefully (Unchecked)', function () {
    expect(NuveiSchemeChecks::avsToLineAndPostal('?'))
        ->toBe([CheckResult::Unchecked, CheckResult::Unchecked]);
});

it('is case-insensitive on AVS letter input', function () {
    expect(NuveiSchemeChecks::avsToLineAndPostal('y'))
        ->toBe([CheckResult::Pass, CheckResult::Pass]);
});

it('maps CVV letters to CheckResult', function (string $letter, CheckResult $expected) {
    expect(NuveiSchemeChecks::cvvToCheckResult($letter))->toBe($expected);
})->with([
    ['M', CheckResult::Pass],
    ['N', CheckResult::Fail],
    ['S', CheckResult::Fail],   // "should have been present" — protocol violation
    ['P', CheckResult::Unchecked],
    ['U', CheckResult::Unavailable],
    ['X', CheckResult::Unavailable],
]);

it('treats null/empty CVV letter as Unchecked', function (?string $letter) {
    expect(NuveiSchemeChecks::cvvToCheckResult($letter))->toBe(CheckResult::Unchecked);
})->with([null, '']);
