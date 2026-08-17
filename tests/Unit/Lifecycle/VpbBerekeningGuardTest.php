<?php

/**
 * Unit tests for VpbBerekeningGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\VpbBerekeningGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for VpbBerekeningGuard.
 *
 * Covers REQ-VPB-003 (schijftarief berekening) and REQ-VPB-006 (voorvoegingsverlies
 * regime + verjaring), the pure fiscal computations split out of VpbAangifteGuard.
 */
class VpbBerekeningGuardTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var VpbBerekeningGuard
	 */
	private VpbBerekeningGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new VpbBerekeningGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->buildSchemaStub(recordsBySchema: [])),
		);

	}//end setUp()

	/**
	 * Point the guard at the given duck-typed ObjectService store.
	 *
	 * The store is a constructor dependency since ADR-084, so the guard has to
	 * be rebuilt whenever a test seeds different records.
	 *
	 * @param object $store The in-memory ObjectService double.
	 *
	 * @return void
	 */
	private function wireObjectService(object $store): void {
		$this->container->method('get')->willReturn($store);

		$this->guard = new VpbBerekeningGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end wireObjectService()

	/**
	 * The schijftarief calculation applies the 2026 brackets (19% / 25.8% / 245k) (REQ-VPB-003).
	 *
	 * For a belastbaar bedrag of EUR 450.000: 0.19 * 245.000 + 0.258 * 205.000 =
	 * 46.550 + 52.890 = 99.440.
	 *
	 * @return void
	 */
	public function testBerekenVerschuldigdeVpbAppliesBrackets(): void {
		$this->wireObjectService(
			store: $this->buildSchemaStub(
				recordsBySchema: [
					'VpbTariefcatalogus' => [
						['taxYear' => 2026, 'tarief1' => 0.19, 'tarief2' => 0.258, 'taxableAmountThreshold' => 245000],
					],
				]
			)
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertSame(99440.0, $this->guard->berekenVerschuldigdeVpb(taxYear: 2026, taxableProfit: 450000.0));

	}//end testBerekenVerschuldigdeVpbAppliesBrackets()

	/**
	 * Below the bracket grens only tarief1 applies (REQ-VPB-003).
	 *
	 * @return void
	 */
	public function testBerekenVerschuldigdeVpbBelowGrens(): void {
		$this->wireObjectService(
			store: $this->buildSchemaStub(
				recordsBySchema: [
					'VpbTariefcatalogus' => [
						['taxYear' => 2026, 'tarief1' => 0.19, 'tarief2' => 0.258, 'taxableAmountThreshold' => 245000],
					],
				]
			)
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertSame(19000.0, $this->guard->berekenVerschuldigdeVpb(taxYear: 2026, taxableProfit: 100000.0));

	}//end testBerekenVerschuldigdeVpbBelowGrens()

	/**
	 * A non-positive belastbaar bedrag yields zero Vpb (REQ-VPB-003).
	 *
	 * @return void
	 */
	public function testBerekenVerschuldigdeVpbZeroOnNonPositive(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertSame(0.0, $this->guard->berekenVerschuldigdeVpb(taxYear: 2026, taxableProfit: 0.0));

	}//end testBerekenVerschuldigdeVpbZeroOnNonPositive()

	/**
	 * A missing tariefcatalogus record yields zero Vpb (fail-closed, REQ-VPB-003).
	 *
	 * @return void
	 */
	public function testBerekenVerschuldigdeVpbZeroWhenNoTarief(): void {
		$this->wireObjectService(
			store: $this->buildSchemaStub(recordsBySchema: ['VpbTariefcatalogus' => []])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertSame(0.0, $this->guard->berekenVerschuldigdeVpb(taxYear: 2099, taxableProfit: 500000.0));

	}//end testBerekenVerschuldigdeVpbZeroWhenNoTarief()

	/**
	 * The calculation fails closed (returns 0.0) when ObjectService throws (CWE-863).
	 *
	 * @return void
	 */
	public function testBerekenVerschuldigdeVpbFailsClosed(): void {
		$this->wireObjectService(store: $this->buildFailingObjectServiceStub());
		$this->logger->expects($this->once())->method('error');

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertSame(0.0, $this->guard->berekenVerschuldigdeVpb(taxYear: 2026, taxableProfit: 450000.0));

	}//end testBerekenVerschuldigdeVpbFailsClosed()

	/**
	 * Regime determination follows the verliesjaar boundaries (REQ-VPB-006).
	 *
	 * @return void
	 */
	public function testBepaalVerliesRegime(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertSame('9jr', $this->guard->bepaalVerliesRegime(lossYear: 2018));
		self::assertSame('6jr', $this->guard->bepaalVerliesRegime(lossYear: 2019));
		self::assertSame('6jr', $this->guard->bepaalVerliesRegime(lossYear: 2021));
		self::assertSame('onbeperkt-50pct', $this->guard->bepaalVerliesRegime(lossYear: 2022));
		self::assertSame('onbeperkt-50pct', $this->guard->bepaalVerliesRegime(lossYear: 2026));
		self::assertSame('', $this->guard->bepaalVerliesRegime(lossYear: null));
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end testBepaalVerliesRegime()

	/**
	 * Verjaring date follows the regime (9/6 years; null when unbounded) (REQ-VPB-006).
	 *
	 * @return void
	 */
	public function testBepaalVerjaardatum(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		// 9jr regime (<= 2018): verjaart 31-12 of verliesjaar + 9.
		self::assertSame('2027-12-31', $this->guard->bepaalVerjaardatum(lossYear: 2018));
		// 6jr regime (2019-2021): verjaart 31-12 of verliesjaar + 6.
		self::assertSame('2025-12-31', $this->guard->bepaalVerjaardatum(lossYear: 2019));
		self::assertSame('2027-12-31', $this->guard->bepaalVerjaardatum(lossYear: 2021));
		// Onbeperkt-50pct regime (>= 2022): no verjaring (null).
		self::assertNull($this->guard->bepaalVerjaardatum(lossYear: 2023));
		self::assertNull($this->guard->bepaalVerjaardatum(lossYear: 2024));
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end testBepaalVerjaardatum()

	/**
	 * Build a schema-aware ObjectService stub.
	 *
	 * The fluent setSchema() selects which record set findAll() returns, so a
	 * single stub can serve cross-schema lookups within one guard call.
	 *
	 * @param array<string,array<mixed>> $recordsBySchema Map of schema slug => records.
	 *
	 * @return object
	 */
	private function buildSchemaStub(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * Map of schema slug => records.
			 *
			 * @var array<string,array<mixed>>
			 */
			private array $recordsBySchema;

			/**
			 * The currently selected schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<mixed>> $recordsBySchema Map of schema => records.
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (unused).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — selects the active record set.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return the records for the active schema.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return ($this->recordsBySchema[$this->schema] ?? []);
			}//end findAll()
		};
	}//end buildSchemaStub()

	/**
	 * Build an ObjectService store that refuses every read.
	 *
	 * Since the store is injected rather than pulled from the container, an
	 * unavailable OpenRegister is modelled by a store that throws.
	 *
	 * @return object
	 */
	private function buildFailingObjectServiceStub(): object {
		return new class {

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (unused).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug (unused).
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse the read, as an unavailable ObjectService would.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('down');
			}//end findAll()
		};
	}//end buildFailingObjectServiceStub()
}//end class
