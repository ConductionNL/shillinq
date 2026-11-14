<?php

/**
 * Unit tests for the RunsUnderSystemIdentity repair helper.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/app-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair\Support;

use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use PHPUnit\Framework\TestCase;

/**
 * A repair step stand-in exposing the trait's helper.
 */
final class SystemIdentityStep {
	use RunsUnderSystemIdentity;

	/**
	 * Run the work through the trait.
	 *
	 * @param object|null $objectService The object service.
	 * @param callable    $work          The work.
	 *
	 * @return void
	 */
	public function run(?object $objectService, callable $work): void {
		$this->withSystemIdentity(objectService: $objectService, work: $work);
	}//end run()
}//end class

/**
 * @covers \OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity
 *
 * @spec openspec/specs/app-administration/spec.md
 */
final class RunsUnderSystemIdentityTest extends TestCase {

	/**
	 * The work runs inside OpenRegister's system identity when it offers one.
	 *
	 * @return void
	 */
	public function testTheWorkRunsUnderTheSystemIdentity(): void {
		$service = new class {
			/**
			 * Whether the system identity is in place.
			 *
			 * @var bool
			 */
			public bool $asSystem = false;

			/**
			 * Run under the system identity.
			 *
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$this->asSystem = true;
				$work();
				$this->asSystem = false;
			}
		};

		$seen = [];
		(new SystemIdentityStep())->run(
			objectService: $service,
			work: static function () use ($service, &$seen): void {
				$seen[] = $service->asSystem;
			}
		);

		self::assertSame(expected: [true], actual: $seen);
	}//end testTheWorkRunsUnderTheSystemIdentity()

	/**
	 * Without OpenRegister, or one without runAsSystem, the work still runs once.
	 *
	 * @return void
	 */
	public function testTheWorkStillRunsWithoutASystemIdentity(): void {
		$runs = 0;
		$work = static function () use (&$runs): void {
			$runs++;
		};

		(new SystemIdentityStep())->run(objectService: null, work: $work);
		(new SystemIdentityStep())->run(objectService: new class {
		}, work: $work);

		self::assertSame(expected: 2, actual: $runs);
	}//end testTheWorkStillRunsWithoutASystemIdentity()
}//end class
