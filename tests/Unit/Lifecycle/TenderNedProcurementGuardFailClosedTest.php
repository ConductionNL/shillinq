<?php

/**
 * Unit tests for TenderNedProcurementGuard fail-closed paths.
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
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\TenderNedProcurementGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Award and completion are denied when the check itself breaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(TenderNedProcurementGuard::class)]
final class TenderNedProcurementGuardFailClosedTest extends TestCase {

	/**
	 * A guard whose logger fails on the given level and records the error it is handed.
	 *
	 * @param string                    $failingLevel The log level that throws.
	 * @param array<int,array<mixed>>   $errors       Collects the error calls.
	 *
	 * @return TenderNedProcurementGuard
	 */
	private function guard(string $failingLevel, array &$errors): TenderNedProcurementGuard {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method($failingLevel)->willThrowException(new \RuntimeException('log sink down'));
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$errors): void {
				$errors[] = [$message, $context];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		return new TenderNedProcurementGuard(
			container: $container,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $logger,
		);
	}//end guard()

	/**
	 * A refusal that cannot be logged still refuses the award, and says so at error level.
	 *
	 * @return void
	 */
	public function testAwardIsDeniedWhenTheCheckThrows(): void {
		$errors = [];
		$guard = $this->guard('info', $errors);

		self::assertFalse($guard->canGunnen(['tenderId' => 'T-9', 'awardedSupplier' => '']));
		self::assertSame(
			[['TenderNedProcurementGuard: canGunnen failed — denying award (fail-closed)', ['tenderId' => 'T-9', 'exception' => 'log sink down']]],
			$errors
		);
	}//end testAwardIsDeniedWhenTheCheckThrows()

	/**
	 * Completion without a commitment is denied when the check throws, not permitted.
	 *
	 * @return void
	 */
	public function testCompletionIsDeniedWhenTheCheckThrows(): void {
		$errors = [];
		$guard = $this->guard('warning', $errors);

		self::assertFalse($guard->canAfronden([]));
		self::assertSame(
			[['TenderNedProcurementGuard: canAfronden failed — denying completion (fail-closed)', ['tenderId' => 'unknown', 'exception' => 'log sink down']]],
			$errors
		);
	}//end testCompletionIsDeniedWhenTheCheckThrows()
}//end class
