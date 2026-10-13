<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Installer;

use Kommandhub\PaystackSW\Checkout\Payment\Handler\PaystackPaymentHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;

readonly class PaymentMethodInstaller
{
    /**
     * @param EntityRepository<PaymentMethodCollection> $paymentMethodRepository
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private EntityRepository $paymentMethodRepository,
        private PluginIdProvider $pluginIdProvider,
        private EntityRepository $salesChannelRepository
    ) {
    }

    /**
     * Installs the Paystack payment method if it doesn't exist.
     *
     * On first creation the method is assigned to the shop's sales channels so
     * it is selectable at checkout straight after installation; the cart
     * validator keeps it from being used until the API keys are configured.
     * An existing method is only refreshed, never re-assigned, so a merchant who
     * deliberately removed it from a channel does not get it back on every update.
     *
     * @param string $pluginClass The base class of the plugin
     * @param Context $context The Shopware context
     */
    public function install(string $pluginClass, Context $context): void
    {
        $paymentId = $this->getPaymentMethodId($context);

        if ($paymentId !== null) {
            $this->paymentMethodRepository->update([
                [
                    'id' => $paymentId,
                    'handlerIdentifier' => PaystackPaymentHandler::class,
                    'active' => true,
                ],
            ], $context);

            return;
        }

        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass($pluginClass, $context);

        $this->paymentMethodRepository->create([
            [
                'handlerIdentifier' => PaystackPaymentHandler::class,
                'name' => 'Pay with Paystack',
                'description' => 'Securely pay with card, bank transfer or mobile money via Paystack.',
                'pluginId' => $pluginId,
                'technicalName' => 'kmh_paystack_payment',
                'afterOrderEnabled' => true,
                'active' => true,
                'salesChannels' => $this->getSalesChannelAssignments($context),
            ],
        ], $context);
    }

    /**
     * Builds the sales channel association payload for every sales channel.
     *
     * @return list<array{id: string}>
     */
    private function getSalesChannelAssignments(Context $context): array
    {
        $ids = $this->salesChannelRepository->searchIds(new Criteria(), $context)->getIds();

        return array_map(
            static fn (string $id): array => ['id' => $id],
            $ids
        );
    }

    /**
     * Activates the Paystack payment method.
     *
     * @param Context $context The Shopware context
     */
    public function activate(Context $context): void
    {
        $this->setPaymentMethodActive(true, $context);
    }

    /**
     * Deactivates the Paystack payment method.
     *
     * @param Context $context The Shopware context
     */
    public function deactivate(Context $context): void
    {
        $this->setPaymentMethodActive(false, $context);
    }

    /**
     * Sets the active state of the Paystack payment method.
     *
     * @param bool $active Whether the payment method should be active
     * @param Context $context The Shopware context
     */
    private function setPaymentMethodActive(bool $active, Context $context): void
    {
        $paymentId = $this->getPaymentMethodId($context);

        if ($paymentId === null) {
            return;
        }

        $this->paymentMethodRepository->update([
            [
                'id' => $paymentId,
                'active' => $active,
            ],
        ], $context);
    }

    /**
     * Retrieves the ID of the Paystack payment method if it exists.
     *
     * @param Context $context The Shopware context
     *
     * @return string|null The payment method ID or null if not found
     */
    private function getPaymentMethodId(Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('technicalName', 'kmh_paystack_payment')
        );

        $paymentId = $this->paymentMethodRepository
            ->searchIds($criteria, $context)
            ->firstId();

        if ($paymentId !== null) {
            return $paymentId;
        }

        $criteria = (new Criteria())->addFilter(
            new EqualsFilter('handlerIdentifier', PaystackPaymentHandler::class)
        );

        return $this->paymentMethodRepository
            ->searchIds($criteria, $context)
            ->firstId();
    }
}
