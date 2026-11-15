<?php

/**
 * The lifecycle action that runs one step of an import batch.
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

use OCA\Shillinq\Lifecycle\Action\ImportBatchAction;
use OCA\Shillinq\Service\Import\ImportBatchSteps;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The `step` parameter names the ImportBatchSteps method to run; an unknown
 * step is refused before any step runs.
 *
 * @covers \OCA\Shillinq\Lifecycle\Action\ImportBatchAction
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ImportBatchActionTest extends TestCase {

	private const STEPS = ['parse', 'startMapping', 'validate', 'dryRun', 'post', 'reverse'];

	private const BATCH = ['id' => 'batch-1', 'status' => 'uploaded'];

	/**
	 * Every known step.
	 *
	 * @return array<string, array{string}>
	 */
	public static function steps(): array {
		$cases = [];
		foreach (self::STEPS as $step) {
			$cases[$step] = [$step];
		}

		return $cases;

	}//end steps()

	/**
	 * A known step runs that method only, on the batch, and returns its result.
	 *
	 * @param string $step The step.
	 *
	 * @dataProvider steps
	 *
	 * @return void
	 */
	public function testAKnownStepRunsThatMethodOnly(string $step): void {
		$steps = $this->createMock(ImportBatchSteps::class);
		foreach (self::STEPS as $other) {
			if ($other === $step) {
				$steps->expects($this->once())->method($other)->with(self::BATCH)->willReturn(['id' => 'batch-1', 'status' => $step]);
				continue;
			}

			$steps->expects($this->never())->method($other);
		}

		$this->assertSame(
			['id' => 'batch-1', 'status' => $step],
			(new ImportBatchAction($steps))->execute(self::BATCH, [], ['step' => $step], 'run')
		);

	}//end testAKnownStepRunsThatMethodOnly()

	/**
	 * Unknown, missing, differently cased or non-step method names are refused with the step named.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function refusedParameters(): array {
		return [
			'missing'          => [[], ''],
			'unknown'          => [['step' => 'archive'], 'archive'],
			'other casing'     => [['step' => 'Parse'], 'Parse'],
			'public non-step'  => [['step' => '__construct'], '__construct'],
		];

	}//end refusedParameters()

	/**
	 * A step outside the list throws and runs nothing.
	 *
	 * @param array<string, mixed> $parameters The action parameters.
	 * @param string               $named      The step the message names.
	 *
	 * @dataProvider refusedParameters
	 *
	 * @return void
	 */
	public function testAStepOutsideTheListIsRefused(array $parameters, string $named): void {
		$steps = $this->createMock(ImportBatchSteps::class);
		foreach (self::STEPS as $step) {
			$steps->expects($this->never())->method($step);
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(sprintf('ImportBatchAction does not know the step "%s".', $named));

		(new ImportBatchAction($steps))->execute(self::BATCH, [], $parameters, 'run');

	}//end testAStepOutsideTheListIsRefused()
}//end class
