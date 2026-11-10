<?php

/**
 * Characterisation tests for FixedAssetDisposalService::postDisposalJournal.
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
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\DepreciationCalculator;
use OCA\Shillinq\Service\DisposalJournalEmitter;
use OCA\Shillinq\Service\FixedAssetDisposalService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the tenant refusals, the skip paths, the posted book value read from
 * the depreciation schedule and the persisted journal.
 */
final class FixedAssetDisposalPostingTest extends TestCase {

	/**
	 * The in-memory store of the last service built.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The service over the given schedule rows; only adm-1 is accessible.
	 *
	 * @param array<int, array<string, mixed>> $schedule DepreciationSchedule rows.
	 *
	 * @return FixedAssetDisposalService
	 */
	private function service(array $schedule): FixedAssetDisposalService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'shillinq',
				FixedAssetDisposalService::CFG_GAIN_ACCOUNT => ' 8300 ',
				default => $default,
			}
		);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');

		$this->store = new InMemoryObjectServiceStub(['DepreciationSchedule' => $schedule]);

		return new FixedAssetDisposalService(
			$appConfig,
			new DisposalJournalEmitter(new DepreciationCalculator()),
			$context,
			$this->createMock(LoggerInterface::class),
			$this->store
		);
	}//end service()

	/**
	 * A retired asset disposed on 2026-06-30.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function asset(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'fa-1',
				'assetNumber' => 'FA-001',
				'purchaseCost' => '1000.00',
				'capitalizationAccountNumber' => '0200',
				'accumulatedDepreciationAccountNumber' => '0210',
				'retirementDate' => '2026-06-30T12:00:00Z',
				'salvageProceeds' => 300,
				'costCenterCode' => 'CC-9',
			],
			$overrides
		);
	}//end asset()

	/**
	 * The tenant check refuses before anything is read or written.
	 *
	 * @return void
	 */
	public function testTenantRefusals(): void {
		$messages = [];
		foreach (['', 'adm-2'] as $administrationId) {
			$service = $this->service([]);
			try {
				$service->postDisposalJournal($administrationId, $this->asset());
				$messages[] = 'accepted';
			} catch (RuntimeException $e) {
				$messages[] = $e->getMessage();
			}

			self::assertSame([], $this->store->saved);
		}

		self::assertSame(['administrationId is required', 'Administration not found'], $messages);
	}//end testTenantRefusals()

	/**
	 * An asset without a cost is skipped.
	 *
	 * @return void
	 */
	public function testNoCostIsSkipped(): void {
		$result = $this->service([])->postDisposalJournal('adm-1', $this->asset(['purchaseCost' => 'n/a']));

		self::assertSame(['posted' => false, 'message' => 'FixedAsset carries no acquisition cost; disposal posting skipped'], $result);
		self::assertSame([], $this->store->saved);
	}//end testNoCostIsSkipped()

	/**
	 * The latest posted schedule row on or before the disposal date sets the
	 * book value; planned and later rows are ignored, and the asset number is
	 * the fallback lookup; the journal is persisted header first.
	 *
	 * @return void
	 */
	public function testPostedScheduleSetsTheBookValue(): void {
		$schedule = [
			['assetNumber' => 'FA-001', 'administrationId' => 'adm-1', 'status' => 'posted', 'periodEndDate' => '2026-03-31', 'accumulatedDepreciation' => 400],
			['assetNumber' => 'FA-001', 'administrationId' => 'adm-1', 'status' => 'posted', 'periodEndDate' => '2025-12-31', 'accumulatedDepreciation' => 300],
			['assetNumber' => 'FA-001', 'administrationId' => 'adm-1', 'status' => 'planned', 'periodEndDate' => '2026-06-30', 'accumulatedDepreciation' => 900],
			['assetNumber' => 'FA-001', 'administrationId' => 'adm-1', 'status' => 'posted', 'periodEndDate' => '2026-09-30', 'accumulatedDepreciation' => 800],
			['assetNumber' => 'FA-001', 'administrationId' => 'adm-2', 'status' => 'posted', 'periodEndDate' => '2026-05-31', 'accumulatedDepreciation' => 999],
		];

		$result = $this->service($schedule)->postDisposalJournal('adm-1', $this->asset());

		self::assertTrue($result['posted']);
		self::assertSame(600.0, $result['bookValue']);
		self::assertSame(0.0, $result['gain']);
		self::assertSame(300.0, $result['loss']);
		self::assertSame('Fixed-asset disposal journal materialised', $result['message']);
		self::assertSame(100000, $result['amountCents']);

		$saved = $this->store->saved;
		self::assertSame('GLTransaction', $saved[0]['schema']);
		self::assertSame(
			[
				'transactionNumber' => 'FA-DISP-FA-001',
				'postingDate' => '2026-06-30',
				'periodId' => '2026-06',
				'currency' => 'EUR',
			],
			array_slice($saved[0]['object'], 0, 4)
		);
		self::assertSame('draft', $saved[0]['object']['state']);
		self::assertSame('adm-1', $saved[0]['object']['administrationId']);

		$lines = array_slice($saved, 1);
		self::assertSame(array_fill(0, count($lines), 'GLLine'), array_column($lines, 'schema'));
		$first = $lines[0]['object'];
		self::assertSame(
			['transactionId', 'lineNumber', 'accountNumber', 'side', 'amount', 'currency', 'description', 'subLedgerType', 'subLedgerRef', 'costCenterCode', 'periodId', 'administrationId', 'id'],
			array_keys($first)
		);
		self::assertSame([$saved[0]['object']['id'], 1, 'fixed-asset', 'FA-001', 'CC-9', '2026-06'], [$first['transactionId'], $first['lineNumber'], $first['subLedgerType'], $first['subLedgerRef'], $first['costCenterCode'], $first['periodId']]);
		self::assertSame(range(1, count($lines)), array_map(static fn (array $l): int => $l['object']['lineNumber'], $lines));
		self::assertSame(
			$this->linesOf($result),
			array_map(static fn (array $l): array => [$l['object']['accountNumber'], $l['object']['side'], $l['object']['amount']], $lines)
		);
		self::assertSame([], array_diff(['0200', '0210'], array_column($this->linesOf($result), 0)));
		self::assertSame('EUR', $first['currency']);
	}//end testPostedScheduleSetsTheBookValue()

	/**
	 * A gain uses the configured, trimmed gain account; a schedule found by
	 * asset id wins over the asset-number rows.
	 *
	 * @return void
	 */
	public function testGainAndTheIdLookup(): void {
		$schedule = [
			['assetRef' => 'fa-1', 'administrationId' => 'adm-1', 'status' => 'posted', 'periodEndDate' => '', 'accumulatedDepreciation' => 900],
			['assetNumber' => 'FA-001', 'administrationId' => 'adm-1', 'status' => 'posted', 'periodEndDate' => '2026-01-31', 'accumulatedDepreciation' => 100],
		];

		$result = $this->service($schedule)->postDisposalJournal('adm-1', $this->asset());

		self::assertSame(100.0, $result['bookValue']);
		self::assertSame(200.0, $result['gain']);
		self::assertContains(['8300', 'credit', 200.0], $this->linesOf($result));
	}//end testGainAndTheIdLookup()

	/**
	 * The account, side and amount of every persisted line of a result.
	 *
	 * @param array<string, mixed> $result The result envelope.
	 *
	 * @return array<int, array{0:string,1:string,2:float}>
	 */
	private function linesOf(array $result): array {
		return array_map(
			static fn (array $line): array => [(string)$line['accountNumber'], (string)$line['side'], (float)$line['amount']],
			$result['lines']
		);
	}//end linesOf()
}//end class
