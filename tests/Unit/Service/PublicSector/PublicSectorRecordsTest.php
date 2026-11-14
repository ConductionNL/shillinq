<?php

/**
 * Unit tests for PublicSectorRecords: register-scoped reads and writes of
 * the public-sector schemas, and posting a journal entry through its
 * lifecycle transition.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PublicSector
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

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;

use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Register-scoped public-sector records.
 */
#[CoversClass(PublicSectorRecords::class)]
#[UsesClass(ObjectIdentifier::class)]
final class PublicSectorRecordsTest extends TestCase {

	/**
	 * Captured saves.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Mock transition runner.
	 *
	 * @var ObjectTransitionRunner&MockObject
	 */
	private ObjectTransitionRunner&MockObject $transitions;

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub|null
	 */
	private ?InMemoryObjectServiceStub $store = null;

	/**
	 * Build the service over rows per schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema
	 *
	 * @return PublicSectorRecords
	 */
	private function records(array $rows = []): PublicSectorRecords {
		$this->saved = [];
		$this->store = new InMemoryObjectServiceStub($rows, $this->saved, true);
		$this->transitions = $this->createMock(ObjectTransitionRunner::class);
		$location = $this->createStub(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new PublicSectorRecords($this->store, $this->transitions, $location);

	}//end records()

	/**
	 * A record is found by id in its schema and keeps its id.
	 *
	 * @return void
	 */
	public function testFindReturnsTheRecordWithItsId(): void {
		$service = $this->records(
			[
				'Reserve' => [['id' => 'r-1', 'name' => 'Algemene reserve']],
				'Investment' => [['id' => 'r-2', 'name' => 'School']],
			]
		);

		$found = $service->find('Reserve', 'r-1');

		$this->assertSame('r-1', $found['id']);
		$this->assertSame('Algemene reserve', $found['name']);
		$this->assertNull($service->find('Reserve', 'r-2'));
		$this->assertNull($service->find('Reserve', 'r-404'));

	}//end testFindReturnsTheRecordWithItsId()

	/**
	 * An empty id finds nothing.
	 *
	 * @return void
	 */
	public function testFindWithEmptyIdIsNull(): void {
		$this->assertNull($this->records(['Reserve' => [['id' => '', 'name' => 'x']]])->find('Reserve', ''));

	}//end testFindWithEmptyIdIsNull()

	/**
	 * Records are filtered in their schema and each carries an id.
	 *
	 * @return void
	 */
	public function testRecordsFiltersWithinTheSchema(): void {
		$service = $this->records(
			[
				'Reserve' => [
					['id' => 'r-1', 'administrationId' => 'adm-1'],
					['id' => 'r-2', 'administrationId' => 'adm-2'],
					['id' => 'r-3', 'administrationId' => 'adm-1'],
				],
				'Investment' => [['id' => 'i-1', 'administrationId' => 'adm-1']],
			]
		);

		$found = $service->records('Reserve', ['administrationId' => 'adm-1']);

		$this->assertSame(['r-1', 'r-3'], array_column($found, 'id'));
		$this->assertSame([], $service->records('Reserve', ['administrationId' => 'adm-9']));

	}//end testRecordsFiltersWithinTheSchema()

	/**
	 * Creating saves the object in its schema and answers the new id.
	 *
	 * @return void
	 */
	public function testCreateSavesAndReturnsTheId(): void {
		$service = $this->records();

		$id = $service->create('Reserve', ['name' => 'Bestemmingsreserve']);

		$this->assertNotSame('', $id);
		$this->assertCount(1, $this->saved);
		$this->assertSame('Reserve', $this->saved[0]['schema']);
		$this->assertSame('Bestemmingsreserve', $this->saved[0]['object']['name']);
		$this->assertSame($id, $service->find('Reserve', $id)['id']);

	}//end testCreateSavesAndReturnsTheId()

	/**
	 * Posting a journal creates the JournalEntry and runs its postDirect transition on it.
	 *
	 * @return void
	 */
	public function testPostJournalCreatesAndPostsTheEntry(): void {
		$service = $this->records();
		$posted = [];
		$this->transitions->expects($this->once())
			->method('run')
			->willReturnCallback(
				function (string $objectId, string $action) use (&$posted): void {
					$posted = [$objectId, $action];
				}
			);

		$id = $service->postJournal(['description' => 'Rentetoerekening 2026']);

		$this->assertSame([$id, 'postDirect'], $posted);
		$this->assertSame('Rentetoerekening 2026', $service->find('JournalEntry', $id)['description']);

	}//end testPostJournalCreatesAndPostsTheEntry()
}//end class
