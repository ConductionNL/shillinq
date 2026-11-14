<?php

/**
 * Unit tests for the intercompany step of the nightly soft close: every
 * relation of the administration is matched by the real matching service and a
 * matched pair gets its elimination entry, once.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-intercompany-elimination/spec.md#requirement-the-nightly-soft-close-writes-the-elimination-entries-of-each-intercompany-relation-req-ice-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\IntercompanyMatchingCalculator;
use OCA\Shillinq\Service\IntercompanyMatchingService;
use OCA\Shillinq\Service\SoftCloseExecutor;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The soft close over the real IntercompanyMatchingService and an in-memory register.
 *
 * @covers \OCA\Shillinq\Service\SoftCloseExecutor
 * @uses   \OCA\Shillinq\Service\IntercompanyMatchingService
 * @uses   \OCA\Shillinq\Service\IntercompanyMatchingCalculator
 * @uses   \OCA\Shillinq\Service\IntercompanyMatchRun
 * @uses   \OCA\Shillinq\Service\SoftCloseRun
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SoftCloseIntercompanyEliminationTest extends TestCase {

	/**
	 * The in-memory register: rows per schema.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The soft close over the store, with the real matching service in the container.
	 *
	 * @param array<int,array<string,mixed>> $relations    IntercompanyRelation rows.
	 * @param array<int,array<string,mixed>> $transactions IntercompanyTransaction rows.
	 *
	 * @return SoftCloseExecutor
	 */
	private function executor(array $relations, array $transactions): SoftCloseExecutor {
		$this->store = new class(['IntercompanyRelation' => $relations, 'IntercompanyTransaction' => $transactions]) {
			/**
			 * Rows per schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $rows;

			/**
			 * The selected schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $rows The rows.
			 */
			public function __construct(array $rows) {
				$this->rows = $rows;
			}//end __construct()

			/**
			 * Select the register (ignored).
			 *
			 * @param string $register The register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Select the schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * The rows of the schema whose fields equal every filter.
			 *
			 * @param array<string,mixed> $config The query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config): array {
				$filters = (array)($config['filters'] ?? []);
				return array_values(
					array_filter(
						($this->rows[$this->schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (is_scalar($value) === true && ($row[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Store a row under the selected schema.
			 *
			 * @param array<string,mixed> $object The row.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->rows[$this->schema][] = $object;
				return $object;
			}//end saveObject()
		};

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$objects  = new DuckObjectServiceAdapter($this->store);
		$matching = new IntercompanyMatchingService($config, new IntercompanyMatchingCalculator(), new NullLogger(), $objects);

		$container = $this->createStub(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => $id === IntercompanyMatchingService::class);
		$container->method('get')->willReturn($matching);

		return new SoftCloseExecutor($container, $config, new NullLogger(), objectService: $objects);
	}//end executor()

	/**
	 * A relation between the parent and its subsidiary.
	 *
	 * @param string $relationId       The relation id.
	 * @param string $administrationId The administration it belongs to.
	 *
	 * @return array<string,mixed>
	 */
	private static function relation(string $relationId, string $administrationId): array {
		return [
			'relationId' => $relationId,
			'administrationId' => $administrationId,
			'entityAId' => 'adm-parent',
			'entityBId' => 'adm-sub',
			'defaultAccountA' => '1300',
			'defaultAccountB' => '1600',
			'toleranceAbsolute' => 10.0,
			'toleranceRelative' => 0.5,
		];
	}//end relation()

	/**
	 * One side of an intercompany booking.
	 *
	 * @param string $id         The transaction id.
	 * @param string $relationId The relation.
	 * @param string $source     The administration that booked it.
	 * @param float  $amount     The amount.
	 *
	 * @return array<string,mixed>
	 */
	private static function booking(string $id, string $relationId, string $source, float $amount): array {
		return ['id' => $id, 'relationId' => $relationId, 'sourceAdministrationId' => $source, 'debitAmount' => $amount, 'creditAmount' => 0.0, 'currency' => 'EUR'];
	}//end booking()

	/**
	 * The schema's stored rows.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function saved(string $schema): array {
		return ($this->store->rows[$schema] ?? []);
	}//end saved()

	/**
	 * An intercompany sale of EUR 12,000 matched both ways is eliminated
	 * overnight, once, however often the close runs.
	 *
	 * @return void
	 */
	public function testMatchedIntercompanySalesAreEliminatedOnce(): void {
		$executor = $this->executor(
			relations: [self::relation(relationId: 'rel-1', administrationId: 'adm-parent')],
			transactions: [
				self::booking(id: 't1', relationId: 'rel-1', source: 'adm-parent', amount: 12000.0),
				self::booking(id: 't2', relationId: 'rel-1', source: 'adm-sub', amount: 12000.0),
			]
		);

		$report = $executor->execute('adm-parent', '2026-10', new DateTimeImmutable('2026-11-01T02:00:00Z'));

		$this->assertSame(1, $report['intercompanyMatches']);
		$eliminations = $this->saved(schema: 'EliminationJournal');
		$this->assertCount(1, $eliminations);
		$this->assertSame('elim-match-rel-1-2026-10', $eliminations[0]['eliminationId']);
		$this->assertSame('2026-10', $eliminations[0]['consolidationPeriodId']);
		$this->assertEquals(12000.0, $eliminations[0]['totalDebit']);
		$this->assertEquals(12000.0, $eliminations[0]['totalCredit']);
		$this->assertSame('perfect-match', $this->saved(schema: 'IntercompanyMatch')[0]['matchStatus']);

		$again = $executor->execute('adm-parent', '2026-10', new DateTimeImmutable('2026-11-02T02:00:00Z'));

		$this->assertSame(1, $again['intercompanyMatches']);
		$this->assertCount(1, $this->saved(schema: 'EliminationJournal'), 'a rerun writes no second elimination');
		$this->assertCount(1, $this->saved(schema: 'IntercompanyMatch'));
	}//end testMatchedIntercompanySalesAreEliminatedOnce()

	/**
	 * Only the administration's own relations are matched; one outside its
	 * tolerance raises a mismatch and is not eliminated.
	 *
	 * @return void
	 */
	public function testOnlyOwnRelationsAreMatchedAndAMismatchIsNotEliminated(): void {
		$executor = $this->executor(
			relations: [
				self::relation(relationId: 'rel-off', administrationId: 'adm-parent'),
				self::relation(relationId: 'rel-other', administrationId: 'adm-elsewhere'),
			],
			transactions: [
				self::booking(id: 't1', relationId: 'rel-off', source: 'adm-parent', amount: 12000.0),
				self::booking(id: 't2', relationId: 'rel-off', source: 'adm-sub', amount: 9000.0),
				self::booking(id: 't3', relationId: 'rel-other', source: 'adm-parent', amount: 500.0),
				self::booking(id: 't4', relationId: 'rel-other', source: 'adm-sub', amount: 500.0),
			]
		);

		$report = $executor->execute('adm-parent', '2026-10', new DateTimeImmutable('2026-11-01T02:00:00Z'));

		$this->assertSame(0, $report['intercompanyMatches']);
		$this->assertSame([], $this->saved(schema: 'EliminationJournal'));
		$this->assertSame(['rel-off'], array_column($this->saved(schema: 'IntercompanyMatch'), 'relationId'));
		$this->assertCount(1, $this->saved(schema: 'IntercompanyMismatch'));
	}//end testOnlyOwnRelationsAreMatchedAndAMismatchIsNotEliminated()

	/**
	 * Without the matching service in the container the step matches nothing.
	 *
	 * @return void
	 */
	public function testWithoutTheMatchingServiceNothingIsMatched(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$container = $this->createStub(ContainerInterface::class);
		$container->method('has')->willReturn(false);
		$this->executor(relations: [self::relation(relationId: 'rel-1', administrationId: 'adm-parent')], transactions: []);

		$report = (new SoftCloseExecutor($container, $config, new NullLogger(), objectService: new DuckObjectServiceAdapter($this->store)))
			->execute('adm-parent', '2026-10', new DateTimeImmutable('2026-11-01T02:00:00Z'));

		$this->assertSame(0, $report['intercompanyMatches']);
		$this->assertSame([], $this->saved(schema: 'IntercompanyMatch'));
	}//end testWithoutTheMatchingServiceNothingIsMatched()
}//end class
