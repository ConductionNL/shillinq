<?php

/**
 * Unit tests for CommitStockReservationAction and ReleaseStockReservationAction.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Lifecycle\Action\CommitStockReservationAction;
use OCA\Shillinq\Lifecycle\Action\ReleaseStockReservationAction;
use OCA\Shillinq\Lifecycle\StockReservationGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The StockMove post and cancel actions run the reservation step and refuse
 * the transition when it does not succeed.
 */
final class StockReservationActionsTest extends TestCase {

	private const MOVE = ['movementNumber' => 'SM-DLV-ABC-000', 'itemId' => 'SKU-1', 'quantity' => 3, 'sourceLocationId' => 'MAIN'];

	/**
	 * Posting commits the move's stock and returns the move unchanged.
	 *
	 * @return void
	 */
	public function testPostingCommitsTheStock(): void {
		$guard = $this->createMock(originalClassName: StockReservationGuard::class);
		$guard->expects(self::once())->method('commitReservation')->with(self::MOVE)->willReturn(true);
		$action = new CommitStockReservationAction(reservations: $guard);

		self::assertInstanceOf(expected: LifecycleActionInterface::class, actual: $action);
		self::assertSame(expected: self::MOVE, actual: $action->execute(context: $this->context(action: 'post')));

	}//end testPostingCommitsTheStock()

	/**
	 * A commit that fails refuses the transition, naming the move.
	 *
	 * @return void
	 */
	public function testAFailedCommitRefusesThePost(): void {
		$guard = $this->createMock(originalClassName: StockReservationGuard::class);
		$guard->method('commitReservation')->willReturn(false);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'The stock for move SM-DLV-ABC-000 could not be updated');
		(new CommitStockReservationAction(reservations: $guard))->execute(context: $this->context(action: 'post'));

	}//end testAFailedCommitRefusesThePost()

	/**
	 * Cancelling a draft releases its reservation; a failed release refuses.
	 *
	 * @return void
	 */
	public function testCancellingReleasesTheReservation(): void {
		$guard = $this->createMock(originalClassName: StockReservationGuard::class);
		$guard->expects(self::exactly(count: 2))->method('releaseReservation')->with(self::MOVE)->willReturnOnConsecutiveCalls(true, false);
		$action = new ReleaseStockReservationAction(reservations: $guard);

		self::assertSame(expected: self::MOVE, actual: $action->execute(context: $this->context(action: 'cancel')));

		$this->expectException(exception: RuntimeException::class);
		$action->execute(context: $this->context(action: 'cancel'));

	}//end testCancellingReleasesTheReservation()

	/**
	 * The transition context for the move.
	 *
	 * @param string $action The transition.
	 *
	 * @return LifecycleActionContext
	 */
	private function context(string $action): LifecycleActionContext {
		return new LifecycleActionContext(objectData: self::MOVE, previousData: [], parameters: [], actionName: $action);

	}//end context()
}//end class
