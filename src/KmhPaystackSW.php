<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW;

use Kommandhub\PaystackSW\Installer\CustomFieldsInstaller;
use Kommandhub\PaystackSW\Installer\PaymentMethodInstaller;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Main Shopware plugin class for Kmh Paystack integration.
 *
 * Responsibilities:
 * - Registers plugin services (via Symfony container)
 * - Manages lifecycle (install/activate/deactivate/uninstall)
 * - Creates and maintains Paystack payment method
 * - Installs custom fields required for integration
 */
class KmhPaystackSW extends Plugin
{
    /**
     * Allow composer commands during plugin execution.
     */
    public function executeComposerCommands(): bool
    {
        return true;
    }

    /**
     * Load additional service configuration files.
     *
     * This extends Shopware's DI container with custom YAML configurations.
     *
     * @throws \Exception
     *
     * @codeCoverageIgnore
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $locator = new FileLocator('Resources/config');

        $resolver = new LoaderResolver([
            new YamlFileLoader($container, $locator),
            new GlobFileLoader($container, $locator),
            new DirectoryLoader($container, $locator),
        ]);

        $loader = new DelegatingLoader($resolver);

        $configPath = rtrim($this->getPath(), '/') . '/Resources/config';

        // Load all package service definitions
        $loader->load($configPath . '/{packages}/*.yaml', 'glob');
    }

    /**
     * Plugin installation lifecycle hook.
     *
     * Creates payment method and installs required custom fields.
     */
    public function install(InstallContext $installContext): void
    {
        $context = $installContext->getContext();

        $this->getPaymentMethodInstaller()->install(static::class, $context);

        $installer = $this->getCustomFieldsInstaller();
        $installer->install($context);
        $installer->addRelations($context);
    }

    /**
     * Plugin update lifecycle hook.
     *
     * Re-runs the installers so the stored payment-method handlerIdentifier and
     * custom fields are migrated when classes move between versions. Without
     * this, an update (as opposed to a fresh install) leaves a dangling handler
     * identifier and checkout breaks.
     */
    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $context = $updateContext->getContext();

        $this->getPaymentMethodInstaller()->install(static::class, $context);

        $installer = $this->getCustomFieldsInstaller();
        $installer->install($context);
        $installer->addRelations($context);
    }

    /**
     * Plugin activation lifecycle hook.
     */
    public function activate(ActivateContext $activateContext): void
    {
        $this->getPaymentMethodInstaller()->activate($activateContext->getContext());

        parent::activate($activateContext);
    }

    /**
     * Plugin deactivation lifecycle hook.
     */
    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->getPaymentMethodInstaller()->deactivate($deactivateContext->getContext());

        parent::deactivate($deactivateContext);
    }

    /**
     * Plugin uninstall lifecycle hook.
     *
     * Important:
     * We do NOT delete the payment method to avoid breaking historical orders.
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        $this->getPaymentMethodInstaller()->deactivate($uninstallContext->getContext());

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->getCustomFieldsInstaller()->uninstall($uninstallContext->getContext());
    }

    /**
     * Returns configured payment method installer.
     */
    private function getPaymentMethodInstaller(): PaymentMethodInstaller
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container is not available.'); // @codeCoverageIgnore
        }

        /** @var EntityRepository<PaymentMethodCollection> $paymentMethodRepo */
        $paymentMethodRepo = $this->container->get('payment_method.repository');

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);

        /** @var EntityRepository<SalesChannelCollection> $salesChannelRepo */
        $salesChannelRepo = $this->container->get('sales_channel.repository');

        return new PaymentMethodInstaller(
            $paymentMethodRepo,
            $pluginIdProvider,
            $salesChannelRepo
        );
    }

    /**
     * Returns configured custom field installer.
     */
    private function getCustomFieldsInstaller(): CustomFieldsInstaller
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container is not available.'); // @codeCoverageIgnore
        }

        $setRepo = $this->container->get('custom_field_set.repository');
        $relationRepo = $this->container->get('custom_field_set_relation.repository');

        if (
            !$setRepo instanceof EntityRepository ||
            !$relationRepo instanceof EntityRepository
        ) {
            throw new \RuntimeException('Invalid repository services.'); // @codeCoverageIgnore
        }

        return new CustomFieldsInstaller(
            $setRepo,
            $relationRepo
        );
    }
}
