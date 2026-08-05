<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook;

use Override;
use Techork\PaymentService\Nuvei\Webhook\Handler\AuthHandler;
use Techork\PaymentService\Nuvei\Webhook\Handler\CreditHandler;
use Techork\PaymentService\Nuvei\Webhook\Handler\PaymentMethodCreationHandler;
use Techork\PaymentService\Nuvei\Webhook\Handler\SaleHandler;
use Techork\PaymentService\Nuvei\Webhook\Handler\SettleHandler;
use Techork\PaymentService\Nuvei\Webhook\Handler\VoidHandler;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookSubscriber;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;

final readonly class NuveiWebhookSubscriber implements WebhookSubscriber
{
    private const string KIND = 'Nuvei';

    public function __construct(
        private ChecksumVerifier $verifier,
        private EventParser $parser,
        private AuthHandler $auth,
        private PaymentMethodCreationHandler $paymentMethodCreation,
        private SaleHandler $sale,
        private SettleHandler $settle,
        private CreditHandler $credit,
        private VoidHandler $void,
    ) {}

    #[Override]
    public function subscribe(VerifierRegistry $verifiers, HandlerRegistry $handlers): void
    {
        $verifiers->register(self::KIND, $this->verifier, $this->parser);

        $handlers->register(self::KIND, EventParser::TYPE_AUTH, $this->auth);
        $handlers->register(self::KIND, EventParser::TYPE_AUTH_PAYMENT_METHOD, $this->paymentMethodCreation);
        $handlers->register(self::KIND, EventParser::TYPE_SALE, $this->sale);
        $handlers->register(self::KIND, EventParser::TYPE_SETTLE, $this->settle);
        $handlers->register(self::KIND, EventParser::TYPE_CREDIT, $this->credit);
        $handlers->register(self::KIND, EventParser::TYPE_VOID, $this->void);
    }
}
