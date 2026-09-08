# Nuvei gateway

`techork/payment-service-nuvei` — acquiring gateway for
[Nuvei](https://docs.nuvei.com/) built on the official
`nuvei/nuvei-server-php` SDK. Supports server-to-server card payments
(tokenized only), the hosted **Cashier** payment page, refunds, payouts and
DMN webhooks. The Laravel bridge auto-discovers it via `extra.laravel` in
`composer.json` (`NuveiGateway` + `Webhook\NuveiWebhookSubscriber`).

## Configuration

`NuveiGateway::configure(GatewayInfrastructure)` settings, read once into typed
properties and handed to every operation as a `NuveiSettings`:

| Setting | Meaning |
| --- | --- |
| `merchantId` | Nuvei merchant id |
| `merchantSiteId` | Nuvei site id |
| `secretKey` | Merchant secret key (request checksums) |
| `environment` | `Nuvei\Api\Environment::TEST` (default) or `LIVE` |
| `sessionToken` | Optional; when omitted the gateway fetches one from Nuvei during `configure()` (skipped while `merchantId` is empty, so a half-filled credential row cannot reach the network) |

`NuveiSettings` carries the configured `RestClient` too, so every operation of a
configured gateway talks over the same client and the same session.

## Operations

| Role | Operation class | Nuvei endpoint | Notes |
| --- | --- | --- | --- |
| `charge` | `Purchase` | `payment.do` (`Sale`) — or Cashier form | `HostedPayment` instrument switches to the hosted flow (below) |
| `authorize` / `authorizeRebilling` | `Authorize` | `payment.do` (`Auth`, `settleType=0`) | Pre-auth hold; a `RebillingCommand` adds the series block |
| `capture` | `Capture` | `settleTransaction.do` | By `transactionReference`, restating `transactionType=Settle` in the body |
| `refund` | `Refund` | `refundTransaction.do` | By `transactionReference` |
| `retryRefund` | `Payout` | `payout.do` | Visa OCT / Mastercard MoneySend; independent of the original sale. Raw PANs rejected (PCI scope) — Token/PaymentMethod only |
| `cancel` | `VoidTransaction` | `voidTransaction.do` | Cancelling an intent is voiding its authorization. Full-amount void; see quirks |
| `tokenize` | `Tokenize` | `cardTokenization.do` | Raw `CreditCard` → `ccTempToken` |
| `registerPaymentMethod` | `RegisterPaymentMethod` | `addUPOCreditCardByTempToken.do` for a `Token`, or `payment.do` (zero-amount `Auth`) for a raw `CreditCard` | `ccTempToken` → permanent UPO (`userPaymentOptionId`); the `Auth` route returns the UPO plus the issuer's AVS/CVV verdicts |
| `createCustomer` | `CreateCustomer` | `createUser.do` | `userTokenId` = customer email |
| `updateCustomer` | `UpdateCustomer` | — | No-op; echoes existing reference |
| `issueVirtualCard` etc. | — | — | Throw `UnsupportedOperation` (no issuing) |

Every operation has the same three parts: a typed constructor taking the
settings, whatever infrastructure it needs and the command; a pure `payload()`
that builds the provider body without touching the network; and one action
method that makes the call and returns the typed result (`GatewayResult` /
`AuthorizationResult` / `RegistrationResult`) directly. There is no request
object with a `getData()`/`sendData()` lifecycle and no response object wrapping
a payload for something else to re-read.

Each role on `NuveiGateway` builds its operation inline and invokes it; there is
no accessor handing one back unsent. A test that wants a payload constructs the
operation itself, since `NuveiSettings` is a public value object.

`payload()` is built OUTSIDE the `try` that wraps the call, which is load-bearing
rather than stylistic: omnipay's `send()` was `getData()` then `sendData()`, and
only the sending was ever wrapped. An `UnsupportedInstrument` or an
`IncompleteAuthentication` therefore propagates — both carry `UnsupportedByGateway`,
which the gateway stack rethrows rather than folding, so catching one would record
a wiring error as an acquirer decline for a request no acquirer saw.

Instrument mapping in `Concern\PaymentBody` (visitor): `Token` →
`card.ccTempToken`, `PaymentMethod` → `userPaymentOptionId` only (no
`storedCredentialsMode` is sent on payments; mode `'0'` is sent solely by
`registerPaymentMethod`'s zero-amount Auth), raw `CreditCard` throws (tokenize
first via `tokenize`), `Cash` throws. A rebilling series is marked instead by
the root-level `isRebilling`/`rebillingType`/`relatedTransactionId` block that
`authorizeRebilling` produces. An external 3DS result (`ThreeDSResult`) is
forwarded as `threeD.externalMpi` (`eci`, `cavv`, `dsTransID`).

Customer resolution: Nuvei requires the owning `userTokenId` when charging a
stored UPO. `NuveiGateway::resolveCustomerReference()` looks the reference up
through the injected `CustomerRepository`, creates the Nuvei user from the
billing address email on a miss, and persists the link. Empty-string legacy
links count as missing; an empty `userTokenId` is omitted from requests, never
sent as `''` (Nuvei rejects it).

## Reading an answer

Every transaction endpoint (payment / settle / refund / void / payout) replies
in one shape, and `NuveiTransactionOutcome` is the single place that reads it.
Success means `status=SUCCESS` **and** `transactionStatus=APPROVED`; the message
falls back `reason` → `gwErrorReason` → `errCode`. It folds the answer straight
into a result:

- `toAuthorizationResult()` for the placements — a `ThreeDSChallenge` built from
  `paymentOption.card.threeD.acsUrl` (or `methodUrl`, the fingerprinting step)
  when `transactionStatus=REDIRECT` or 3DS `result=C`, the AVS/CVV verdicts that
  `NuveiSchemeChecks` decodes from `avsCode` and `cvv2Reply`, the DCC/MCP
  `currencyConversion` block as a `Money` in the converted currency, and
  `opening_transaction_reference` metadata naming the transaction that opened
  the intent.
- `toGatewayResult()` for capture, refund, void and payout, which carry none of
  those signals and deliberately record no opening reference — a settle writing
  that key would bury the authorization's under its own.

A challenge is read before success: a payment awaiting a step-up is neither.
The two vault operations map their own answers, because Nuvei replies to them
in shapes nothing else uses — `Tokenize` reads `ccTempToken`, and
`RegisterPaymentMethod` reads both the flat vault shape and the nested payment
shape, with card checks only on the latter.

No operation lets a `Throwable` out: a call that never left becomes a failed
result carrying the reason, because an exception escaping here reaches the event
stream as an acquirer decline for a request no acquirer ever saw.

## Hosted payments (Cashier)

`charge` with a `HostedPayment` instrument makes **no REST call**. It returns
an `AuthorizationResult::requiresAction()` carrying a `RedirectChallenge` whose
form the browser POSTs to the Cashier (`https://ppp-test.nuvei.com/ppp/purchase.do` on
TEST, `https://secure.safecharge.com/ppp/purchase.do` on LIVE), checksum
`sha256(merchantId + siteId + amount + currency + timestamp + secretKey)`.
The outcome arrives asynchronously as a `Sale` DMN.

## Webhooks (DMN)

`NuveiWebhookSubscriber` registers `ChecksumVerifier` + `EventParser` under
kind `Nuvei`. `ChecksumVerifier` matches the payload's merchant pair against
the credential (`merchant_id`, `site_id`, `secret_key` keys from
`GatewayCredential::getCredentials()`) and validates both delivery shapes:
form-encoded **DMN** (`sha256(secret + totalAmount + currency +
responseTimeStamp + PPP_TransactionID + Status + productId)` in the body) and
JSON **Notification** (`EventCorrelationId` present, `sha256(secret + rawBody)`
in the `checksum` header).

| DMN `transactionType` | Handler | Effect |
| --- | --- | --- |
| `Auth`, amount > 0 | `AuthHandler` | Records authorization / decline on the PaymentIntent; best-effort UPO → PaymentMethod upsert |
| `Auth`, amount = 0 | `PaymentMethodCreationHandler` | Nuvei's tokenization flow — upserts a local PaymentMethod for the UPO |
| `Sale` | `SaleHandler` | Confirms the hosted Cashier purchase (challenge → `Charged`) |
| `Settle` | `SettleHandler` | Confirms capture; forwards `feeAmount` to the fee recorder |
| `Credit` | `CreditHandler` | Refund processed / failed (resolved via `relatedTransactionId`); forwards refund fee |
| `Void` | `VoidHandler` | Cancels the linked PaymentIntent (resolved via `relatedTransactionId`) |

Correlation: operations send the caller's id as `clientUniqueId` — the
PaymentIntent UUID for top-level ops, or `<uuid>:<verb>` for follow-ups
(e.g. `:capture`); the payment and void bodies mirror the same id into
`clientRequestId` (capture/refund send `clientUniqueId` only). The
Sale/Settle handlers recover the UUID via
`NuveiEvent::clientUniqueIdUuid()` and skip payloads that don't carry one; the
Auth handler matches the raw `clientUniqueId` as a whole UUID and skips
anything suffixed with `:<verb>`.
`PayloadParser` extracts card metadata (`cardCompany`/`bin`/`last4Digits`) and
the billing address from the DMN, backfilling missing fields with
`ShreddingStubs` sentinels.

## Quirks

- **Void**: `NuveiPaymentService` overrides the SDK's `voidTransaction()`,
  which wrongly marks `amount`/`currency` as mandatory. They are optional per
  Nuvei docs and must be omitted — sending a value that differs from the
  original auth by even a cent gets rejected with "Invalid Amount". Voids are
  therefore always full-amount.
- `Payout` bypasses the SDK's `Payments\Payout` service and computes
  its own request checksum (also covering `clientUniqueId` + `userTokenId`,
  which the SDK's omits) before posting to `payout.do`.
- `CreateCustomer` defaults `firstName`/`lastName` to `N/A` and
  `countryCode` to `US` when absent; the "transaction reference" it returns is
  the email/`userTokenId`, not a Nuvei-generated id.
