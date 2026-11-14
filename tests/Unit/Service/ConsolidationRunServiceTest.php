<?php

/**
 * Unit tests for ConsolidationRunService.
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
 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-consolidation-commercial/spec.md#requirement-a-consolidation-run-sums-the-subsidiaries-and-applies-the-eliminations-req-cons-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\TrialBalanceServiceInterface;
use OCA\Shillinq\Service\ConsolidationMappingService;
use OCA\Shillinq\Service\ConsolidationRunService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A consolidation run sums the members, mapped onto the parent's chart, and applies the eliminations (REQ-CONS-011).
 */
#[CoversClass(ConsolidationRunService::class)]
#[UsesClass(ConsolidationMappingService::class)]
#[UsesClass(ObjectIdentifier::class)]
final class ConsolidationRunServiceTest extends TestCase {

	/**
	 * Rows findAll() answers per schema.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $store = [];

	/**
	 * Records find() answers per schema and id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $records = [];

	/**
	 * Trial balance rows per "administration@period".
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $balances = [];

	/**
	 * The trial balances asked for, as "administration@period".
	 *
	 * @var string[]
	 */
	private array $balancesRead = [];

	/**
	 * Captured saveObject calls as [schema, object, uuid].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>,2:?string}>
	 */
	private array $saves = [];

