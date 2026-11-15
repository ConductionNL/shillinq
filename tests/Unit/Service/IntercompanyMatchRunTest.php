<?php

/**
 * Characterisation tests for IntercompanyMatchingService::matchRelationPeriod().
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
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\IntercompanyMatchingCalculator;
use OCA\Shillinq\Service\IntercompanyMatchingService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the persisted match payload, the follow-up record per status, the
 * roll-forward warning and the prior-period choice of matchRelationPeriod().
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class IntercompanyMatchRunTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Recording ObjectService double.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The service under test.
	 *
	 * @var IntercompanyMatchingService
	 */
	private IntercompanyMatchingService $service;

	/**
	 * Set up the service on a filtering store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$this->store = new class {

			public string $schema = '';

			/** @var array<string,array<int,array<string,mixed>>> */
			public array $rows = [];

			/** @var array<int,array{0:string,1:array<string,mixed>}> */
			public array $saves = [];

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				$filters = ($args['filters'] ?? []);
				return array_values(
					array_filter(
						($this->rows[$this->schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($row[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}

			public function saveObject(array $object): array {
				$this->saves[] = [$this->schema, $object];
				return $object;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		$this->store->rows['IntercompanyRelation'] = [
			[
				'relationId' => 'rel1',
				'administrationId' => 'adm1',
				'entityAId' => 'entA',
				'entityBId' => 'entB',
				'defaultAccountA' => '8200',
				'defaultAccountB' => '4400',
			],
		];

		$this->service = new IntercompanyMatchingService(
			$appConfig,
			new IntercompanyMatchingCalculator(),
			$this->logger,
			objectService: new DuckObjectServiceAdapter($this->store),
		);

	}//end setUp()

	/**
	 * Seed the relation's transactions.
	 *
	 * @param float  $amountA  Side A debit.
	 * @param float  $amountB  Side B debit.
	 * @param string $currency Side B currency.
	 *
	 * @return void
	 */
	private function seed(float $amountA, float $amountB, string $currency): void {
		$this->store->rows['IntercompanyTransaction'] = [
			['id' => 't1', 'relationId' => 'rel1', 'sourceAdministrationId' => 'entA', 'debitAmount' => $amountA, 'creditAmount' => 0.0],
			['@self' => ['id' => 't2'], 'relationId' => 'rel1', 'sourceAdministrationId' => 'entB', 'debitAmount' => $amountB, 'currency' => $currency],
			['id' => 't3', 'relationId' => 'rel1', 'sourceAdministrationId' => 'other', 'debitAmount' => 5.0],
		];

	}//end seed()

	/**
	 * The schemas saved, in order.
	 *
	 * @return array<int,string>
	 */
	private function savedSchemas(): array {
		return array_column($this->store->saves, 0);

	}//end savedSchemas()

	/**
	 * A within-tolerance match: full payload, returned as built, followed by an
	 * elimination journal.
	 *
	 * @return void
	 */
	public function testWithinToleranceMatch(): void {
		$this->seed(1000.0, 995.0, 'EUR');
		$this->logger->expects(self::never())->method('warning');

		$match = $this->service->matchRelationPeriod('rel1', 'p-1');

		self::assertSame(
			[
				'matchId' => 'match-rel1-p-1',
				'periodId' => 'p-1',
				'relationId' => 'rel1',
				'entityATransactionIds' => ['t1'],
				'entityBTransactionIds' => ['t2'],
				'totalAmountA' => 1000.0,
				'totalAmountB' => 995.0,
				'mismatchAmount' => 5.0,
				'mismatchPercentage' => $match['mismatchPercentage'],
				'matchStatus' => 'within-tolerance',
				'administrationId' => 'adm1',
			],
			$match
		);
		self::assertSame(['IntercompanyMatch', 'EliminationJournal'], $this->savedSchemas());
		self::assertSame($match, $this->store->saves[0][1]);
		self::assertSame('elim-match-rel1-p-1', $this->store->saves[1][1]['eliminationId']);

	}//end testWithinToleranceMatch()

	/**
	 * An outside-tolerance FX match raises a mismatch; the latest other
	 * period is checked for roll-forward and a gap is warned about before
	 * the match is saved.
	 *
	 * @return void
	 */
	public function testOutsideToleranceWithRollForwardWarning(): void {
		$this->seed(1000.0, 500.0, 'USD');
		$this->store->rows['IntercompanyMatch'] = [
			['relationId' => 'rel1', 'periodId' => 'p-0', 'totalAmountA' => 1, 'totalAmountB' => 1],
			['relationId' => 'rel1', 'periodId' => 'p-2', 'totalAmountA' => 9, 'totalAmountB' => 1],
			['relationId' => 'rel1', 'periodId' => 'p-9'],
			['relationId' => 'rel1', 'periodId' => ''],
			['relationId' => 'other', 'periodId' => 'p-8'],
		];
		$this->logger->expects(self::once())->method('warning')->with(
			'IntercompanyMatchingService: roll-forward inconsistency — possible backdated change (REQ-ICE-008)',
			['relationId' => 'rel1', 'priorPeriodId' => 'p-2', 'currentPeriodId' => 'p-9']
		);

		$match = $this->service->matchRelationPeriod('rel1', 'p-9');

		self::assertSame('outside-tolerance', $match['matchStatus']);
		self::assertSame(['IntercompanyMatch', 'IntercompanyMismatch'], $this->savedSchemas());
		$mismatch = $this->store->saves[1][1];
		self::assertSame('mismatch-match-rel1-p-9', $mismatch['mismatchId']);
		self::assertSame(500.0, $mismatch['amount']);
		self::assertSame(
			(new IntercompanyMatchingCalculator())->defaultCauseClassification(currencyDiff: true),
			$mismatch['causeClassification']
		);

	}//end testOutsideToleranceWithRollForwardWarning()

	/**
	 * An unknown relation yields an empty match on both sides.
	 *
	 * @return void
	 */
	public function testUnknownRelation(): void {
		$match = $this->service->matchRelationPeriod('rel-x', 'p-1');

		self::assertSame([], $match['entityATransactionIds']);
		self::assertSame('', $match['administrationId']);
		self::assertSame('IntercompanyMatch', $this->savedSchemas()[0]);

	}//end testUnknownRelation()
}//end class
