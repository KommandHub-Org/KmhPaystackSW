<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests\Unit;

use Kommandhub\PaystackSW\KmhPaystackSW;
use Kommandhub\PaystackSW\Installer\CustomFieldsInstaller;
use Kommandhub\PaystackSW\Installer\PaymentMethodInstaller;
use Kommandhub\PaystackSW\Checkout\Payment\Handler\PaystackPaymentHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Context;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

#[CoversClass(KmhPaystackSW::class)]
#[UsesClass(CustomFieldsInstaller::class)]
#[UsesClass(PaymentMethodInstaller::class)]
#[UsesClass(PaystackPaymentHandler::class)]
class KmhPaystackSWTest extends TestCase
{
    private KmhPaystackSW $plugin;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->plugin = new KmhPaystackSW(true, '');
        $this->container = $this->createMock(ContainerInterface::class);
        $this->plugin->setContainer($this->container);
    }

    public function testExecuteComposerCommands(): void
    {
        $this->assertTrue($this->plugin->executeComposerCommands());
    }

    public function testInstall(): void
    {
        $context = $this->createMock(InstallContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $repository = $this->createMock(EntityRepository::class);
        $customFieldSetRepository = $this->createMock(EntityRepository::class);
        $customFieldSetRelationRepository = $this->createMock(EntityRepository::class);

        $this->container->method('get')->willReturnMap([
            ['payment_method.repository', $repository],
            ['sales_channel.repository', $this->createMock(EntityRepository::class)],
            [PluginIdProvider::class, $this->createMock(PluginIdProvider::class)],
            ['custom_field_set.repository', $customFieldSetRepository],
            ['custom_field_set_relation.repository', $customFieldSetRelationRepository],
        ]);

        // Mock search to return no existing payment method
        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn(null);
        $repository->method('searchIds')->willReturn($idSearchResult);

        $repository->expects($this->once())->method('create');

        $this->plugin->install($context);
    }

    public function testActivate(): void
    {
        $context = $this->createMock(ActivateContext::class);
        $context->method('getContext')->willReturn($shopwareContext = Context::createDefaultContext());

        $repository = $this->createMock(EntityRepository::class);
        $this->container->method('get')->willReturnMap([
            ['payment_method.repository', $repository],
            ['sales_channel.repository', $this->createMock(EntityRepository::class)],
            [PluginIdProvider::class, $this->createMock(PluginIdProvider::class)],
        ]);

        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('payment-method-id');
        $repository->method('searchIds')->willReturn($idSearchResult);

        $repository->expects($this->once())->method('update')->with([
            ['id' => 'payment-method-id', 'active' => true],
        ], $shopwareContext);

        $this->plugin->activate($context);
    }

    public function testDeactivate(): void
    {
        $context = $this->createMock(DeactivateContext::class);
        $context->method('getContext')->willReturn($shopwareContext = Context::createDefaultContext());

        $repository = $this->createMock(EntityRepository::class);
        $this->container->method('get')->willReturnMap([
            ['payment_method.repository', $repository],
            ['sales_channel.repository', $this->createMock(EntityRepository::class)],
            [PluginIdProvider::class, $this->createMock(PluginIdProvider::class)],
        ]);

        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('payment-method-id');
        $repository->method('searchIds')->willReturn($idSearchResult);

        $repository->expects($this->once())->method('update')->with([
            ['id' => 'payment-method-id', 'active' => false],
        ], $shopwareContext);

        $this->plugin->deactivate($context);
    }

    public function testUninstall(): void
    {
        $context = $this->createMock(UninstallContext::class);
        $context->method('getContext')->willReturn($shopwareContext = Context::createDefaultContext());
        $context->method('keepUserData')->willReturn(true);

        $repository = $this->createMock(EntityRepository::class);
        $customFieldSetRepository = $this->createMock(EntityRepository::class);
        $customFieldSetRelationRepository = $this->createMock(EntityRepository::class);

        $this->container->method('get')->willReturnMap([
            ['payment_method.repository', $repository],
            ['sales_channel.repository', $this->createMock(EntityRepository::class)],
            [PluginIdProvider::class, $this->createMock(PluginIdProvider::class)],
            ['custom_field_set.repository', $customFieldSetRepository],
            ['custom_field_set_relation.repository', $customFieldSetRelationRepository],
        ]);

        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('payment-method-id');
        $repository->method('searchIds')->willReturn($idSearchResult);

        $repository->expects($this->once())->method('update')->with([
            ['id' => 'payment-method-id', 'active' => false],
        ], $shopwareContext);

        $this->plugin->uninstall($context);
    }

    public function testUninstallWithoutKeepingUserData(): void
    {
        $context = $this->createMock(UninstallContext::class);
        $context->method('getContext')->willReturn($shopwareContext = Context::createDefaultContext());
        $context->method('keepUserData')->willReturn(false);

        $repository = $this->createMock(EntityRepository::class);
        $customFieldSetRepository = $this->createMock(EntityRepository::class);
        $customFieldSetRelationRepository = $this->createMock(EntityRepository::class);

        $this->container->method('get')->willReturnMap([
            ['payment_method.repository', $repository],
            ['sales_channel.repository', $this->createMock(EntityRepository::class)],
            [PluginIdProvider::class, $this->createMock(PluginIdProvider::class)],
            ['custom_field_set.repository', $customFieldSetRepository],
            ['custom_field_set_relation.repository', $customFieldSetRelationRepository],
        ]);

        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('payment-method-id');
        $repository->method('searchIds')->willReturn($idSearchResult);

        $repository->expects($this->once())->method('update');

        $customFieldSetIdResult = $this->createMock(IdSearchResult::class);
        $customFieldSetIdResult->method('getIds')->willReturn(['fieldset-id']);
        $customFieldSetRepository->method('searchIds')->willReturn($customFieldSetIdResult);

        $customFieldSetRepository->expects($this->once())->method('delete');

        $this->plugin->uninstall($context);
    }
}