	/**
	 * The schema the object service is scoped to.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Build the service over the in-memory store.
	 *
	 * @return ConsolidationRunService
	 */
	private function service(): ConsolidationRunService {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (string|int $schema) use ($objectService): ObjectServiceInterface {
				$this->schema = (string)$schema;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = []): array => ($this->store[$this->schema] ?? [])
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntityInterface {
				$record = ($this->records[$this->schema . '/' . (string)$id] ?? null);
				if ($record === null) {
					return null;
				}

				$entity = $this->createStub(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($record);
				return $entity;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->saves[] = [(string)$schema, $object, $uuid];
				return $this->createStub(ObjectEntityInterface::class);
			}
		);

		$trialBalance = $this->createMock(TrialBalanceServiceInterface::class);
		$trialBalance->method('compute')->willReturnCallback(
			function (string $administrationId, string $periodId): array {
				$key = $administrationId . '@' . $periodId;
				$this->balancesRead[] = $key;
				return ['data' => ($this->balances[$key] ?? [])];
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('books');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$logger = $this->createStub(LoggerInterface::class);

		return new ConsolidationRunService(
			trialBalance: $trialBalance,
			mapping: new ConsolidationMappingService(container: $container, appConfig: $config, logger: $logger),
			objectService: $objectService,
			appConfig: $config,
			logger: $logger,
		);
	}//end service()

	/**
	 * One trial balance row.
	 *
	 * @param string $account The account number.
	 * @param string $type    The account type.
	 * @param float  $closing The closing balance (debit positive).
	 *
	 * @return array<string,mixed>
	 */
	private static function row(string $account, string $type, float $closing): array {
		return ['accountNumber' => $account, 'accountType' => $type, 'closingBalance' => $closing];
	}//end row()

	/**
	 * The saved report of a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array{0:string,1:array<string,mixed>,2:?string}
	 */
	private function saved(string $schema): array {
		foreach ($this->saves as $save) {
			if ($save[0] === $schema) {
				return $save;
			}
		}

		self::fail('No ' . $schema . ' was saved.');
	}//end saved()

	/**
	 * The two-administration group of the spec scenario, with an intercompany receivable and sale.
	 *
	 * @return void
	 */
	private function seedGroup(): void {
		$this->records['ConsolidationGroup/g-1'] = ['parentAdministrationId' => 'adm-p', 'defaultConsolidationMethod' => 'integral'];
		$this->store['GroupEntity'] = [
			['consolidationGroupId' => 'g-1', 'administrationId' => 'adm-p', 'entityType' => 'parent', 'state' => 'active'],
			['consolidationGroupId' => 'g-1', 'administrationId' => 'adm-s', 'entityType' => 'subsidiary', 'state' => 'active', 'consolidationMethod' => 'integral'],
			['consolidationGroupId' => 'g-1', 'administrationId' => 'adm-e', 'entityType' => 'associate', 'state' => 'active', 'consolidationMethod' => 'equity'],
			['consolidationGroupId' => 'g-1', 'administrationId' => 'adm-d', 'entityType' => 'subsidiary', 'state' => 'draft'],
		];
		$this->store['ConsolidationMapping'] = [
			['sourceAdministrationId' => 'adm-s', 'destinationAdministrationId' => 'adm-p', 'validFrom' => '2026-01-01', 'rules' => [['sourceAccount' => '1100', 'destinationAccount' => '1000']]],
		];
		$this->balances['adm-p@2026-10'] = [
			self::row(account: '1000', type: 'assets', closing: 500000.0),
			self::row(account: '1600', type: 'liabilities', closing: -12000.0),
			self::row(account: '0800', type: 'equity', closing: -488000.0),
			self::row(account: '7200', type: 'expenses', closing: 5000.0),
		];
		$this->balances['adm-s@2026-10'] = [
			self::row(account: '1100', type: 'assets', closing: 188000.0),
			self::row(account: '1300', type: 'assets', closing: 12000.0),
			self::row(account: '0800', type: 'equity', closing: -170000.0),
			self::row(account: '8200', type: 'revenue', closing: -30000.0),
		];
		$this->store['EliminationEntry'] = [
			['id' => 'el-1', 'consolidationPeriodId' => 'cp-1', 'reviewStatus' => 'pending', 'lines' => [
				['accountNumber' => '1600', 'debit' => 12000, 'credit' => 0],
				['accountNumber' => '1300', 'debit' => 0, 'credit' => 12000],
			]],
			['id' => 'el-2', 'consolidationPeriodId' => 'cp-1', 'reviewStatus' => 'rejected', 'lines' => [['accountNumber' => '1000', 'credit' => 99999]]],
			['id' => 'el-3', 'consolidationPeriodId' => 'cp-9', 'reviewStatus' => 'approved', 'lines' => [['accountNumber' => '1000', 'credit' => 99999]]],
		];
		$this->store['EliminationJournal'] = [
			['id' => 'ej-1', 'consolidationPeriodId' => '2026-10', 'administrationId' => 'adm-s', 'lines' => [
				['glAccount' => '8200', 'debitAmount' => 5000.0, 'creditAmount' => 0.0],
				['glAccount' => '7200', 'debitAmount' => 0.0, 'creditAmount' => 5000.0],
			]],
			['id' => 'ej-2', 'consolidationPeriodId' => '2026-10', 'administrationId' => 'elsewhere', 'lines' => [['glAccount' => '1000', 'creditAmount' => 99999]]],
		];
		$this->store['ConsolidatedBalance'] = [['id' => 'cb-1', 'consolidationPeriodId' => 'cp-1', 'state' => 'draft']];
	}//end seedGroup()

	/**
	 * The spec scenario: EUR 500,000 and EUR 200,000 of assets with EUR 12,000 intercompany receivables consolidate to EUR 688,000.
	 *
	 * @return void
	 */
	public function testAGroupOfTwoAdministrationsIsConsolidated(): void {
		$this->seedGroup();

		$outcome = $this->service()->run(
			period: ['id' => 'cp-1', 'consolidationGroupId' => 'g-1', 'periodStart' => '2026-10-01', 'periodEnd' => '2026-10-31'],
			asOf: new DateTimeImmutable('2026-11-05T10:00:00+00:00')
		);

		self::assertSame(['adm-p@2026-10', 'adm-s@2026-10'], $this->balancesRead);
		self::assertSame(688000.0, $outcome['totalAssets']);
		self::assertSame(0.0, $outcome['totalLiabilities']);
		self::assertSame(658000.0, $outcome['totalEquity']);
		self::assertSame([25000.0, 0.0, 25000.0], [$outcome['totalRevenue'], $outcome['totalExpenses'], $outcome['netProfitTotal']]);
		self::assertSame([2, 2, 17000.0], [$outcome['memberCount'], $outcome['eliminationCount'], $outcome['eliminationAmount']]);

		[, $balance, $uuid] = $this->saved(schema: 'ConsolidatedBalance');
		self::assertSame('cb-1', $uuid);
		self::assertSame(
			['g-1', 'cp-1', '2026-10-31', 'draft', 688000.0, 0.0, 658000.0],
			[$balance['consolidationGroupId'], $balance['consolidationPeriodId'], $balance['reportDate'], $balance['state'], $balance['totalAssets'], $balance['totalLiabilities'], $balance['totalEquity']]
		);
		$byAccount = array_column($balance['lines'], null, 'rapportageregelnummer');
		self::assertSame(['0800', '1000', '1300', '1600'], array_map('strval', array_keys($byAccount)));
		self::assertSame([12000.0, 0.0, ['el-1']], [$byAccount['1300']['preEliminationTotal'], $byAccount['1300']['postEliminationTotal'], $byAccount['1300']['eliminationAdjustments']]);
		self::assertSame([688000.0, 688000.0, []], [$byAccount['1000']['preEliminationTotal'], $byAccount['1000']['postEliminationTotal'], $byAccount['1000']['eliminationAdjustments']]);

		[, $income, $incomeUuid] = $this->saved(schema: 'ConsolidatedIncomeStatement');
		self::assertNull($incomeUuid);
		self::assertSame([25000.0, 0.0, 25000.0], [$income['totalRevenue'], $income['totalExpenses'], $income['netProfitTotal']]);
		self::assertSame(['7200', '8200'], array_map('strval', array_column($income['lines'], 'rapportageregelnummer')));
		self::assertSame(['ej-1'], $income['lines'][1]['eliminationAdjustments']);
	}//end testAGroupOfTwoAdministrationsIsConsolidated()

	/**
	 * A proportional member counts by its ownership percentage.
	 *
	 * @return void
	 */
	public function testAProportionalMemberCountsByItsShare(): void {
		$this->records['ConsolidationGroup/g-2'] = ['parentAdministrationId' => 'adm-p', 'defaultConsolidationMethod' => 'proportional'];
		$this->store['GroupEntity'] = [['administrationId' => 'adm-j', 'state' => 'active', 'ownershipPercentage' => 40]];
		$this->balances['adm-p@2026-03'] = [self::row(account: '1000', type: 'assets', closing: 1000.0)];
		$this->balances['adm-j@2026-03'] = [self::row(account: '1000', type: 'assets', closing: 500.25)];

		$outcome = $this->service()->run(
			period: ['@self' => ['id' => 'cp-2'], 'consolidationGroupId' => 'g-2', 'periodEnd' => '2026-03-31'],
			asOf: new DateTimeImmutable('2026-04-02T00:00:00+00:00')
		);

		self::assertSame(1200.1, $outcome['totalAssets']);
		self::assertSame('cp-2', $this->saved(schema: 'ConsolidatedBalance')[1]['consolidationPeriodId']);
	}//end testAProportionalMemberCountsByItsShare()

	/**
	 * A final report is not run again, and nothing is written.
	 *
	 * @return void
	 */
	public function testAFinalReportIsNotRunAgain(): void {
		$this->seedGroup();
		$this->store['ConsolidatedIncomeStatement'] = [['id' => 'ci-1', 'consolidationPeriodId' => 'cp-1', 'state' => 'final']];

		try {
			$this->service()->run(period: ['id' => 'cp-1', 'consolidationGroupId' => 'g-1', 'periodEnd' => '2026-10-31'], asOf: new DateTimeImmutable());
			self::fail('A final report was run again.');
		} catch (InvalidArgumentException $e) {
			self::assertSame('The ConsolidatedIncomeStatement of this period is final; a final report is not run again.', $e->getMessage());
		}

		self::assertSame([], $this->saves);
	}//end testAFinalReportIsNotRunAgain()

	/**
	 * A period without an id, a group or a valid end, or with an unknown group, is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnIncompletePeriodIsRefused(): void {
		$service = $this->service();
		foreach ([
			['consolidationGroupId' => 'g-1', 'periodEnd' => '2026-10-31'],
			['id' => 'cp-1', 'periodEnd' => '2026-10-31'],
			['id' => 'cp-1', 'consolidationGroupId' => 'g-1', 'periodEnd' => '2026-02-30'],
			['id' => 'cp-1', 'consolidationGroupId' => 'unknown', 'periodEnd' => '2026-10-31'],
		] as $period) {
			try {
				$service->run(period: $period, asOf: new DateTimeImmutable());
				self::fail('An incomplete period was run.');
			} catch (InvalidArgumentException $e) {
				$expected = 'A consolidation period needs its id, a group and a period end before it can be run.';
				if (($period['consolidationGroupId'] ?? '') === 'unknown') {
					$expected = 'The consolidation group of this period is unknown or names no parent administration.';
				}

				self::assertSame($expected, $e->getMessage());
			}
		}

		self::assertSame([], $this->balancesRead);
		self::assertSame([], $this->saves);
	}//end testAnIncompletePeriodIsRefused()

	/**
	 * Only active integral and proportional members count, the parent once and in full.
	 *
	 * @return void
	 */
	public function testMembersAreTheParentAndTheActiveSummedEntities(): void {
		self::assertSame(
			['p' => 1.0, 'a' => 1.0, 'b' => 0.25, 'c' => 1.0],
			ConsolidationRunService::members(
				parentId: 'p',
				defaultMethod: 'integral',
				entities: [
					['administrationId' => 'p', 'state' => 'active', 'consolidationMethod' => 'proportional', 'ownershipPercentage' => 10],
					['administrationId' => 'a', 'state' => 'active', 'consolidationMethod' => 'integral'],
					['administrationId' => 'b', 'state' => 'active', 'consolidationMethod' => 'proportional', 'ownershipPercentage' => 25],
					['administrationId' => 'c', 'state' => 'active'],
					['administrationId' => 'e', 'state' => 'active', 'consolidationMethod' => 'equity'],
					['administrationId' => 'f', 'state' => 'inactive', 'consolidationMethod' => 'integral'],
					['state' => 'active'],
				]
			)
		);
		self::assertSame('2026-10', ConsolidationRunService::monthOf(date: '2026-10-31'));
		self::assertNull(ConsolidationRunService::monthOf(date: '31-10-2026'));
	}//end testMembersAreTheParentAndTheActiveSummedEntities()
}//end class
