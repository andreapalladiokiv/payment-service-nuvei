<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Omnipay\Common\Message\AbstractRequest;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Nuvei\CreateCustomerResponse;
use Techork\PaymentService\Nuvei\NuveiGateway;
use Techork\PaymentService\Nuvei\UpdateCustomerRequest;

/**
 * {@see UpdateCustomerRequest} is a no-op that echoes the customer reference it
 * was given back as the transaction reference — so the only thing it can get
 * wrong is receiving that reference at all.
 *
 * It does get it wrong, and these tests pin the mechanism rather than the wish.
 * `Omnipay\Common\Helper::initialize()` applies a constructor option only where
 * the target exposes a matching `set…()`; anything else is discarded silently.
 * This class extends `AbstractRequest` directly and mixes in
 * `Concern\NuveiRequestParameters`, and NEITHER declares `setCustomerReference`
 * — that method lives on `NuveiPaymentRequest`, which this class does not
 * extend. The parameter therefore cannot arrive through the only door the
 * gateway facade uses, `getData()` falls to its `?? ''` default, and every
 * update answers with an empty reference, i.e. an unsuccessful response.
 *
 * The tests below are split deliberately: one group pins the read/echo path
 * working correctly once the parameter is present (reached by reflection,
 * because `setParameter` is protected in omnipay), the other pins that the
 * production path — the gateway facade — cannot make it present. That split is
 * what identifies the missing setter as the fault, instead of blaming getData().
 *
 * Helpers prefixed `nuveiNoOpUpdate…`; Pest helpers are global suite-wide and
 * `stripeUpdateCustomer*` / `nuveiFacade*` / `nuveiCustomerAddress` are taken.
 */
function nuveiNoOpUpdateRequest(array $options = []): UpdateCustomerRequest
{
    $request = new UpdateCustomerRequest(new OmnipayClient, new HttpRequest);
    $request->initialize($options);

    return $request;
}

/**
 * Places a parameter the way a working setter would, bypassing
 * `Helper::initialize()`. Reflection because omnipay declares `setParameter`
 * protected; this is the only way to observe the read path independently of
 * the broken write path.
 */
function nuveiNoOpUpdateWithReference(string $reference): UpdateCustomerRequest
{
    $request = nuveiNoOpUpdateRequest();
    new ReflectionMethod(AbstractRequest::class, 'setParameter')
        ->invoke($request, 'customerReference', $reference);

    return $request;
}

// ──────────────────────────────────────────────
//  the echo, once the reference is actually present
// ──────────────────────────────────────────────

it('echoes the existing customer reference straight back as the transaction reference', function () {
    // The whole contract of the class: Nuvei has no update endpoint for a user's
    // address, so a successful "update" is the unchanged userTokenId coming back.
    $response = nuveiNoOpUpdateWithReference('user@example.com')->send();

    expect($response)->toBeInstanceOf(CreateCustomerResponse::class)
        ->and($response->getTransactionReference())->toBe('user@example.com')
        ->and($response->isSuccessful())->toBeTrue();
});

it('needs no rest client, because it never leaves the process', function () {
    // Pinned as a property, not an accident: the gateway injects a RestClient
    // into every request it builds, and a future "let's actually push the
    // address" edit that reached for it would turn this offline call into a
    // network round trip on a path that currently cannot fail.
    $request = nuveiNoOpUpdateWithReference('user@example.com');

    expect($request->getParameters())->not->toHaveKey('restClient')
        ->and($request->send()->isSuccessful())->toBeTrue();
});

it('carries no address on the wire, since Nuvei has nowhere to put one', function () {
    // getData() is the whole request body. A billing address handed in is
    // accepted (the trait does have setBillingAddress) and then goes nowhere,
    // which is the documented behaviour rather than a silent drop.
    $request = nuveiNoOpUpdateWithReference('user@example.com');

    expect($request->getData())->toHaveCount(1)
        ->toHaveKey('customerReference', 'user@example.com');
});

// ──────────────────────────────────────────────
//  the write path, which is the defect
// ──────────────────────────────────────────────

it('receives the customerReference option it is given', function () {
    // The parameter bag is the proof. It used to be empty here: Helper::initialize() applies an
    // option only where a matching set…() exists, and this class had none — the accessor was
    // declared identically in three OTHER Nuvei requests and missing from the one whose only
    // parameter it is. It lives in the shared trait now.
    expect(nuveiNoOpUpdateRequest(['customerReference' => 'user@example.com'])->getParameters())
        ->toHaveKey('customerReference', 'user@example.com');
});

it('exposes the setter for the parameter it reads', function () {
    // Stated directly, because the absence of this method was the whole defect.
    expect(method_exists(UpdateCustomerRequest::class, 'setCustomerReference'))->toBeTrue();
});

it('answers with the reference it was given for an update the facade builds', function () {
    // The production path end to end. NuveiGateway::updateCustomer() passes the caller's options
    // through createRequest(), i.e. the same initialize() — so this is what the application
    // observes. It used to be a well-formed response reporting failure for an operation that
    // cannot fail, because the reference never arrived.
    $gateway = new NuveiGateway;
    $gateway->initialize([
        'merchantId' => 'mid-1',
        'merchantSiteId' => 'site-1',
        'secretKey' => 'secret-1',
        // Seeded so initialize() does not reach Nuvei's getSessionToken endpoint.
        'sessionToken' => 'session-1',
    ]);

    $response = $gateway->updateCustomer(['customerReference' => 'user@example.com'])->send();

    expect($response->getTransactionReference())->toBe('user@example.com')
        ->and($response->isSuccessful())->toBeTrue();
});

it('degrades to an empty reference rather than a null one when nothing is set', function () {
    // `?? ''` and not `?? null`: CreateCustomerResponse::getTransactionReference()
    // would hand a null straight to callers typed on string. The empty string is
    // the shape the failure takes, and pinning it stops a "fix" that only moves
    // the crash downstream.
    expect(nuveiNoOpUpdateRequest()->getData())->toBe(['customerReference' => '']);
});
