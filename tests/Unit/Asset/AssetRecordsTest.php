<?php

/**
 * Unit tests for AssetRecords: scoping to the register of the app, record
 * ids, and posting journals through the transition engine.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Asset
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

namespace OCA\Shillinq\Tests\Unit\Asset;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Reads and writes go to the register of the app and the named schema;
 * records always carry an id; a journal is saved and then posted directly.
 *
 * @covers \OCA\Shillinq\Service\Asset\AssetRecords
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AssetRecordsTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objects;

	/**
	 * The transition runner.
	 *
	 * @var ObjectTransitionRunner&MockObject
	 */
	private ObjectTransitionRunner $transitions;

	/**
	 * The register and schema each scoped call selected, in order.
	 *
	 * @var array<int, string>
	 */
	private array $scopes = [];

	/**
	 * An object service that records its scoping.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->scopes = [];
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('setRegister')->willReturnCallback(function (string|int $register) {
			$this->scopes[] = 'register:' . $register;
			return $this->objects;
		});
		$this->objects->method('setSchema')->willReturnCallback(function (string|int $schema) {
			$this->scopes[] = 'schema:' . $schema;
			return $this->objects;
		});
		$this->transitions = $this->createMock(ObjectTransitionRunner::class);
	}//end setUp()

	/**
	 * The service under test, on register "shillinq".
	 *
	 * @return AssetRecords
	 */
	private function records(): AssetRecords {
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new AssetRecords($this->objects, $this->transitions, $settings);
	}//end records()

	/**
	 * A stored object as the store hands it back.
	 *
	 * @param array<string, mixed> $payload The object data.
	 *
	 * @return ObjectEntityStub
	 */
	private function entity(array $payload): ObjectEntityStub {
		return new ObjectEntityStub(payload: $payload, register: 'shillinq', schema: 'FixedAsset');
	}//end entity()

	/**
	 * An empty id finds nothing without asking the store.
	 *
	 * @return void
	 */
	public function testAnEmptyIdFindsNothing(): void {
		$this->objects->expects($this->never())->method('find');

		$this->assertNull($this->records()->find('FixedAsset', ''));
	}//end testAnEmptyIdFindsNothing()

	/**
	 * A found record without its own id gets the id it was asked for.
	 *
	 * @return void
	 */
	public function testAFoundRecordCarriesTheIdItWasFoundBy(): void {
		$this->objects->method('find')->willReturn($this->entity(['name' => 'Van', 'id' => '']));

		$this->assertSame(['name' => 'Van', 'id' => 'asset-1'], $this->records()->find('FixedAsset', 'asset-1'));
		$this->assertSame(['register:shillinq', 'schema:FixedAsset'], array_slice($this->scopes, 0, 2));
	}//end testAFoundRecordCarriesTheIdItWasFoundBy()

	/**
	 * A found record keeps its own id.
	 *
	 * @return void
	 */
	public function testAFoundRecordKeepsItsOwnId(): void {
		$this->objects->method('find')->willReturn($this->entity(['id' => 'uuid-9', 'name' => 'Van']));

		$this->assertSame(['id' => 'uuid-9', 'name' => 'Van'], $this->records()->find('FixedAsset', 'VAN-01'));
	}//end testAFoundRecordKeepsItsOwnId()

	/**
	 * Listing filters on the schema with a high limit and drops rows that are no record.
	 *
	 * @return void
	 */
	public function testRecordsFiltersAndSkipsNonRecords(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['status' => 'active'], 'limit' => 10000])
			->willReturn([['id' => 'a-1'], 'junk', [], ['id' => 'a-2', 'name' => 'Van']]);

		$this->assertSame([['id' => 'a-1'], ['id' => 'a-2', 'name' => 'Van']], $this->records()->records('FixedAsset', ['status' => 'active']));
		$this->assertSame(['register:shillinq', 'schema:FixedAsset'], $this->scopes);
	}//end testRecordsFiltersAndSkipsNonRecords()

	/**
	 * Saving returns the stored record, or the object given when the store answers nothing usable.
	 *
	 * @return void
	 */
	public function testSaveReturnsTheStoredRecordOrTheObjectGiven(): void {
		$this->objects->expects($this->exactly(2))->method('saveObject')
			->with(['name' => 'Van'])
			->willReturnOnConsecutiveCalls($this->entity(['id' => 'new-1', 'name' => 'Van']), $this->entity([]));

		$this->assertSame(['id' => 'new-1', 'name' => 'Van'], $this->records()->save('FixedAsset', ['name' => 'Van']));
		$this->assertSame(['name' => 'Van'], $this->records()->save('FixedAsset', ['name' => 'Van']));
	}//end testSaveReturnsTheStoredRecordOrTheObjectGiven()

	/**
	 * Patch and delete name the register and schema explicitly.
	 *
	 * @return void
	 */
	public function testPatchAndDeleteNameRegisterAndSchema(): void {
		$this->objects->expects($this->once())->method('patchObject')
			->with('a-1', ['currentBookValue' => 450.0], 'shillinq', 'FixedAsset');
		$this->objects->expects($this->once())->method('deleteObject')
			->with('line-1', 'shillinq', 'DepreciationLine');

		$records = $this->records();
		$records->patch('FixedAsset', 'a-1', ['currentBookValue' => 450.0]);
		$records->delete('DepreciationLine', 'line-1');

		$this->assertSame([], $this->scopes, 'patch and delete do not change the scoped register');
	}//end testPatchAndDeleteNameRegisterAndSchema()

	/**
	 * A journal is saved as a JournalEntry, then posted directly under its new id.
	 *
	 * @return void
	 */
	public function testAJournalIsSavedThenPostedDirectly(): void {
		$journal = ['number' => 'AFS-2026-06', 'lines' => []];
		$this->objects->expects($this->once())->method('saveObject')->with($journal)->willReturn($this->entity(['id' => 'je-7'] + $journal));
		$this->transitions->expects($this->once())->method('run')->with('je-7', 'postDirect');

		$this->assertSame('je-7', $this->records()->postJournal($journal));
		$this->assertSame(['register:shillinq', 'schema:JournalEntry'], $this->scopes);
	}//end testAJournalIsSavedThenPostedDirectly()
}//end class
