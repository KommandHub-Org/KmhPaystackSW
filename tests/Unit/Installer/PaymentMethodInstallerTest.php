<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests\Unit\Installer;

use Kommandhub\PaystackSW\Installer\PaymentMethodInstaller;
use Kommandhub\PaystackSW\Checkout\Payment\Handler\PaystackPaymentHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

#[CoversClass(PaymentMethodInstaller::class)]
class PaymentMethodInstallerTest extends TestCase
{
    private EntityRepository $paymentMethodRepository;
    private PluginIdProvider $pluginIdProvider;
    private PaymentMethodInstaller $installer;
    private Context $context;

    protected function setUp(): void
    {
        $this->paymentMethodRepository = $this->createMock(EntityRepository::class);
        $this->pluginIdProvider = $this->createMock(PluginIdProvider::class);
        $this->installer = new PaymentMethodInstaller(
            $this->paymentMethodRepository,
            $this->pluginIdProvider
        );
        $this->context = Context::createDefaultContext();
    }

    public function testInstallWhenAlreadyExists(): void
    {
        $paymentId = 'existing-payment-id';
        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn($paymentId);
        $this->paymentMethodRepository->method('searchIds')->willReturn($idSearchResult);

        $this->paymentMethodRepository->expects($this->once())->method('update')->with([
            [
                'id' => $paymentId,
                'handlerIdentifier' => PaystackPaymentHandler::class,
                'active' => true,
            ],
        ], $this->context);

        $this->paymentMethodRepository->expects($this->never())->method('create');

        $this->installer->install('PluginClass', $this->context);
    }

    public function testInstallWhenNotExists(): void
    {
        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn(null);
        $this->paymentMethodRepository->method('searchIds')->willReturn($idSearchResult);

        $this->pluginIdProvider->method('getPluginIdByBaseClass')->willReturn('plugin-id');

        $this->paymentMethodRepository->expects($this->once())->method('create')->with([
            [
                'handlerIdentifier' => PaystackPaymentHandler::class,
                'name' => 'Pay with Paystack',
                'description' => 'Securely pay with card, bank transfer or mobile money via Paystack.',
                'pluginId' => 'plugin-id',
                'technicalName' => 'kmh_paystack_payment',
                'afterOrderEnabled' => true,
                'active' => true,
            ],
        ], $this->context);

        $this->installer->install('PluginClass', $this->context);
    }

    public function testActivate(): void
    {
        $paymentId = 'payment-id';
        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn($paymentId);
        $this->paymentMethodRepository->method('searchIds')->willReturn($idSearchResult);

        $this->paymentMethodRepository->expects($this->once())->method('update')->with([
            [
                'id' => $paymentId,
                'active' => true,
            ],
        ], $this->context);

        $this->installer->activate($this->context);
    }

    public function testDeactivate(): void
    {
        $paymentId = 'payment-id';
        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn($paymentId);
        $this->paymentMethodRepository->method('searchIds')->willReturn($idSearchResult);

        $this->paymentMethodRepository->expects($this->once())->method('update')->with([
            [
                'id' => $paymentId,
                'active' => false,
            ],
        ], $this->context);

        $this->installer->deactivate($this->context);
    }

    public function testSetPaymentMethodActiveWhenNotFound(): void
    {
        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn(null);
        $this->paymentMethodRepository->method('searchIds')->willReturn($idSearchResult);

        $this->paymentMethodRepository->expects($this->never())->method('update');

        $this->installer->activate($this->context);
    }
}
