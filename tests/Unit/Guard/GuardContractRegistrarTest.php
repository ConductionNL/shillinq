<?php

/**
 * Unit tests for GuardContractRegistrar.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
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

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\DBAOpdrachtGuard;
use OCA\Shillinq\Guard\DBAScoreCalculator;
use OCA\Shillinq\Guard\GuardContractRegistrar;
use OCA\Shillinq\Guard\UrencriteriumYearGuard;
use OCA\Shillinq\Guard\UrenDagregistratieGuard;
use OCA\Shillinq\Kernel\Contracts\DBAOpdrachtGuardInterface;
use OCA\Shillinq\Kernel\Contracts\DBAScoreCalculatorInterface;
use OCA\Shillinq\Kernel\Contracts\UrencriteriumYearGuardInterface;
use OCA\Shillinq\Kernel\Contracts\UrenDagregistratieGuardInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Guard\GuardContractRegistrar
 */
final class GuardContractRegistrarTest extends TestCase {
	public function testEachGuardContractIsBoundToItsGuard(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->exactly(4))->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new GuardContractRegistrar())->register($context);

		$this->assertSame(
			[
				DBAOpdrachtGuardInterface::class => DBAOpdrachtGuard::class,
				DBAScoreCalculatorInterface::class => DBAScoreCalculator::class,
				UrenDagregistratieGuardInterface::class => UrenDagregistratieGuard::class,
				UrencriteriumYearGuardInterface::class => UrencriteriumYearGuard::class,
			],
			$aliases
		);
	}//end testEachGuardContractIsBoundToItsGuard()
}//end class
