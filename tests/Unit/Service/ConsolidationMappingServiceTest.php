<?php

/**
 * Unit tests for ConsolidationMappingService.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\ConsolidationMappingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the pure consolidation-mapping hooks (REQ-MA-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ConsolidationMappingServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var ConsolidationMappingService
	 */
	private ConsolidationMappingService $service;

	/**
	 * Set up the service with mocked deps.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new ConsolidationMappingService(
			container: $container,
			appConfig: $appConfig,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * applyAccountRule rewrites a matched source account.
	 *
	 * @return void
	 */
	public function testApplyAccountRuleMatch(): void {
		$mapping = [
			'rules' => [
				['sourceAccount' => '4001', 'destinationAccount' => '7001'],
				['sourceAccount' => '4002', 'destinationAccount' => '7002'],
			],
		];

		self::assertSame('7001', $this->service->applyAccountRule(mapping: $mapping, sourceAccount: '4001'));

	}//end testApplyAccountRuleMatch()

	/**
	 * applyAccountRule passes through when no rule matches (REQ-MA-005 — visible gap).
	 *
	 * @return void
	 */
	public function testApplyAccountRulePassThrough(): void {
		$mapping = [
			'rules' => [
				['sourceAccount' => '4001', 'destinationAccount' => '7001'],
			],
		];

		self::assertSame('9999', $this->service->applyAccountRule(mapping: $mapping, sourceAccount: '9999'));

	}//end testApplyAccountRulePassThrough()

	/**
	 * applyMapping returns the per-line rewrite plus the list of unmapped accounts.
	 *
	 * @return void
	 */
	public function testApplyMappingReportsUnmapped(): void {
		$mapping = [
			'rules' => [
				['sourceAccount' => '4001', 'destinationAccount' => '7001'],
				['sourceAccount' => '4002', 'destinationAccount' => '7002'],
			],
		];

		$result = $this->service->applyMapping(
			mapping: $mapping,
			sourceAccounts: ['4001', '4002', '4003']
		);

		self::assertCount(3, $result['mapped']);
		self::assertSame('7001', $result['mapped'][0]['destination']);
		self::assertSame('7002', $result['mapped'][1]['destination']);
		// 4003 has no rule -> pass through.
		self::assertSame('4003', $result['mapped'][2]['destination']);
		self::assertSame(['4003'], $result['unmapped']);

	}//end testApplyMappingReportsUnmapped()

	/**
	 * shouldEliminate honours both the flag and the balanced status.
	 *
	 * @return void
	 */
	public function testShouldEliminateHonoursStatus(): void {
		$confirmed = [
			'eliminateOnConsolidation' => true,
			'status' => 'bevestigd_beide',
		];
		$concept = [
			'eliminateOnConsolidation' => true,
			'status' => 'draft',
		];
		$notFlagged = [
			'eliminateOnConsolidation' => false,
			'status' => 'bevestigd_beide',
		];

		self::assertTrue($this->service->shouldEliminate(intercompanyEntry: $confirmed));
		self::assertFalse($this->service->shouldEliminate(intercompanyEntry: $concept));
		self::assertFalse($this->service->shouldEliminate(intercompanyEntry: $notFlagged));

	}//end testShouldEliminateHonoursStatus()

	/**
	 * Explicit eliminationAccount on the IC entry wins over the mapping default.
	 *
	 * @return void
	 */
	public function testResolveEliminationAccountPrefersExplicit(): void {
		$entry = ['eliminationAccount' => '9001'];
		$mapping = ['intercompanyEliminationAccount' => '9999'];

		self::assertSame(
			'9001',
			$this->service->resolveEliminationAccount(intercompanyEntry: $entry, mapping: $mapping)
		);

	}//end testResolveEliminationAccountPrefersExplicit()

	/**
	 * Falls back to the mapping default when the IC entry has no explicit account.
	 *
	 * @return void
	 */
	public function testResolveEliminationAccountFallback(): void {
		$entry = [];
		$mapping = ['intercompanyEliminationAccount' => '9999'];

		self::assertSame(
			'9999',
			$this->service->resolveEliminationAccount(intercompanyEntry: $entry, mapping: $mapping)
		);

	}//end testResolveEliminationAccountFallback()

	/**
	 * Returns null when no elimination account is configured anywhere.
	 *
	 * @return void
	 */
	public function testResolveEliminationAccountReturnsNullWhenUnconfigured(): void {
		self::assertNull(
			$this->service->resolveEliminationAccount(intercompanyEntry: [], mapping: null)
		);
		self::assertNull(
			$this->service->resolveEliminationAccount(intercompanyEntry: [], mapping: [])
		);

	}//end testResolveEliminationAccountReturnsNullWhenUnconfigured()

	/**
	 * pickMostRecent returns the candidate with the latest validFrom on or before asOf.
	 *
	 * @return void
	 */
	public function testPickMostRecentByValidFrom(): void {
		$candidates = [
			['validFrom' => '2024-01-01', 'name' => 'old'],
			['validFrom' => '2026-01-01', 'name' => 'current'],
			['validFrom' => '2027-01-01', 'name' => 'future'],
		];

		$best = $this->service->pickMostRecent(
			candidates: $candidates,
			asOf: new DateTimeImmutable('2026-06-08')
		);

		self::assertNotNull($best);
		self::assertSame('current', $best['name']);

	}//end testPickMostRecentByValidFrom()

	/**
	 * pickMostRecent ignores future validFrom candidates.
	 *
	 * @return void
	 */
	public function testPickMostRecentSkipsFuture(): void {
		$candidates = [
			['validFrom' => '2030-01-01', 'name' => 'future'],
		];

		$best = $this->service->pickMostRecent(
			candidates: $candidates,
			asOf: new DateTimeImmutable('2026-06-08')
		);

		self::assertNull($best);

	}//end testPickMostRecentSkipsFuture()

	/**
	 * pickMostRecent returns the first candidate without validFrom as a fallback.
	 *
	 * @return void
	 */
	public function testPickMostRecentFallsBackToAlwaysValid(): void {
		$candidates = [
			['name' => 'evergreen'],
		];

		$best = $this->service->pickMostRecent(
			candidates: $candidates,
			asOf: new DateTimeImmutable('2026-06-08')
		);

		self::assertNotNull($best);
		self::assertSame('evergreen', $best['name']);

	}//end testPickMostRecentFallsBackToAlwaysValid()

	/**
	 * findActiveMapping returns null on empty input — guards against bad callers.
	 *
	 * @return void
	 */
	public function testFindActiveMappingNullOnEmptyInput(): void {
		self::assertNull(
			$this->service->findActiveMapping(
				sourceAdministrationId: '',
				destinationAdministrationId: 'adm-1'
			)
		);
		self::assertNull(
			$this->service->findActiveMapping(
				sourceAdministrationId: 'adm-1',
				destinationAdministrationId: ''
			)
		);

	}//end testFindActiveMappingNullOnEmptyInput()
}//end class
