<?php

/**
 * Every ObjectServiceInterface double in this suite declares the raw append and purge pair.
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
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * openregister#3406 named two shillinq doubles; the local contract copy found
 * six. A double that misses a contract method fatals at class LOAD, which takes
 * the whole suite down before a single test runs, so loading each one is the
 * assertion. The decorators must also hand the pair on, and the fixture stubs
 * must refuse it by name rather than answer zero.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ObjectServiceDoublesDeclareRawPairTest extends TestCase {

	/**
	 * Every double loads and satisfies the contract.
	 *
	 * @return void
	 */
	public function testEveryDoubleStillImplementsTheContract(): void {
		$inner = new InMemoryObjectServiceStub();
		$doubles = [
			$inner,
			new DuckObjectServiceAdapter(inner: $inner),
			new CallCountingObjectServiceDecorator(inner: $inner),
			new RacingDefaultObjectServiceDecorator(inner: $inner, racerId: 'r', administrationId: 'a'),
			new FilteredObjectServiceStub(data: []),
			new KnownCostFixtureObjectServiceStub(),
		];

		foreach ($doubles as $double) {
			$this->assertInstanceOf(ObjectServiceInterface::class, $double);
		}
	}//end testEveryDoubleStillImplementsTheContract()

	/**
	 * Both decorators hand append then purge to the wrapped store.
	 *
	 * @return void
	 */
	public function testDecoratorsPassAppendThenPurgeThrough(): void {
		foreach (['counting', 'racing'] as $kind) {
			$inner = new InMemoryObjectServiceStub();
			$decorator = match ($kind) {
				'counting' => new CallCountingObjectServiceDecorator(inner: $inner),
				'racing' => new RacingDefaultObjectServiceDecorator(inner: $inner, racerId: 'r', administrationId: 'a'),
			};

			$written = $decorator->appendObjectsRaw(
				objects: [
					['uuid' => 'gone', 'expires' => '2000-01-01T00:00:00+00:00'],
					['uuid' => 'stays'],
				],
				register: 'shillinq',
				schema: 'traffic-event'
			);

			$this->assertSame(2, $written, $kind);
			$this->assertSame(1, $decorator->purgeExpiredObjectsRaw(register: 'shillinq', schema: 'traffic-event'), $kind);
			$this->assertSame(
				['stays'],
				array_column($inner->setSchema(schema: 'traffic-event')->findAll(), 'uuid'),
				$kind
			);
		}
	}//end testDecoratorsPassAppendThenPurgeThrough()

	/**
	 * The fixture stubs refuse the pair by name.
	 *
	 * @return void
	 */
	public function testFixtureStubsRefuseByName(): void {
		$stubs = [
			new FilteredObjectServiceStub(data: []),
			new KnownCostFixtureObjectServiceStub(),
		];

		foreach ($stubs as $stub) {
			$calls = [
				'appendObjectsRaw' => static fn (): int => $stub->appendObjectsRaw(objects: [], register: 'shillinq', schema: 'traffic-event'),
				'purgeExpiredObjectsRaw' => static fn (): int => $stub->purgeExpiredObjectsRaw(register: 'shillinq', schema: 'traffic-event'),
			];
			foreach ($calls as $method => $call) {
				try {
					$call();
					$this->fail($stub::class . '::' . $method . '() answered instead of refusing');
				} catch (LogicException $e) {
					$this->assertStringContainsString($method . '()', $e->getMessage());
				}
			}
		}
	}//end testFixtureStubsRefuseByName()
}//end class
