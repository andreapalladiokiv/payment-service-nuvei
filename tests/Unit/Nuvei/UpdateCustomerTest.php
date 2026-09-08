<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Nuvei\NuveiGateway;
use Techork\PaymentService\Nuvei\UpdateCustomer;

/**
 * {@see UpdateCustomer} is a no-op that echoes the customer reference it was given back as the
 * transaction reference — so the only thing it can get wrong is receiving that reference at all.
 *
 * It used to get it wrong, and these tests pin the mechanism rather than the wish.
 * `Omnipay\Common\Helper::initialize()` applied a constructor option only where the target exposed
 * a matching `set…()`; anything else was discarded silently. The class extended `AbstractRequest`
 * and mixed in a trait, and NEITHER declared `setCustomerReference` — that method lived on the
 * payment request base, which this one did not extend. The parameter therefore could not arrive
 * through the only door the gateway facade used, `getData()` fell to its `?? ''` default, and every
 * update answered with an empty reference, i.e. an unsuccessful response.
 *
 * The whole defect class is gone with the bag: the reference is a constructor argument, and the
 * only way to build the operation without one is to say so. What is left worth testing is the echo
 * itself and the shape the empty case takes.
 */
it('echoes the existing customer reference straight back as the transaction reference', function () {
    // The whole contract of the class: Nuvei has no update endpoint for a user's
    // address, so a successful "update" is the unchanged userTokenId coming back.
    $result = new UpdateCustomer('user@example.com')->update();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('user@example.com');
});

it('carries no address on the wire, since Nuvei has nowhere to put one', function () {
    // payload() is the whole request body. A billing address is not accepted at all now, which is
    // the documented behaviour stated as a signature rather than as a field quietly dropped.
    expect(new UpdateCustomer('user@example.com')->payload())
        ->toHaveCount(1)
        ->toHaveKey('customerReference', 'user@example.com');
});

it('never leaves the process, so it needs no rest client', function () {
    // Pinned as a property, not an accident: every other Nuvei operation takes
    // {@see \Techork\PaymentService\Nuvei\NuveiSettings} because it has a call to make, and a
    // future "let's actually push the address" edit would have to add one — visibly — rather than
    // reach for a client that was already there.
    $constructor = new ReflectionClass(UpdateCustomer::class)->getConstructor();

    expect($constructor?->getNumberOfParameters())->toBe(1)
        ->and($constructor?->getParameters()[0]->getName())->toBe('customerReference');
});

it('answers with the reference it was given for an update the facade builds', function () {
    // The production path end to end. It used to be a well-formed response reporting failure for
    // an operation that cannot fail, because the reference never arrived.
    $gateway = new NuveiGateway;
    $gateway->configure(new GatewayInfrastructure(
        Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        Mockery::mock(DecryptInterface::class),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
        [
            'merchantId' => 'mid-1',
            'merchantSiteId' => 'site-1',
            'secretKey' => 'secret-1',
            // Seeded so configure() does not reach Nuvei's getSessionToken endpoint.
            'sessionToken' => 'session-1',
        ],
    ));

    $result = $gateway->updateCustomer('user@example.com');

    expect($result->reference)->toBe('user@example.com')
        ->and($result->success)->toBeTrue();
});

it('degrades to an unsuccessful empty reference rather than a null one when nothing is set', function () {
    // All three fields pinned, because this shape is easy to "improve" by accident. The old
    // response was built from `customerReference ?? ''` and reported success as
    // `! empty($data['reference'])`, so an empty reference was UNSUCCESSFUL, the reference itself
    // stayed `''` rather than becoming null, and there was no message — the `error` key was never
    // set on this path.
    //
    // `?? ''` and not `?? null` was itself deliberate: the old
    // `CreateCustomerResponse::getTransactionReference()` would otherwise hand a null straight to
    // callers typed on string. Pinning the empty string stops a "fix" that only moves the crash
    // downstream, and pinning the message stops one that invents a reason nobody used to get.
    $result = new UpdateCustomer()->update();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBe('')
        ->and($result->message)->toBeNull();
});
