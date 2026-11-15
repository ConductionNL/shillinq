<?php

/**
 * Unit tests for LifecycleContractRegistrar.
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
 * @spec exclude Structural contract wiring, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Kernel\Contracts\AansluitingResolutionGuardInterface;
use OCA\Shillinq\Kernel\Contracts\BudgetBlockerInterface;
use OCA\Shillinq\Kernel\Contracts\ImportBatchGuardInterface;
use OCA\Shillinq\Kernel\Contracts\LotSellabilityGuardInterface;
use OCA\Shillinq\Kernel\Contracts\MandateEnforcerInterface;
use OCA\Shillinq\Kernel\Contracts\MandateGuardInterface;
use OCA\Shillinq\Kernel\Contracts\MaterialiseGlTransactionActionInterface;
use OCA\Shillinq\Kernel\Contracts\OrderFulfilmentGuardInterface;
use OCA\Shillinq\Kernel\Contracts\RequisitionConversionGuardInterface;
use OCA\Shillinq\Kernel\Contracts\StatementParserInterface;
use OCA\Shillinq\Kernel\Contracts\VarianceGateInterface;
use OCA\Shillinq\Lifecycle\AansluitingResolutionGuard;
use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Lifecycle\BudgetBlocker;
use OCA\Shillinq\Lifecycle\ImportBatchGuard;
use OCA\Shillinq\Lifecycle\LifecycleContractRegistrar;
use OCA\Shillinq\Lifecycle\LotSellabilityGuard;
use OCA\Shillinq\Lifecycle\MandateEnforcer;
use OCA\Shillinq\Lifecycle\MandateGuard;
use OCA\Shillinq\Lifecycle\OrderFulfilmentGuard;
use OCA\Shillinq\Lifecycle\RequisitionConversionGuard;
use OCA\Shillinq\Lifecycle\StatementParser;
use OCA\Shillinq\Lifecycle\VarianceGate;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every Lifecycle contract is aliased to its implementation, and to nothing else.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(LifecycleContractRegistrar::class)]
final class LifecycleContractRegistrarTest extends TestCase {

	/**
	 * The registrar aliases each contract to exactly its implementation and registers no services.
	 *
	 * @return void
	 */
	public function testEveryContractIsAliasedToItsImplementation(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->never())->method('registerService');
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new LifecycleContractRegistrar())->register($context);

		self::assertSame(
			[
				AansluitingResolutionGuardInterface::class => AansluitingResolutionGuard::class,
				BudgetBlockerInterface::class => BudgetBlocker::class,
				ImportBatchGuardInterface::class => ImportBatchGuard::class,
				LotSellabilityGuardInterface::class => LotSellabilityGuard::class,
				MandateEnforcerInterface::class => MandateEnforcer::class,
				MandateGuardInterface::class => MandateGuard::class,
				MaterialiseGlTransactionActionInterface::class => MaterialiseGlTransactionAction::class,
				OrderFulfilmentGuardInterface::class => OrderFulfilmentGuard::class,
				RequisitionConversionGuardInterface::class => RequisitionConversionGuard::class,
				StatementParserInterface::class => StatementParser::class,
				VarianceGateInterface::class => VarianceGate::class,
			],
			$aliases
		);

		foreach ($aliases as $contract => $implementation) {
			self::assertTrue(is_subclass_of($implementation, $contract), $implementation . ' implements ' . $contract);
		}
	}//end testEveryContractIsAliasedToItsImplementation()
}//end class
