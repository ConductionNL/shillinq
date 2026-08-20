<?php

/**
 * Unit tests for FixedAssetDisposalListener.
 *
 * These are the correctness proof for tasks.md Task 14. On the pre-change
 * codebase `DisposalJournalEmitter::emit()` had zero callers — this test
 * could not even be written (`FixedAssetDisposalListener` and
 * `FixedAssetDisposalService` did not exist, and the `FixedAsset` `dispose`
 * transition could never fire). Post-change it demonstrates the full chain
 * with REAL `DisposalJournalEmitter`, `DepreciationCalculator` and
 * `FixedAssetDisposalService` instances (only `ObjectService` is faked,
 * in-memory): a `FixedAsset` reaching `retired` posts a GLTransaction whose
 * debits equal its credits AND whose per-account amounts are right.
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
use OCA\Shillinq\Listener\FixedAssetDisposalListener;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\DepreciationCalculator;
use OCA\Shillinq\Service\DisposalJournalEmitter;
use OCA\Shillinq\Service\FixedAssetDisposalService;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Service/InMemoryObjectService.php';

/**
 * Tests the FixedAsset `retired` → balanced disposal journal wiring.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class FixedAssetDisposalListenerTest extends TestCase {

	/**
	 * The in-memory ObjectService backing the whole chain.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $objects;

	/**
	 * The listener under test, wired to the REAL service + emitter.
	 *
	 * @var FixedAssetDisposalListener
	 */
	private FixedAssetDisposalListener $listener;

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
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				$map = [
					'register' => 'shillinq',
					FixedAssetDisposalService::CFG_GAIN_ACCOUNT => '8000',
					FixedAssetDisposalService::CFG_LOSS_ACCOUNT => '8001',
					FixedAssetDisposalService::CFG_CLEARING_ACCOUNT => '1100',
				];
				return ($map[$key] ?? $default);
			}
		);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturn(true);

		$service = new FixedAssetDisposalService(
			appConfig: $appConfig,
			emitter: new DisposalJournalEmitter(new DepreciationCalculator()),
			administrationContext: $administrationContext,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($this->objects),
		);

		$this->listener = new FixedAssetDisposalListener(
			disposalService: $service,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Seed the demo-shaped asset + its posted depreciation schedule.
	 *
	 * Mirrors lib/Settings/seeds/fixed-assets-demo.json: purchaseCost 25000,
	 * accumulatedDepreciation 4600 posted through 2026-12-31.
	 *
	 * @param float $proceeds The salvage proceeds on disposal.
	 *
	 * @return array<string,mixed> The asset payload.
	 */
	private function seedAsset(float $proceeds): array {
		$asset = [
			'id' => 'asset-1',
			'assetNumber' => 'FA-DEMO-V-2026-0001',
			'administrationId' => 'adm-1',
			'purchaseCost' => 25000,
			'purchaseDate' => '2026-01-15',
			'capitalizationAccountNumber' => '0220',
			'accumulatedDepreciationAccountNumber' => '0225',
			'costCenterCode' => 'HQ',
			'currency' => 'EUR',
			'status' => 'retired',
			'retirementDate' => '2027-06-30',
			'salvageProceeds' => $proceeds,
			'disposalAccountingTreatment' => 'sale',
		];

		$this->objects->seed('FixedAsset', [$asset]);
		$this->objects->seed(
			'DepreciationSchedule',
			[
				[
					'id' => 'sch-1',
					'assetRef' => 'asset-1',
					'administrationId' => 'adm-1',
					'periodEndDate' => '2026-12-31',
					'accumulatedDepreciation' => 4600,
					'status' => 'completed',
				],
			]
		);

		return $asset;
	}//end seedAsset()

	/**
	 * Fire the `retired` transition for the given asset payload.
	 *
	 * @param array<string,mixed> $asset The asset payload.
	 *
	 * @return void
	 */
	private function retire(array $asset): void {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn('FixedAsset');
		$entity->method('getObject')->willReturn($asset);

		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'retired', 'getSchema' => 'FixedAsset']
		);

		$this->listener->handle($event);

	}//end retire()

	/**
	 * Index the persisted GL lines by account number.
	 *
	 * @return array<string,array{side:string,amount:float}>
	 */
	private function postedLines(): array {
		$byAccount = [];
		foreach ($this->objects->dump('GLLine') as $line) {
			$byAccount[(string)$line['accountNumber']] = [
				'side' => (string)$line['side'],
				'amount' => (float)$line['amount'],
			];
		}

		return $byAccount;
	}//end postedLines()

	/**
	 * Assert the persisted journal balances to the cent.
	 *
	 * @return integer The total debit in cents.
	 */
	private function assertJournalBalances(): int {
		$debitCents = 0;
		$creditCents = 0;
		foreach ($this->objects->dump('GLLine') as $line) {
			$cents = (int)round(((float)$line['amount']) * 100.0);
			if ((string)$line['side'] === 'debit') {
				$debitCents += $cents;
				continue;
			}

			$creditCents += $cents;
		}

		self::assertGreaterThan(0, $debitCents, 'The disposal journal must post at least one line.');
		self::assertSame(
			$debitCents,
			$creditCents,
			'The disposal journal MUST balance: sum(debit) === sum(credit).'
		);

		return $debitCents;
	}//end assertJournalBalances()

	/**
	 * A retired FixedAsset posts a balanced disposal journal with the right
	 * per-account amounts (REQ-GLTAX-001).
	 *
	 * Book value = purchaseCost 25000 − posted accumulated depreciation 4600
	 * = 20400. Proceeds 18000 → loss 2400.
	 *
	 * @return void
	 */
	public function testRetiringAnAssetPostsABalancedDisposalJournal(): void {
		$asset = $this->seedAsset(proceeds: 18000.0);

		$this->retire($asset);

		$transactions = $this->objects->dump('GLTransaction');
		self::assertCount(1, $transactions, 'Exactly one disposal GLTransaction must be posted.');
		self::assertSame('adm-1', $transactions[0]['administrationId']);

		$debitCents = $this->assertJournalBalances();
		self::assertSame(2500000, $debitCents, 'Debits must total the gross acquisition cost.');

		$lines = $this->postedLines();
		self::assertSame(['side' => 'credit', 'amount' => 25000.0], $lines['0220'], 'Asset account credited for gross cost.');
		self::assertSame(['side' => 'debit', 'amount' => 4600.0], $lines['0225'], 'Accumulated depreciation debited for what was POSTED.');
		self::assertSame(['side' => 'debit', 'amount' => 18000.0], $lines['1100'], 'Clearing account debited for the proceeds.');
		self::assertSame(['side' => 'debit', 'amount' => 2400.0], $lines['8001'], 'Loss = book value 20400 − proceeds 18000.');
		self::assertArrayNotHasKey('8000', $lines, 'No gain line on a loss-making disposal.');

	}//end testRetiringAnAssetPostsABalancedDisposalJournal()

	/**
	 * Scrapping with zero proceeds books the full residual book value as a
	 * loss, and the journal still balances (REQ-GLTAX-001).
	 *
	 * @return void
	 */
	public function testScrappingWithZeroProceedsBooksTheFullResidualAsLoss(): void {
		$asset = $this->seedAsset(proceeds: 0.0);
		$asset['disposalAccountingTreatment'] = 'scrap';

		$this->retire($asset);

		$debitCents = $this->assertJournalBalances();
		self::assertSame(2500000, $debitCents);

		$lines = $this->postedLines();
		self::assertSame(['side' => 'credit', 'amount' => 25000.0], $lines['0220']);
		self::assertSame(['side' => 'debit', 'amount' => 4600.0], $lines['0225']);
		self::assertSame(['side' => 'debit', 'amount' => 20400.0], $lines['8001'], 'Full residual book value books to loss.');
		self::assertArrayNotHasKey('1100', $lines, 'No proceeds line when nothing was received.');

	}//end testScrappingWithZeroProceedsBooksTheFullResidualAsLoss()

	/**
	 * Proceeds above the book value post a gain, not a loss (REQ-GLTAX-001).
	 *
	 * @return void
	 */
	public function testSellingAboveBookValuePostsAGain(): void {
		$asset = $this->seedAsset(proceeds: 22000.0);

		$this->retire($asset);

		$this->assertJournalBalances();

		$lines = $this->postedLines();
		self::assertSame(['side' => 'credit', 'amount' => 1600.0], $lines['8000'], 'Gain = proceeds 22000 − book value 20400.');
		self::assertArrayNotHasKey('8001', $lines, 'No loss line on a profitable disposal.');

	}//end testSellingAboveBookValuePostsAGain()

	/**
	 * A transition to any other state posts nothing (REQ-GLTAX-001).
	 *
	 * @return void
	 */
	public function testNonRetiredTransitionPostsNothing(): void {
		$asset = $this->seedAsset(proceeds: 18000.0);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn('FixedAsset');
		$entity->method('getObject')->willReturn($asset);

		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'active', 'getSchema' => 'FixedAsset']
		);

		$this->listener->handle($event);

		self::assertSame([], $this->objects->dump('GLTransaction'));

	}//end testNonRetiredTransitionPostsNothing()

	/**
	 * A retired asset on another schema posts nothing (REQ-GLTAX-001).
	 *
	 * @return void
	 */
	public function testOtherSchemaPostsNothing(): void {
		$asset = $this->seedAsset(proceeds: 18000.0);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn('PurchaseOrder');
		$entity->method('getObject')->willReturn($asset);

		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'retired', 'getSchema' => 'PurchaseOrder']
		);

		$this->listener->handle($event);

		self::assertSame([], $this->objects->dump('GLTransaction'));

	}//end testOtherSchemaPostsNothing()
}//end class
