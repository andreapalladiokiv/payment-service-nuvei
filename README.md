# Nuvei gateway

`techork/payment-service-nuvei` — acquiring gateway for
[Nuvei](https://docs.nuvei.com/) built on the official
`nuvei/nuvei-server-php` SDK. Supports server-to-server card payments
(tokenized only), the hosted **Cashier** payment page, refunds, payouts and
DMN webhooks. The Laravel bridge auto-discovers it via `extra.laravel` in
`composer.json` (`NuveiGateway` + `Webhook\NuveiWebhookSubscriber`).

## Configuration

`NuveiGateway::initialize()` parameters:

| Parameter | Meaning |
| --- | --- |
| `merchantId` | Nuvei merchant id |
| `merchantSiteId` | Nuvei site id |
| `secretKey` | Merchant secret key (request checksums) |
| `environment` | `Nuvei\Api\Environment::TEST` (default) or `LIVE` |
| `sessionToken` | Optional; when omitted the gateway fetches one from Nuvei during `initialize()` (skipped while `merchantId` is empty, i.e. during the bare `AbstractGateway` constructor call) |

## Operations

| Operation | Request class | Nuvei endpoint | Notes |
| --- | --- | --- | --- |
| `purchase` | `PurchaseRequest` | `payment.do` (`Sale`) — or Cashier form | `HostedPayment` instrument switches to the hosted flow (below) |
| `authorize` | `AuthorizeRequest` | `payment.do` (`Auth`, `settleType=0`) | Pre-auth hold |
| `capture` | `CaptureRequest` | `settleTransaction.do` | By `transactionReference` |
| `refund` | `RefundRequest` | `refundTransaction.do` | By `transactionReference` |
| `retryRefund` | `PayoutRequest` | `payout.do` | Visa OCT / Mastercard MoneySend; independent of the original sale. Raw PANs rejected (PCI scope) — Token/PaymentMethod only |
| `void` | `VoidRequest` | `voidTransaction.do` | Full-amount void; see quirks |
| `createCard` | `CreateCardRequest` | `cardTokenization.do` | Raw `CreditCard` → `ccTempToken` |
| `createPaymentMethod` | `CreatePaymentMethodRequest` | `addUPOCreditCardByTempToken.do` | `ccTempToken` → permanent UPO (`userPaymentOptionId`) |
| `createCustomer` | `CreateCustomerRequest` | `createUser.do` | `userTokenId` = customer email |
| `updateCustomer` | `UpdateCustomerRequest` | — | No-op; echoes existing reference |
| `issueVirtualCard` etc. | — | — | Throw `RuntimeException` (no issuing) |

Instrument mapping in `NuveiPaymentRequest` (visitor): `Token` →
`card.ccTempToken`, `PaymentMethod` → `userPaymentOptionId` +
`storedCredentialsMode: '1'`, raw `CreditCard` throws (tokenize first via
`createCard`), `Cash` throws. An external 3DS result (`ThreeDSResult`) is
forwarded as `threeD.externalMpi` (`eci`, `cavv`, `dsTransID`).

Customer resolution: Nuvei requires the owning `userTokenId` when charging a
stored UPO. `NuveiGateway::resolveCustomerReference()` looks the reference up
through the injected `CustomerRepository`, creates the Nuvei user from the
billing address email on a miss, and persists the link. Empty-string legacy
links count as missing; an empty `userTokenId` is omitted from requests, never
sent as `''` (Nuvei rejects it).

## Responses

Payment-operation responses (purchase / authorize / capture / refund /
payout / void) extend `NuveiTransactionResponse`; the tokenization and
customer responses are plain Omnipay `AbstractResponse`s. Success means
`status=SUCCESS` **and** `transactionStatus=APPROVED`; `getMessage()` falls
back `reason` → `gwErrorReason` → `errCode`. It implements three Gateway
contracts:

- `ChallengeProvider` — builds a `ThreeDSChallenge` from
  `paymentOption.card.threeD.acsUrl` when `transactionStatus=REDIRECT` or
  3DS `result=C`.
- `CardChecksProvider` — `NuveiSchemeChecks` maps the scheme AVS letter
  (`avsCode`) into separate street/postal `CheckResult`s and `cvv2Reply`
  into a CVC check.
- `ConvertedAmountProvider` — parses the DCC/MCP `currencyConversion` block
  into a `Money` in the converted currency (null when no FX happened).

`sendData()` never throws: any `Throwable` is wrapped into an `ERROR`
response.

## Hosted payments (Cashier)

`purchase` with a `HostedPayment` instrument makes **no REST call**. It
returns a `PENDING` response carrying a `RedirectChallenge` whose form the
browser POSTs to the Cashier (`https://ppp-test.nuvei.com/ppp/purchase.do` on
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

Correlation: requests send the caller's id as `clientUniqueId` — the
PaymentIntent UUID for top-level ops, or `<uuid>:<verb>` for follow-ups
(e.g. `:capture`); payment and void requests mirror the same id into
`clientRequestId` (capture/refund send `clientUniqueId` only). The
Auth/Sale/Settle handlers recover the UUID via
`NuveiEvent::clientUniqueIdUuid()` and skip payloads that don't carry one.
`PayloadParser` extracts card metadata (`cardCompany`/`bin`/`last4Digits`) and
the billing address from the DMN, backfilling missing fields with
`ShreddingStubs` sentinels.

## Quirks

- **Void**: `NuveiPaymentService` overrides the SDK's `voidTransaction()`,
  which wrongly marks `amount`/`currency` as mandatory. They are optional per
  Nuvei docs and must be omitted — sending a value that differs from the
  original auth by even a cent gets rejected with "Invalid Amount". Voids are
  therefore always full-amount.
- `PayoutRequest` bypasses the SDK's `Payments\Payout` service and computes
  its own request checksum (also covering `clientUniqueId` + `userTokenId`,
  which the SDK's omits) before posting to `payout.do`.
- `CreateCustomerRequest` defaults `firstName`/`lastName` to `N/A` and
  `countryCode` to `US` when absent; the "transaction reference" it returns is
  the email/`userTokenId`, not a Nuvei-generated id.
