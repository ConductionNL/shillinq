<?php

/**
 * Unit tests for ContractRegistrar — the Kernel contract to Service bindings.
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

use OCA\Shillinq\Kernel\Contracts\AansluitingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\VendorPerformanceAggregationInterface;
use OCA\Shillinq\Kernel\Contracts\WidgetAuthServiceInterface;
use OCA\Shillinq\Service\AansluitingService;
use OCA\Shillinq\Service\ContractRegistrar;
use OCA\Shillinq\Service\VendorPerformanceAggregation;
use OCA\Shillinq\Service\WidgetAuthService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every Kernel contract must resolve to a class that actually implements it,
 * exactly once, and nothing but aliases may be registered here.
 */
#[CoversClass(ContractRegistrar::class)]
final class ContractRegistrarTest extends TestCase {

	/**
	 * Run the registrar against a recording context.
	 *
	 * @return array<int, array{0: string, 1: string}> The alias/target pairs in call order
	 */
	private function recordAliases(): array {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::never())->method('registerService');
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[] = [$alias, $target];
			}
		);

		(new ContractRegistrar())->register(context: $context);

		return $aliases;

	}//end recordAliases()

	/**
	 * The registrar binds the full contract set, each contract once.
	 *
	 * @return void
	 */
	public function testEveryContractIsBoundExactlyOnce(): void {
		$aliases = $this->recordAliases();
		$names = array_column($aliases, 0);

		self::assertCount(207, $aliases);
		self::assertSame($names, array_values(array_unique($names)));

	}//end testEveryContractIsBoundExactlyOnce()

	/**
	 * Each alias is a Kernel contract interface and each target implements it.
	 *
	 * @return void
	 */
	public function testEveryTargetImplementsItsContract(): void {
		foreach ($this->recordAliases() as [$alias, $target]) {
			self::assertStringStartsWith('OCA\\Shillinq\\Kernel\\Contracts\\', $alias);
			self::assertTrue(interface_exists($alias), $alias.' is not an interface');
			self::assertTrue(class_exists($target), $target.' is not a class');
			self::assertTrue(is_subclass_of($target, $alias), $target.' does not implement '.$alias);
		}

	}//end testEveryTargetImplementsItsContract()

	/**
	 * Known bindings resolve to the expected concrete service.
	 *
	 * @return void
	 */
	public function testKnownBindingsPointAtTheirService(): void {
		$map = [];
		foreach ($this->recordAliases() as [$alias, $target]) {
			$map[$alias] = $target;
		}

		self::assertSame(AansluitingService::class, $map[AansluitingServiceInterface::class]);
		self::assertSame(VendorPerformanceAggregation::class, $map[VendorPerformanceAggregationInterface::class]);
		self::assertSame(WidgetAuthService::class, $map[WidgetAuthServiceInterface::class]);

	}//end testKnownBindingsPointAtTheirService()
}//end class
