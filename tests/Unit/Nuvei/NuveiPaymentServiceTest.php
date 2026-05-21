<?php

declare(strict_types=1);

use Nuvei\Api\Service\PaymentService;
use Techork\PaymentService\Nuvei\NuveiPaymentService;

it('extends the SDK PaymentService so callers can drop-in replace it', function () {
    expect(is_subclass_of(NuveiPaymentService::class, PaymentService::class))->toBeTrue();
});

it('keeps amount and currency optional in voidTransaction (regression guard)', function () {
    // Sentinel: lock down that our override does not declare amount/currency
    // as mandatory fields. If a future SDK upgrade reintroduces the SDK
    // bug, this guard fails so we know to update the override.
    $reflect = new ReflectionMethod(NuveiPaymentService::class, 'voidTransaction');
    $body = file_get_contents($reflect->getFileName());
    $fnSrc = implode("\n", array_slice(
        explode("\n", $body),
        $reflect->getStartLine() - 1,
        $reflect->getEndLine() - $reflect->getStartLine() + 1,
    ));

    expect($fnSrc)->toContain("'relatedTransactionId'")
        ->and($fnSrc)->toContain('mandatoryFields');

    // Locate the $mandatoryFields assignment and confirm amount/currency
    // are NOT inside it.
    preg_match('/\$mandatoryFields\s*=\s*\[(.*?)\];/s', $fnSrc, $m);
    $list = $m[1] ?? '';
    expect($list)->not->toContain("'amount'")
        ->and($list)->not->toContain("'currency'");
});

it('overrides PaymentService::voidTransaction (declares its own)', function () {
    // The whole point of this class. If a future refactor removes the
    // override, behaviour silently regresses to the buggy SDK version.
    $reflect = new ReflectionMethod(NuveiPaymentService::class, 'voidTransaction');

    expect($reflect->getDeclaringClass()->getName())->toBe(NuveiPaymentService::class);
});
