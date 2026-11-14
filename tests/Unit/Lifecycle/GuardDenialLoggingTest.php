<?php

/**
 * OrderFulfilmentGuard and ContractLifecycleGuard: what they log, and denial when logging fails.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\ContractLifecycleGuard;
use OCA\Shillinq\Lifecycle\OrderFulfilmentGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A denial is logged with its context; when the log itself fails the guard
 * still denies (fail-closed) and records the error.
 *
 * @covers \OCA\Shillinq\Lifecycle\OrderFulfilmentGuard
 * @covers \OCA\Shillinq\Lifecycle\ContractLifecycleGuard
 */
final class GuardDenialLoggingTest extends TestCase {

	/**
	 * A logger whose info() fails and whose error() expects $message with $context.
	 *
	 * @param string               $message The expected error message.
	 * @param array<string, mixed> $context The expected error context.
	 *
	 * @return LoggerInterface
	 */
	private function failingLogger(string $message, array $context): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willThrowException(new \RuntimeException('log disk full'));
		$logger->expects($this->once())->method('error')->with($message, $context);
		return $logger;

	}//end failingLogger()

	/**
	 * Completion without proof is logged with the commitment and milestone.
	 *
	 * @return void
	 */
	public function testOrderCompletionDenialIsLoggedWithItsContext(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$logger->expects($this->never())->method('error');
		$guard = new OrderFulfilmentGuard(logger: $logger);

		$this->assertFalse($guard->canVoltooien(['commitmentId' => 'VP-1', 'milestoneId' => 'M-2', 'supportingDocuments' => ['scan.pdf', ['documentId' => ' ']]]));
		$this->assertFalse($guard->canVoltooien([]));
		$this->assertTrue($guard->canVoltooien(['supportingDocuments' => ['scan.pdf', ['documentId' => 'doc-1']]]));

		$message = 'OrderFulfilmentGuard: no bewijsstuk attached — denying completion (REQ-004)';
		$this->assertSame(
			[
				[$message, ['commitmentId' => 'VP-1', 'milestoneId' => 'M-2']],
				[$message, ['commitmentId' => 'unknown', 'milestoneId' => 'unknown']],
			],
			$logged
		);

	}//end testOrderCompletionDenialIsLoggedWithItsContext()

	/**
	 * A failing log still denies completion.
	 *
	 * @return void
	 */
	public function testOrderCompletionStaysDeniedWhenLoggingFails(): void {
		$logger = $this->failingLogger(
			'OrderFulfilmentGuard: canVoltooien failed — denying completion (fail-closed)',
			['commitmentId' => 'VP-1', 'exception' => 'log disk full']
		);

		$this->assertFalse((new OrderFulfilmentGuard(logger: $logger))->canVoltooien(['commitmentId' => 'VP-1']));

	}//end testOrderCompletionStaysDeniedWhenLoggingFails()

	/**
	 * Activation denial names every missing field.
	 *
	 * @return void
	 */
	public function testContractActivationDenialNamesTheMissingFields(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'ContractLifecycleGuard: activation denied — mandatory fields missing (fail-closed)',
			['missing' => ['startDate', 'contractOwner']]
		);
		$guard = new ContractLifecycleGuard(logger: $logger);

		$this->assertFalse($guard->canActivate(['startDate' => ' ', 'counterpartyReference' => ['rel-1'], 'contractOwner' => []]));
		$this->assertTrue($guard->canActivate(['startDate' => '2026-01-01', 'counterpartyReference' => 42, 'contractOwner' => 'jan']));

	}//end testContractActivationDenialNamesTheMissingFields()

	/**
	 * A failing log still denies activation and termination.
	 *
	 * @return void
	 */
	public function testContractChecksStayDeniedWhenLoggingFails(): void {
		$activation = new ContractLifecycleGuard(
			logger: $this->failingLogger('ContractLifecycleGuard: activation check failed — denying (fail-closed)', ['exception' => 'log disk full'])
		);
		$this->assertFalse($activation->canActivate([]));

		$termination = new ContractLifecycleGuard(
			logger: $this->failingLogger('ContractLifecycleGuard: termination check failed — denying (fail-closed)', ['exception' => 'log disk full'])
		);
		$this->assertFalse($termination->requireTerminationReason(['terminationReason' => 0]));

	}//end testContractChecksStayDeniedWhenLoggingFails()
}//end class
