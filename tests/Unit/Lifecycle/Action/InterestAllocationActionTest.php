<?php

/**
 * The lifecycle action that calculates or posts an interest allocation run.
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

use OCA\Shillinq\Lifecycle\Action\InterestAllocationAction;
use OCA\Shillinq\Service\PublicSector\InterestAllocationService;
use PHPUnit\Framework\TestCase;

/**
 * The `step` parameter picks post; anything else calculates.
 *
 * @covers \OCA\Shillinq\Lifecycle\Action\InterestAllocationAction
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InterestAllocationActionTest extends TestCase {

	private const RUN = ['id' => 'run-1', 'year' => 2026];

	/**
	 * Step "post" posts the run and returns the posted run.
	 *
	 * @return void
	 */
	public function testThePostStepPostsTheRun(): void {
		$service = $this->createMock(InterestAllocationService::class);
		$service->expects($this->never())->method('calculate');
		$service->expects($this->once())->method('post')->with(self::RUN)->willReturn(['id' => 'run-1', 'status' => 'posted']);

		$this->assertSame(
			['id' => 'run-1', 'status' => 'posted'],
			(new InterestAllocationAction($service))->execute(self::RUN, [], ['step' => 'post'], 'post')
		);

	}//end testThePostStepPostsTheRun()

	/**
	 * Without a step, or with any other step, the run is calculated.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function calculatingParameters(): array {
		return [
			'no step'        => [[]],
			'calculate step' => [['step' => 'calculate']],
			'other casing'   => [['step' => 'Post']],
		];

	}//end calculatingParameters()

	/**
	 * Anything but step "post" calculates.
	 *
	 * @param array<string, mixed> $parameters The action parameters.
	 *
	 * @dataProvider calculatingParameters
	 *
	 * @return void
	 */
	public function testAnyOtherStepCalculates(array $parameters): void {
		$service = $this->createMock(InterestAllocationService::class);
		$service->expects($this->never())->method('post');
		$service->expects($this->once())->method('calculate')->with(self::RUN)->willReturn(['id' => 'run-1', 'total' => 42.0]);

		$this->assertSame(
			['id' => 'run-1', 'total' => 42.0],
			(new InterestAllocationAction($service))->execute(self::RUN, [], $parameters, 'post')
		);

	}//end testAnyOtherStepCalculates()
}//end class
