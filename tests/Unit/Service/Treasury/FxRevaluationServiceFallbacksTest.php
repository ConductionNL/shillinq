<?php

/**
 * Unit tests for the fallbacks of FxRevaluationService: a rate lookup that
 * throws falls back to the manual rate, an unreadable or blank administration
 * defaults to EUR, unreadable positions revalue nothing, positions without an
 * id get a stable identity, rendered entities are read, and blank GL account
 * settings keep their defaults.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Treasury;

use OCA\Shillinq\Service\Treasury\FxPositionMark;
use OCA\Shillinq\Service\Treasury\FxRevaluationRun;
use OCA\Shillinq\Service\Treasury\FxRevaluationService;
use OCA\Shillinq\Service\Treasury\TreasuryRateService;
use OCA\Shillinq\Service\Treasury\TreasuryRateSnapshot;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

// phpcs:disable CustomSniffs.Functions.NamedParameters

#[CoversClass(FxRevaluationService::class)]
#[UsesClass(FxRevaluationRun::class)]
#[UsesClass(FxPositionMark::class)]
#[UsesClass(TreasuryRateSnapshot::class)]
#[UsesClass(ObjectIdentifier::class)]
final class FxRevaluationServiceFallbacksTest extends TestCase {
	/**
	 * What the store saved: schema and object, in order.
	 *
	 * @var array<int,array{schema:string,object:array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * The rate lookup.
	 *
	 * @var TreasuryRateService&MockObject
	 */
	private TreasuryRateService&MockObject $rates;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the rate lookup and the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved  = [];
		$this->rates  = $this->createMock(TreasuryRateService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The service over a store.
	 *
	 * @param InMemoryObjectServiceStub $store    The store.
	 * @param array<string,string>      $settings App config values by key; others answer their default.
	 *
	 * @return FxRevaluationService
	 */
	private function service(InMemoryObjectServiceStub $store, array $settings = []): FxRevaluationService {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($settings[$key] ?? $default)
		);

		return new FxRevaluationService($config, $this->rates, $this->logger, $store);

	}//end service()

	/**
	 * An open USD position marked before at 0.90.
	 *
	 * @param array<string,mixed> $fields Fields to override or add.
	 *
	 * @return array<string,mixed>
	 */
	private static function position(array $fields = []): array {
		return array_merge(
			[
				'id'               => 'fxpos-1',
				'administrationId' => 'adm-1',
				'foreignCurrency'  => 'USD',
				'position'         => 100000.0,
				'spotRate'         => 0.90,
				'fairValue'        => 90000.0,
				'unrealisedPL'     => 0.0,
			],
			$fields
		);

	}//end position()

	/**
	 * The saved objects of a schema, in order.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function savedOf(string $schema): array {
		return array_values(
			array_map(
				static fn (array $entry): array => $entry['object'],
				array_filter($this->saved, static fn (array $entry): bool => $entry['schema'] === $schema)
			)
		);

	}//end savedOf()

	/**
	 * A rate lookup that throws is logged and the position's own rate is used:
	 * the unchanged manual rate keeps the mark and posts nothing.
	 *
	 * @return void
	 */
	public function testAThrowingRateLookupFallsBackToTheManualRate(): void {
		$store = new InMemoryObjectServiceStub(
			['Administration' => [['id' => 'adm-1', 'functionalCurrency' => 'EUR']], 'FXPosition' => [self::position()]],
			$this->saved
		);
		$this->rates->method('getFxSpot')->willThrowException(new RuntimeException('feed down'));
		$this->logger->expects(self::once())->method('warning')->with(
			'FxRevaluationService: TreasuryRateService::getFxSpot threw — falling back to manual rate',
			['foreignCurrency' => 'USD', 'exception' => 'feed down']
		);

		$result = $this->service($store)->reval('adm-1', '2026-03');

		self::assertSame(['postingCount' => 0, 'positionsEvaluated' => 1, 'functionalCurrency' => 'EUR', 'periodId' => '2026-03'], $result);
		$marked = $this->savedOf('FXPosition');
		self::assertCount(1, $marked);
		self::assertSame(0.90, $marked[0]['spotRate']);
		self::assertSame([], $this->savedOf('FxRevaluationPosting'));

	}//end testAThrowingRateLookupFallsBackToTheManualRate()

	/**
	 * An administration whose functional currency is blank revalues in EUR,
	 * so an EUR position is not revalued and a USD one is.
	 *
	 * @return void
	 */
	public function testABlankFunctionalCurrencyDefaultsToEur(): void {
		$store = new InMemoryObjectServiceStub(
			[
				'Administration' => [['id' => 'adm-1', 'functionalCurrency' => '']],
				'FXPosition'     => [self::position(['foreignCurrency' => 'EUR'])],
			],
			$this->saved
		);
		$this->rates->expects(self::never())->method('getFxSpot');

		$result = $this->service($store)->reval('adm-1', '2026-03');

		self::assertSame('EUR', $result['functionalCurrency']);
		self::assertSame(1, $result['positionsEvaluated']);
		self::assertSame(0, $result['postingCount']);
		self::assertSame([], $this->saved);

	}//end testABlankFunctionalCurrencyDefaultsToEur()

	/**
	 * An administration lookup that throws is logged and defaults to EUR.
	 *
	 * @return void
	 */
	public function testAnUnreadableAdministrationDefaultsToEur(): void {
		$store = new class([], $this->saved) extends InMemoryObjectServiceStub {
			/**
			 * Selecting the administration schema throws.
			 *
			 * @param string|int $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string|int $schema): static {
				if ($schema === 'Administration') {
					throw new RuntimeException('store down');
				}

				return parent::setSchema($schema);

			}//end setSchema()
		};
		$this->logger->expects(self::once())->method('warning')->with(
			'FxRevaluationService: failed to resolve Administration.functionalCurrency — defaulting to EUR',
			['administrationId' => 'adm-1', 'exception' => 'store down']
		);

		$result = $this->service($store)->reval('adm-1', '2026-03');

		self::assertSame(['postingCount' => 0, 'positionsEvaluated' => 0, 'functionalCurrency' => 'EUR', 'periodId' => '2026-03'], $result);

	}//end testAnUnreadableAdministrationDefaultsToEur()

	/**
	 * Positions that cannot be loaded are logged as an error and nothing is
	 * revalued; the administration's own currency still answers.
	 *
	 * @return void
	 */
	public function testUnreadablePositionsRevalueNothing(): void {
		$store = new class(['Administration' => [['id' => 'adm-1', 'functionalCurrency' => 'GBP']]], $this->saved) extends InMemoryObjectServiceStub {
			/**
			 * The position list throws; other schemas answer.
			 *
			 * @param array $config        The query.
			 * @param bool  $_rbac         Unused.
			 * @param bool  $_multitenancy Unused.
			 *
			 * @return array
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if (($config['filters']['administrationId'] ?? null) !== null) {
					throw new RuntimeException('index down');
				}

				return parent::findAll($config, $_rbac, $_multitenancy);

			}//end findAll()
		};
		$this->logger->expects(self::once())->method('error')->with(
			'FxRevaluationService: failed to load FXPosition records',
			['administrationId' => 'adm-1', 'exception' => 'index down']
		);

		$result = $this->service($store)->reval('adm-1', '2026-03');

		self::assertSame(['postingCount' => 0, 'positionsEvaluated' => 0, 'functionalCurrency' => 'GBP', 'periodId' => '2026-03'], $result);
		self::assertSame([], $this->saved);

	}//end testUnreadablePositionsRevalueNothing()

	/**
	 * A posting names a position without an id by its slug, else by its
	 * currency and administration; rendered entities are read like arrays,
	 * and blank account settings keep the documented accounts.
	 *
	 * @return void
	 */
	public function testPostingsIdentifyPositionsWithoutAnId(): void {
		$withSlug = self::position(['id' => '', 'slug' => 'usd-book']);
		$bare     = self::position(['id' => '', 'foreignCurrency' => 'CHF', 'administrationId' => 'adm-1']);
		$store    = new InMemoryObjectServiceStub(
			['Administration' => [['id' => 'adm-1', 'functionalCurrency' => 'EUR']], 'FXPosition' => [$withSlug, $bare]],
			$this->saved,
			findAllRendersEntities: true
		);
		$this->rates->method('getFxSpot')->willReturn(
			new TreasuryRateSnapshot(value: '0.95', source: 'ECB', asOf: '2026-03-31', rateCode: 'X/EUR', dormant: false, rateId: 'tr_1')
		);

		$result = $this->service(
			$store,
			['fx_revaluation_gain_account' => '', 'fx_revaluation_adjustment_account' => '', 'register' => '']
		)->reval('adm-1', '2026-03');

		self::assertSame(2, $result['postingCount']);
		$postings = $this->savedOf('FxRevaluationPosting');
		self::assertSame(['usd-book', 'fxpos-chf-adm-1'], array_column($postings, 'positionId'));
		self::assertSame(['gain', 'gain'], array_column($postings, 'direction'));
		self::assertSame(['live', 'live'], array_column($postings, 'rateSource'));
		self::assertSame([500000, 500000], array_column($postings, 'unrealisedDeltaCents'));
		self::assertStringStartsWith('je-2026-03-fx-chf-', $postings[1]['journalEntryId']);

		self::assertSame(['8020', '8020'], array_column($postings, 'targetGLAccount'));
		self::assertSame(['1699', '1699'], array_column($postings, 'contraGLAccount'));

	}//end testPostingsIdentifyPositionsWithoutAnId()
}//end class
