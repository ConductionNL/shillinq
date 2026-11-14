<?php

/**
 * The lifecycle actions that hand an asset, a reading or a reserve mutation to one service method.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\ApplyReinvestmentReserveAction;
use OCA\Shillinq\Lifecycle\Action\ExtraDepreciationAction;
use OCA\Shillinq\Lifecycle\Action\RateMeterReadingAction;
use OCA\Shillinq\Lifecycle\Action\RealiseReserveMutationAction;
use OCA\Shillinq\Lifecycle\Action\ReviseDepreciationAction;
use OCA\Shillinq\Service\Asset\FixedAssetDepreciation;
use OCA\Shillinq\Service\Asset\ReinvestmentReserves;
use OCA\Shillinq\Service\PublicSector\ReserveBalances;
use OCA\Shillinq\Service\Usage\MeterReadingRating;
use PHPUnit\Framework\TestCase;

/**
 * Each action passes the new object data, and only that, to its service
 * method and returns what the service returns; the previous data, the
 * parameters and the action name play no part.
 *
 * @covers \OCA\Shillinq\Lifecycle\Action\ApplyReinvestmentReserveAction
 * @covers \OCA\Shillinq\Lifecycle\Action\ExtraDepreciationAction
 * @covers \OCA\Shillinq\Lifecycle\Action\RateMeterReadingAction
 * @covers \OCA\Shillinq\Lifecycle\Action\RealiseReserveMutationAction
 * @covers \OCA\Shillinq\Lifecycle\Action\ReviseDepreciationAction
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SingleServiceActionsTest extends TestCase {

	private const OBJECT = ['id' => 'obj-1', 'amount' => 1200.0];

	private const PREVIOUS = ['id' => 'obj-1', 'amount' => 1000.0];

	private const RESULT = ['id' => 'obj-1', 'amount' => 1200.0, 'stamped' => true];

	/**
	 * Action class, service class, service method and the named argument it receives.
	 *
	 * @return array<string, array{class-string, class-string, string, string}>
	 */
	public static function actions(): array {
		return [
			'reinvestment reserve' => [ApplyReinvestmentReserveAction::class, ReinvestmentReserves::class, 'apply', 'asset'],
			'extra depreciation'   => [ExtraDepreciationAction::class, FixedAssetDepreciation::class, 'extra', 'asset'],
			'revise depreciation'  => [ReviseDepreciationAction::class, FixedAssetDepreciation::class, 'revise', 'asset'],
			'meter reading'        => [RateMeterReadingAction::class, MeterReadingRating::class, 'rate', 'reading'],
			'reserve mutation'     => [RealiseReserveMutationAction::class, ReserveBalances::class, 'realise', 'mutation'],
		];

	}//end actions()

	/**
	 * The service gets the object data once and its answer is the action's result.
	 *
	 * @param class-string $actionClass  The action.
	 * @param class-string $serviceClass The service it delegates to.
	 * @param string       $method       The service method.
	 * @param string       $argument     The parameter name of that method.
	 *
	 * @dataProvider actions
	 *
	 * @return void
	 */
	public function testTheObjectGoesToTheServiceAndItsAnswerComesBack(string $actionClass, string $serviceClass, string $method, string $argument): void {
		$service = $this->createMock($serviceClass);
		$service->expects($this->once())->method($method)->with(self::OBJECT)->willReturn(self::RESULT);

		$action = new $actionClass($service);

		$this->assertSame(self::RESULT, $action->execute(self::OBJECT, self::PREVIOUS, ['step' => 'post', 'kind' => 'closed'], 'anything'));
		$this->assertSame($argument, (new \ReflectionMethod($serviceClass, $method))->getParameters()[0]->getName());

	}//end testTheObjectGoesToTheServiceAndItsAnswerComesBack()

	/**
	 * The depreciation actions call only their own method on the shared service.
	 *
	 * @return void
	 */
	public function testTheTwoDepreciationActionsDoNotCrossOver(): void {
		$extra = $this->createMock(FixedAssetDepreciation::class);
		$extra->expects($this->never())->method('revise');
		$extra->expects($this->once())->method('extra')->willReturn(['which' => 'extra']);
		$this->assertSame(['which' => 'extra'], (new ExtraDepreciationAction($extra))->execute(self::OBJECT, [], [], 'extra'));

		$revise = $this->createMock(FixedAssetDepreciation::class);
		$revise->expects($this->never())->method('extra');
		$revise->expects($this->once())->method('revise')->willReturn(['which' => 'revise']);
		$this->assertSame(['which' => 'revise'], (new ReviseDepreciationAction($revise))->execute(self::OBJECT, [], [], 'revise'));

	}//end testTheTwoDepreciationActionsDoNotCrossOver()
}//end class
