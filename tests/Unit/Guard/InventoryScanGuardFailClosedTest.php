<?php

/**
 * Unit tests for InventoryScanGuard's fail-closed handling of unexpected errors.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\InventoryScanGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @covers \OCA\Shillinq\Guard\InventoryScanGuard
 */
final class InventoryScanGuardFailClosedTest extends TestCase {

	public function testAnUnexpectedErrorDeniesTheSaveAndIsLoggedWithTheTransactionId(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			'InventoryScanGuard: validateOperation failed — denying save (fail-closed)',
			self::callback(fn (array $context): bool => $context['transactionId'] === 'tx-7' && $context['exception'] !== '')
		);

		$guard = new InventoryScanGuard(container: $container, appConfig: $this->createMock(IAppConfig::class), logger: $logger);

		self::assertFalse($guard->validateOperation(['transactionId' => 'tx-7', 'state' => new stdClass()]));
	}

	public function testTheLogFallsBackToUnknownWithoutATransactionId(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			self::anything(),
			self::callback(fn (array $context): bool => $context['transactionId'] === 'unknown')
		);

		$guard = new InventoryScanGuard(
			container: $this->createMock(ContainerInterface::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $logger
		);

		self::assertFalse($guard->validateOperation(['state' => new stdClass()]));
	}
}
