<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests\Integration\Plugin;

use Kommandhub\PaystackSW\Installer\CustomFieldsInstaller;
use Kommandhub\PaystackSW\Installer\PaymentMethodInstaller;
use Kommandhub\PaystackSW\Checkout\Payment\Handler\PaystackPaymentHandler;
use Kommandhub\PaystackSW\KmhPaystackSW;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

#[CoversClass(KmhPaystackSW::class)]
#[UsesClass(CustomFieldsInstaller::class)]
#[UsesClass(PaymentMethodInstaller::class)]
#[UsesClass(PaystackPaymentHandler::class)]
#[Group('kernel')]
class KmhPaystackSWTest extends TestCase
{
    use IntegrationTestBehaviour;

    private KmhPaystackSW $plugin;

    protected function setUp(): void
    {
        $this->plugin = new KmhPaystackSW(true, '');
        $this->plugin->setContainer(static::getContainer());
    }

    public function testInstallActivateAndDeactivateUpdateRepositoryState(): void
    {
        $this->plugin->install($this->createInstallContext());

        $paymentMethod = $this->getPaymentMethod();
        self::assertInstanceOf(PaymentMethodEntity::class, $paymentMethod);
        self::assertTrue($this->customFieldSetExists());

        $this->plugin->deactivate($this->createDeactivateContext());
        self::assertFalse($this->getPaymentMethod()?->getActive());

        $this->plugin->activate($this->createActivateContext());
        self::assertTrue($this->getPaymentMethod()?->getActive());
    }

    public function testUninstallDisablesPaymentMethodAndRemovesCustomFields(): void
    {
        $this->plugin->install($this->createInstallContext());
        self::assertTrue($this->customFieldSetExists());

        $this->plugin->uninstall($this->createUninstallContext(false));

        self::assertFalse($this->getPaymentMethod()?->getActive());
        self::assertFalse($this->customFieldSetExists());
    }

    public function testExecuteComposerCommands(): void
    {
        self::assertTrue($this->plugin->executeComposerCommands());
    }

    public function testUpdate(): void
    {
        $this->plugin->update($this->createUpdateContext());

        $paymentMethod = $this->getPaymentMethod();
        self::assertInstanceOf(PaymentMethodEntity::class, $paymentMethod);
        self::assertTrue($this->customFieldSetExists());
    }

    private function createUpdateContext(): \Shopware\Core\Framework\Plugin\Context\UpdateContext
    {
        return new \Shopware\Core\Framework\Plugin\Context\UpdateContext(
            $this->plugin,
            Context::createDefaultContext(),
            '6.7.0.0',
            '0.9.0-beta.1',
            $this->createMock(MigrationCollection::class),
            '0.9.0-beta.2'
        );
    }

    private function getPaymentMethod(): ?PaymentMethodEntity
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('technicalName', 'kmh_paystack_payment'));

        /** @var PaymentMethodEntity|null $paymentMethod */
        $paymentMethod = static::getContainer()
            ->get('payment_method.repository')
            ->search($criteria, Context::createDefaultContext())
            ->first();

        return $paymentMethod;
    }

    private function customFieldSetExists(): bool
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('name', 'kmh_paystack_fieldset'));

        return static::getContainer()
            ->get('custom_field_set.repository')
            ->searchIds($criteria, Context::createDefaultContext())
            ->getTotal() > 0;
    }

    private function createInstallContext(): InstallContext
    {
        return new InstallContext(
            $this->plugin,
            Context::createDefaultContext(),
            '6.7.0.0',
            '0.9.0-beta.1',
            $this->createMock(MigrationCollection::class)
        );
    }

    private function createActivateContext(): ActivateContext
    {
        return new ActivateContext(
            $this->plugin,
            Context::createDefaultContext(),
            '6.7.0.0',
            '0.9.0-beta.1',
            $this->createMock(MigrationCollection::class)
        );
    }

    private function createDeactivateContext(): DeactivateContext
    {
        return new DeactivateContext(
            $this->plugin,
            Context::createDefaultContext(),
            '6.7.0.0',
            '0.9.0-beta.1',
            $this->createMock(MigrationCollection::class)
        );
    }

    private function createUninstallContext(bool $keepUserData): UninstallContext
    {
        return new UninstallContext(
            $this->plugin,
            Context::createDefaultContext(),
            '6.7.0.0',
            '0.9.0-beta.1',
            $this->createMock(MigrationCollection::class),
            $keepUserData
        );
    }
}
