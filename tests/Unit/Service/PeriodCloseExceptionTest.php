<?php

/**
 * Unit tests for PeriodCloseException.
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
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PeriodCloseException;
use OCA\Shillinq\Service\PeriodCloseService;
use PHPUnit\Framework\TestCase;

/**
 * Pin the PeriodCloseException value-carrier contract: the controller maps
 * (message + status sentinel) to an HTTP response shape (ADR-005, REQ-PC-002 /
 * REQ-PC-006 / REQ-PC-008), so the sentinel MUST round-trip exactly and every
 * documented ERR_* constant MUST be a valid input.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PeriodCloseExceptionTest extends TestCase {

	/**
	 * Constructing with a message + sentinel persists both.
	 *
	 * @return void
	 */
	public function testConstructorPersistsMessageAndStatus(): void {
		$exception = new PeriodCloseException(
			message: 'Period is not open',
			status: PeriodCloseService::ERR_INVALID_STATE,
		);

		$this->assertSame('Period is not open', $exception->getMessage());
		$this->assertSame(PeriodCloseService::ERR_INVALID_STATE, $exception->getStatus());

	}//end testConstructorPersistsMessageAndStatus()

	/**
	 * Exception extends the framework RuntimeException so existing catch
	 * sites that bracket on RuntimeException keep behaving as expected.
	 *
	 * @return void
	 */
	public function testIsRuntimeException(): void {
		$exception = new PeriodCloseException(
			message: 'forbidden',
			status: PeriodCloseService::ERR_FORBIDDEN,
		);
		$this->assertInstanceOf(\RuntimeException::class, $exception);
		$this->assertInstanceOf(\Throwable::class, $exception);

	}//end testIsRuntimeException()

	/**
	 * The forbidden sentinel maps to the matching controller branch.
	 *
	 * @return void
	 */
	public function testForbiddenSentinelRoundTrips(): void {
		$exception = new PeriodCloseException(
			message: 'Role not allowed',
			status: PeriodCloseService::ERR_FORBIDDEN,
		);
		$this->assertSame('forbidden', $exception->getStatus());

	}//end testForbiddenSentinelRoundTrips()

	/**
	 * The not-found sentinel round-trips for the 404 controller branch.
	 *
	 * @return void
	 */
	public function testNotFoundSentinelRoundTrips(): void {
		$exception = new PeriodCloseException(
			message: 'Record missing',
			status: PeriodCloseService::ERR_NOT_FOUND,
		);
		$this->assertSame('not-found', $exception->getStatus());

	}//end testNotFoundSentinelRoundTrips()

	/**
	 * The invalid-state sentinel round-trips for the 409 controller branch.
	 *
	 * @return void
	 */
	public function testInvalidStateSentinelRoundTrips(): void {
		$exception = new PeriodCloseException(
			message: 'Wrong state',
			status: PeriodCloseService::ERR_INVALID_STATE,
		);
		$this->assertSame('invalid-state', $exception->getStatus());

	}//end testInvalidStateSentinelRoundTrips()

	/**
	 * The validation sentinel round-trips for the 422 controller branch.
	 *
	 * @return void
	 */
	public function testValidationSentinelRoundTrips(): void {
		$exception = new PeriodCloseException(
			message: 'Reason required',
			status: PeriodCloseService::ERR_VALIDATION,
		);
		$this->assertSame('validation', $exception->getStatus());

	}//end testValidationSentinelRoundTrips()

	/**
	 * Throwing the exception is intercept-able as the typed subclass — the
	 * controller relies on `catch (PeriodCloseException $e)` to project the
	 * sentinel onto an HTTP status.
	 *
	 * @return void
	 */
	public function testIsCatchableAsTypedSubclass(): void {
		try {
			throw new PeriodCloseException(
				message: 'Period closed',
				status: PeriodCloseService::ERR_INVALID_STATE,
			);
		} catch (PeriodCloseException $caught) {
			$this->assertSame('Period closed', $caught->getMessage());
			$this->assertSame('invalid-state', $caught->getStatus());

			return;
		}

		$this->fail('PeriodCloseException was not caught as the typed subclass');

	}//end testIsCatchableAsTypedSubclass()

	/**
	 * An empty message is allowed (the controller only needs the status
	 * sentinel; messages may be redacted for client-safety per ADR-005).
	 *
	 * @return void
	 */
	public function testEmptyMessageAccepted(): void {
		$exception = new PeriodCloseException(
			message: '',
			status: PeriodCloseService::ERR_FORBIDDEN,
		);
		$this->assertSame('', $exception->getMessage());
		$this->assertSame('forbidden', $exception->getStatus());

	}//end testEmptyMessageAccepted()
}//end class
