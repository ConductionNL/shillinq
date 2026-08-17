<?php

/**
 * Unit tests for IntercompanyLinkListener.
 *
 * Correctness proof for tasks.md Task 15. On the pre-change codebase every
 * method of `IntercompanyJournalService` — `buildMirror()`,
 * `reconcileVariance()`, `isBalanced()` — had zero callers, so an
 * intercompany pair could be linked without the mirror ever being created
 * and without the afwijking between the two sides ever being computed. These
 * tests drive the REAL `IntercompanyJournalService` + `IntercompanyLinkService`
 * over an in-memory ObjectService and assert the mirror and the variance
 * actually land on the records.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/revive-gl-tax-capabilities/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\IntercompanyLinkListener;
use OCA\Shillinq\Service\IntercompanyJournalService;
use OCA\Shillinq\Service\IntercompanyLinkService;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Service/InMemoryObjectService.php';

/**
 * Tests the IntercompanyJournalEntry `gekoppeld` → mirror + reconcile wiring.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class IntercompanyLinkListenerTest extends TestCase {

	/**
	 * The in-memory ObjectService backing the chain.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $objects;

	/**
	 * The listener under test, wired to the REAL services.
	 *
	 * @var IntercompanyLinkListener
	 */
	private IntercompanyLinkListener $listener;

	/**
	 * Set up the real service chain over an in-memory ObjectService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = new InMemoryObjectService();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$linkService = new IntercompanyLinkService(
			appConfig: $appConfig,
			journalService: new IntercompanyJournalService(),
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($this->objects),
		);

		$this->listener = new IntercompanyLinkListener(
			linkService: $linkService,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Build a source-side entry payload.
	 *
	 * @param float $amount The booked source amount.
	 *
	 * @return array<string,mixed>
	 */
	private function sourceEntry(float $amount): array {
		return [
			'id' => 'ic-src',
			'intercompanyNumber' => 'IC-2026-0001',
			'date' => '2026-06-30',
			'kind' => 'managementfee',
			'sourceAdministrationId' => 'adm-werk',
			'destinationAdministrationId' => 'adm-beheer',
			'amount' => $amount,
			'currency' => 'EUR',
			'description' => 'Managementfee Q2',
			'status' => 'gekoppeld',
		];
	}//end sourceEntry()

	/**
	 * Fire the `gekoppeld` transition for the given entry payload.
	 *
	 * @param array<string,mixed> $entry The entry payload.
	 *
	 * @return void
	 */
	private function link(array $entry): void {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn('IntercompanyJournalEntry');
		$entity->method('getObject')->willReturn($entry);

		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			[
				'getObject' => $entity,
				'getTo' => 'gekoppeld',
				'getSchema' => 'IntercompanyJournalEntry',
			]
		);

		$this->listener->handle($event);

	}//end link()

	/**
	 * Look up a persisted entry by id.
	 *
	 * @param string $id The entry id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function entry(string $id): ?array {
		foreach ($this->objects->dump('IntercompanyJournalEntry') as $row) {
			if (($row['id'] ?? '') === $id) {
				return $row;
			}
		}

		return null;
	}//end entry()

	/**
	 * Linking a one-sided entry creates the mirror in the destination
	 * administration and reconciles at zero variance (REQ-GLTAX-002).
	 *
	 * @return void
	 */
	public function testLinkingCreatesTheMirrorWhenTheCounterSideIsAbsent(): void {
		$source = $this->sourceEntry(amount: 1000.0);
		$this->objects->seed('IntercompanyJournalEntry', [$source]);

		$this->link($source);

		$rows = $this->objects->dump('IntercompanyJournalEntry');
		self::assertCount(2, $rows, 'The mirrored destination-side entry must be created.');

		$mirror = null;
		foreach ($rows as $row) {
			if (($row['id'] ?? '') !== 'ic-src') {
				$mirror = $row;
			}
		}

		self::assertNotNull($mirror);
		self::assertSame('adm-beheer', $mirror['sourceAdministrationId'], 'The mirror swaps the administrations.');
		self::assertSame('adm-werk', $mirror['destinationAdministrationId']);
		self::assertSame(1000.0, (float)$mirror['amount']);
		self::assertSame(0.0, (float)$mirror['varianceAmount'], 'A freshly mirrored pair reconciles exactly.');
		self::assertSame(0.0, (float)$this->entry('ic-src')['varianceAmount']);

	}//end testLinkingCreatesTheMirrorWhenTheCounterSideIsAbsent()

	/**
	 * A counter-side booked at a different amount yields a non-zero variance
	 * persisted on BOTH sides (REQ-GLTAX-002).
	 *
	 * @return void
	 */
	public function testDivergingCounterSideIsFlaggedWithItsVariance(): void {
		$source = $this->sourceEntry(amount: 1000.0);
		$counter = [
			'id' => 'ic-dst',
			'intercompanyNumber' => 'IC-2026-0001',
			'sourceAdministrationId' => 'adm-beheer',
			'destinationAdministrationId' => 'adm-werk',
			'amount' => 950.0,
			'currency' => 'EUR',
			'status' => 'gekoppeld',
		];

		$this->objects->seed('IntercompanyJournalEntry', [$source, $counter]);

		$this->link($source);

		self::assertCount(2, $this->objects->dump('IntercompanyJournalEntry'), 'No mirror is created when the counter-side exists.');
		self::assertSame(50.0, (float)$this->entry('ic-src')['varianceAmount'], 'Variance = |1000 - 950|.');
		self::assertSame(50.0, (float)$this->entry('ic-dst')['varianceAmount'], 'The variance lands on both sides.');

	}//end testDivergingCounterSideIsFlaggedWithItsVariance()

	/**
	 * A transition to another state does nothing (REQ-GLTAX-002).
	 *
	 * @return void
	 */
	public function testOtherTransitionIsIgnored(): void {
		$source = $this->sourceEntry(amount: 1000.0);
		$this->objects->seed('IntercompanyJournalEntry', [$source]);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn('IntercompanyJournalEntry');
		$entity->method('getObject')->willReturn($source);

		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			[
				'getObject' => $entity,
				'getTo' => 'bevestigd_beide',
				'getSchema' => 'IntercompanyJournalEntry',
			]
		);

		$this->listener->handle($event);

		self::assertCount(1, $this->objects->dump('IntercompanyJournalEntry'));

	}//end testOtherTransitionIsIgnored()
}//end class
