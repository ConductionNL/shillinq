<?php

/**
 * Unit tests for ServiceRegistrar — the Service component's container bindings.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AansluitingServiceInterface;
use OCA\Shillinq\Service\AansluitingService;
use OCA\Shillinq\Service\ContractRegistrar;
use OCA\Shillinq\Service\DoorsnijdingsVerbodValidator;
use OCA\Shillinq\Service\Dunning\DunningChannelAdapterInterface;
use OCA\Shillinq\Service\Dunning\MailDunningChannelAdapter;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\InnovatieboxAuditEventLogger;
use OCA\Shillinq\Service\KnownCostScheduleExpander;
use OCA\Shillinq\Service\KnownCostScheduleExpanderInterface;
use OCA\Shillinq\Service\Payment\MolliePaymentProvider;
use OCA\Shillinq\Service\Payment\PaymentProviderInterface;
use OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter;
use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\Pipelinq\PersistentTimelineRetryQueue;
use OCA\Shillinq\Service\Pipelinq\TimelineRetryQueue;
use OCA\Shillinq\Service\ServiceRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

/**
 * The registrar binds the contract set, the adapter ports and two factories.
 */
#[CoversClass(ServiceRegistrar::class)]
#[UsesClass(ContractRegistrar::class)]
#[UsesClass(DoorsnijdingsVerbodValidator::class)]
#[UsesClass(MolliePaymentProvider::class)]
final class ServiceRegistrarTest extends TestCase {

	/**
	 * Recorded aliases, alias => target.
	 *
	 * @var array<string, string>
	 */
	private array $aliases = [];

	/**
	 * Recorded factories, name => callable.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = [];

	/**
	 * Run the registrar against a recording context.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target): void {
				self::assertArrayNotHasKey($alias, $this->aliases, $alias.' is bound twice');
				$this->aliases[$alias] = $target;
			}
		);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory): void {
				$this->factories[$name] = $factory;
			}
		);

		(new ServiceRegistrar())->register(context: $context);

	}//end setUp()

	/**
	 * The Kernel contract bindings are delegated to ContractRegistrar.
	 *
	 * @return void
	 */
	public function testContractBindingsAreIncluded(): void {
		self::assertSame(AansluitingService::class, $this->aliases[AansluitingServiceInterface::class]);
		self::assertCount(207 + 25, $this->aliases);

	}//end testContractBindingsAreIncluded()

	/**
	 * The adapter ports resolve to their default implementations.
	 *
	 * @return void
	 */
	public function testPortsResolveToDefaultAdapters(): void {
		self::assertSame(PersistentTimelineRetryQueue::class, $this->aliases[TimelineRetryQueue::class]);
		self::assertSame(MailDunningChannelAdapter::class, $this->aliases[DunningChannelAdapterInterface::class]);
		self::assertSame(LogPeppolTransmissionAdapter::class, $this->aliases[PeppolTransmissionPortInterface::class]);
		self::assertSame(KnownCostScheduleExpander::class, $this->aliases[KnownCostScheduleExpanderInterface::class]);

		foreach ($this->aliases as $alias => $target) {
			self::assertTrue(is_a($target, $alias, true), $target.' does not implement '.$alias);
		}

	}//end testPortsResolveToDefaultAdapters()

	/**
	 * Exactly two services are built by a factory.
	 *
	 * @return void
	 */
	public function testOnlyTheTwoFactoriesAreRegistered(): void {
		self::assertSame(
			[DoorsnijdingsVerbodValidator::class, PaymentProviderInterface::class],
			array_keys($this->factories)
		);

	}//end testOnlyTheTwoFactoriesAreRegistered()

	/**
	 * The validator factory wires the config, object service and audit logger.
	 *
	 * @return void
	 */
	public function testValidatorFactoryWiresTheAuditLogger(): void {
		$config = $this->createStub(IAppConfig::class);
		$objects = $this->createStub(ObjectServiceInterface::class);
		$logger = $this->createStub(InnovatieboxAuditEventLogger::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::exactly(3))->method('get')->willReturnMap(
			[
				[IAppConfig::class, $config],
				[ObjectServiceInterface::class, $objects],
				[InnovatieboxAuditEventLogger::class, $logger],
			]
		);

		$validator = ($this->factories[DoorsnijdingsVerbodValidator::class])($container);

		self::assertInstanceOf(DoorsnijdingsVerbodValidator::class, $validator);
		self::assertSame($config, (new ReflectionProperty($validator, 'appConfig'))->getValue($validator));
		self::assertSame($objects, (new ReflectionProperty($validator, 'objectService'))->getValue($validator));
		self::assertSame($logger, (new ReflectionProperty($validator, 'auditLogger'))->getValue($validator));

	}//end testValidatorFactoryWiresTheAuditLogger()

	/**
	 * The payment provider factory wraps the bound Mollie adapter.
	 *
	 * @return void
	 */
	public function testPaymentProviderFactoryWrapsTheMollieAdapter(): void {
		$adapter = $this->createStub(MolliePaymentAdapterInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::once())->method('get')
			->with(MolliePaymentAdapterInterface::class)
			->willReturn($adapter);

		$provider = ($this->factories[PaymentProviderInterface::class])($container);

		self::assertInstanceOf(MolliePaymentProvider::class, $provider);
		self::assertSame($adapter, (new ReflectionProperty($provider, 'mollie'))->getValue($provider));

	}//end testPaymentProviderFactoryWrapsTheMollieAdapter()
}//end class
