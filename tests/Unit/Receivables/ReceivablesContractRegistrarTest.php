<?php

/**
 * Unit tests for ReceivablesContractRegistrar.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Receivables
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

namespace OCA\Shillinq\Tests\Unit\Receivables;

use OCA\Shillinq\Kernel\Contracts\SepaPain008GeneratorInterface;
use OCA\Shillinq\Kernel\Contracts\SepaPain008ValidatorInterface;
use OCA\Shillinq\Receivables\ReceivablesContractRegistrar;
use OCA\Shillinq\Receivables\SepaPain008Generator;
use OCA\Shillinq\Receivables\SepaPain008Validator;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Receivables\ReceivablesContractRegistrar
 */
final class ReceivablesContractRegistrarTest extends TestCase {
	public function testEachReceivablesContractIsBoundToItsImplementation(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->exactly(2))->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new ReceivablesContractRegistrar())->register($context);

		$this->assertSame(
			[
				SepaPain008GeneratorInterface::class => SepaPain008Generator::class,
				SepaPain008ValidatorInterface::class => SepaPain008Validator::class,
			],
			$aliases
		);
	}//end testEachReceivablesContractIsBoundToItsImplementation()
}//end class
