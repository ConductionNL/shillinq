<?php

/**
 * Unit tests for LifecycleRegistrar.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude Structural composition root, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Kernel\Contracts\PaymentBlockCheckerInterface;
use OCA\Shillinq\Lifecycle\AnnualBudgetDefaultGuard;
use OCA\Shillinq\Lifecycle\APGuard;
use OCA\Shillinq\Lifecycle\APTransactionSettlementGuard;
use OCA\Shillinq\Lifecycle\FiscalYearGuard;
use OCA\Shillinq\Lifecycle\FourEyesPaymentRunGuard;
use OCA\Shillinq\Lifecycle\GLReversalGuard;
use OCA\Shillinq\Lifecycle\LifecycleRegistrar;
use OCA\Shillinq\Lifecycle\PaymentRunDuplicateGuard;
use OCA\Shillinq\Lifecycle\PeriodCloseGuard;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCA\Shillinq\Lifecycle\WBSOExportValidationGuard;
use OCA\Shillinq\Lifecycle\WriteOffReasonGuard;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every lifecycle tag the registrar binds, and what its factory builds.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(LifecycleRegistrar::class)]
#[UsesClass(RegisterRequiresGuardAdapter::class)]
#[UsesClass(APTransactionSettlementGuard::class)]
#[UsesClass(FourEyesPaymentRunGuard::class)]
#[UsesClass(PaymentRunDuplicateGuard::class)]
final class LifecycleRegistrarTest extends TestCase {

	/**
	 * Run the registrar and collect each registered factory by its tag.
	 *
	 * @return array<string, callable> The factories, keyed by tag.
	 */
	private function factories(): array {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new LifecycleRegistrar())->register($context);

		return $factories;
	}//end factories()

	/**
	 * A container that answers every lookup with the given objects or a fresh mock.
	 *
	 * @param array<string, object> $services Objects to return for specific ids.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services = []): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services, $container): object {
				if (isset($services[$id]) === true) {
					return $services[$id];
				}

				if ($id === ContainerInterface::class) {
					return $container;
				}

				return $this->createMock($id);
			}
		);

		return $container;
	}//end container()

	/**
	 * The tags that map onto a guard's boolean method, with the deny message.
	 *
	 * @return array<string, array{0: string, 1: class-string, 2: string, 3: string}>
	 */
	public static function adapterTags(): array {
		return [
			'fiscal year' => [
				'OCA\Shillinq\Lifecycle\FiscalYearGuard::requireAllPeriodsClosedForYear',
				FiscalYearGuard::class,
				'requireAllPeriodsClosedForYear',
				'Every FiscalPeriod within this fiscal year must be closed before the year-end close can begin.',
			],
			'gl reversal' => [
				'OCA\Shillinq\Lifecycle\GLReversalGuard::isReversed',
				GLReversalGuard::class,
				'isReversed',
				'The linked GLTransaction must already be reversed before this record can be voided.',
			],
			'write-off reason' => [
				'OCA\Shillinq\Lifecycle\WriteOffReasonGuard::requireReason',
				WriteOffReasonGuard::class,
				'requireReason',
				'writeOffReason must be set before the invoice can be written off.',
			],
			'invoice number unique' => [
				'OCA\Shillinq\Lifecycle\APGuard::isInvoiceNumberUnique',
				APGuard::class,
				'isInvoiceNumberUnique',
				'A vendor invoice with this invoice number already exists for this administration.',
			],
			'ap write-off reason' => [
				'OCA\Shillinq\Lifecycle\APGuard::requireWriteOffReason',
				APGuard::class,
				'requireWriteOffReason',
				'writeOffReason must be set before the AP transaction can be written off.',
			],
			'wbso export' => [
				'OCA\Shillinq\Lifecycle\WBSOExportValidationGuard',
				WBSOExportValidationGuard::class,
				'requireEligibleEntries',
				'Every included Urenregistratie entry must carry wbsoTagId and activityCodeId before this export can be validated.',
			],
			'period close' => [
				'OCA\Shillinq\Lifecycle\PeriodCloseGuard::trialBalanceVerifies',
				PeriodCloseGuard::class,
				'trialBalanceVerifies',
				'Total posted debits must equal total posted credits for this period before the close can begin.',
			],
			'annual budget default' => [
				'OCA\Shillinq\Lifecycle\AnnualBudgetDefaultGuard::isUniqueDefault',
				AnnualBudgetDefaultGuard::class,
				'isUniqueDefault',
				'Another AnnualBudget already claims isDefault for this administration and fiscal year.',
			],
		];
	}//end adapterTags()

	/**
	 * Each tag's adapter calls its guard's method and denies with its own message.
	 *
	 * @param string       $tag     The registered tag.
	 * @param class-string $class   The guard class resolved from the container.
	 * @param string       $method  The guard method the adapter calls.
	 * @param string       $message The deny message.
	 *
	 * @return void
	 */
	#[DataProvider('adapterTags')]
	public function testEachAdapterWrapsItsGuardMethodAndMessage(string $tag, string $class, string $method, string $message): void {
		$guard = $this->createMock($class);
		$guard->expects($this->once())->method($method)->with(['id' => 'obj-1'])->willReturn(false);

		$factory = $this->factories()[$tag] ?? null;
		self::assertNotNull($factory, 'tag not registered: ' . $tag);

		$adapter = $factory($this->container([$class => $guard]));
		self::assertInstanceOf(RegisterRequiresGuardAdapter::class, $adapter);

		$result = $adapter->check(['id' => 'obj-1'], 'transition', 'user-1');
		self::assertFalse($result->isAllowed());
		self::assertSame($message, $result->getMessage());
	}//end testEachAdapterWrapsItsGuardMethodAndMessage()

	/**
	 * The guards that implement the lifecycle interface themselves are bound under their own class name.
	 *
	 * @return void
	 */
	public function testDirectGuardsAreBoundUnderTheirClassName(): void {
		$factories = $this->factories();
		$container = $this->container([
			IAppConfig::class => $this->createMock(IAppConfig::class),
			PaymentBlockCheckerInterface::class => $this->createMock(PaymentBlockCheckerInterface::class),
		]);

		foreach ([APTransactionSettlementGuard::class, FourEyesPaymentRunGuard::class, PaymentRunDuplicateGuard::class] as $class) {
			self::assertArrayHasKey($class, $factories);
			self::assertInstanceOf($class, $factories[$class]($container));
		}

		self::assertCount(11, $factories);
	}//end testDirectGuardsAreBoundUnderTheirClassName()
}//end class
