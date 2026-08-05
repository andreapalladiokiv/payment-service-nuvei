<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;

/**
 * Maps Nuvei response letter codes (Visa/Mastercard scheme standard) into the
 * normalized {@see CheckResult} enum.
 *
 * One AVS letter is decomposed into two normalized fields (street vs postal)
 * because schemes encode both into a single character.
 *
 * Lives inside the Nuvei package — not in the shared gateway package — so
 * each gateway owns its own raw-format translation. Nuvei reports under
 * `paymentOption.card.avsCode` and `paymentOption.card.cvv2Reply`.
 */
final readonly class NuveiSchemeChecks
{
    /**
     * @return array{0: CheckResult, 1: CheckResult}
     */
    public static function avsToLineAndPostal(?string $letter): array
    {
        if ($letter === null || $letter === '') {
            return [CheckResult::Unchecked, CheckResult::Unchecked];
        }

        return match (strtoupper($letter)) {
            'Y', 'X', 'D', 'M', 'F' => [CheckResult::Pass, CheckResult::Pass],
            'A', 'B' => [CheckResult::Pass, CheckResult::Fail],
            'Z', 'P', 'W' => [CheckResult::Fail, CheckResult::Pass],
            'N', 'C' => [CheckResult::Fail, CheckResult::Fail],
            'U', 'G', 'I', 'R', 'S' => [CheckResult::Unavailable, CheckResult::Unavailable],
            default => [CheckResult::Unchecked, CheckResult::Unchecked],
        };
    }

    public static function cvvToCheckResult(?string $letter): CheckResult
    {
        if ($letter === null || $letter === '') {
            return CheckResult::Unchecked;
        }

        return match (strtoupper($letter)) {
            'M' => CheckResult::Pass,
            'N', 'S' => CheckResult::Fail,
            'U', 'X' => CheckResult::Unavailable,
            default => CheckResult::Unchecked,
        };
    }
}
