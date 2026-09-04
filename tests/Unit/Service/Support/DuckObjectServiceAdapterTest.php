<?php

/**
 * Unit tests for the raw append and purge pass-through on DuckObjectServiceAdapter.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude contract-shift follow-up for openregister#3407; no shillinq spec owns the raw append path
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Support;

use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The adapter must declare appendObjectsRaw() and purgeExpiredObjectsRaw()
 * (openregister#3407, contract-shift openregister#3406). It hands both to the
 * wrapped double verbatim, and refuses by name when the double lacks them.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DuckObjectServiceAdapterTest extends TestCase {

	/**
	 * Append then purge reach the in-memory double, and the survivors are visible through inner().
	 *
	 * @return void
	 */
	public function testAppendThenPurgePassThroughToTheDouble(): void {
		$inner = new InMemoryObjectServiceStub();
		$adapter = new DuckObjectServiceAdapter(inner: $inner);

		$written = $adapter->appendObjectsRaw(
			objects: [
				['uuid' => 'gone', 'expires' => '2000-01-01T00:00:00+00:00'],
				['uuid' => 'stays'],
			],
			register: 'shillinq',
			schema: 'traffic-event'
		);

		$this->assertSame(2, $written);
		$this->assertSame(1, $adapter->purgeExpiredObjectsRaw(register: 'shillinq', schema: 'traffic-event'));
		$this->assertSame(
			['stays'],
			array_column($inner->setSchema(schema: 'traffic-event')->findAll(), 'uuid')
		);

	}//end testAppendThenPurgePassThroughToTheDouble()

	/**
	 * The arguments arrive at the double untouched, register and schema included.
	 *
	 * @return void
	 */
	public function testArgumentsAreHandedOnVerbatim(): void {
		$recorder = new class {
			/**
			 * Every call, as [method, args].
			 *
			 * @var array<int,array{0:string,1:array<int,mixed>}>
			 */
			public array $calls = [];

			/**
			 * Record the append.
			 *
			 * @param array      $objects  Rows.
			 * @param string|int $register Register.
			 * @param string|int $schema   Schema.
			 *
			 * @return int
			 */
			public function appendObjectsRaw(array $objects, string|int $register, string|int $schema): int {
				$this->calls[] = ['appendObjectsRaw', [$objects, $register, $schema]];
				return 7;
			}

			/**
			 * Record the sweep.
			 *
			 * @param string|int $register Register.
			 * @param string|int $schema   Schema.
			 *
			 * @return int
			 */
			public function purgeExpiredObjectsRaw(string|int $register, string|int $schema): int {
				$this->calls[] = ['purgeExpiredObjectsRaw', [$register, $schema]];
				return 3;
			}
		};
		$adapter = new DuckObjectServiceAdapter(inner: $recorder);

		$this->assertSame(7, $adapter->appendObjectsRaw(objects: [['a' => 1]], register: 12, schema: 'traffic-event'));
		$this->assertSame(3, $adapter->purgeExpiredObjectsRaw(register: 12, schema: 'traffic-event'));
		$this->assertSame(
			[
				['appendObjectsRaw', [[['a' => 1]], 12, 'traffic-event']],
				['purgeExpiredObjectsRaw', [12, 'traffic-event']],
			],
			$recorder->calls
		);

	}//end testArgumentsAreHandedOnVerbatim()

	/**
	 * A double without the pair makes the adapter refuse by name, not answer zero.
	 *
	 * @return void
	 */
	public function testRefusesByNameWhenTheDoubleLacksThePair(): void {
		$adapter = new DuckObjectServiceAdapter(inner: new class {
		});

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('appendObjectsRaw()');
		$adapter->appendObjectsRaw(objects: [], register: 'shillinq', schema: 'traffic-event');

	}//end testRefusesByNameWhenTheDoubleLacksThePair()

	/**
	 * The sweep refuses the same way.
	 *
	 * @return void
	 */
	public function testPurgeRefusesByNameWhenTheDoubleLacksIt(): void {
		$adapter = new DuckObjectServiceAdapter(inner: new class {
		});

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('purgeExpiredObjectsRaw()');
		$adapter->purgeExpiredObjectsRaw(register: 'shillinq', schema: 'traffic-event');

	}//end testPurgeRefusesByNameWhenTheDoubleLacksIt()
}//end class
