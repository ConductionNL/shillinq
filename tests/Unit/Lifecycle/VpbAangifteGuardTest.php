<?php

/**
 * Unit tests for VpbAangifteGuard.
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

use OCA\Shillinq\Lifecycle\VpbAangifteGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for VpbAangifteGuard.
 *
 * Covers REQ-VPB-002/004/009 (indiening preconditions), REQ-VPB-010 (aanslag
 * ontvangst) and REQ-VPB-007 (fiscale eenheid voeging). The schijftarief
 * (REQ-VPB-003) and voorvoegingsverlies regime (REQ-VPB-006) computations are
 * covered by VpbBerekeningGuardTest.
 */
class VpbAangifteGuardTest extends TestCase {

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
	 * @var VpbAangifteGuard
	 */
	private VpbAangifteGuard $guard;

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

		$this->guard = new VpbAangifteGuard(
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

		$this->guard = new VpbAangifteGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end wireObjectService()

	/**
	 * Indiening is allowed when the jaarrekening is vastgesteld, the
	 * belastingplichtige is Digipoort-ready and the innovatiebox claim carries an
	 * S&O-verklaring (REQ-VPB-002/004/009).
	 *
	 * @return void
	 */
	public function testCanIndienenWhenAllPreconditionsMet(): void {
		$this->wireObjectService(
			store: $this->buildSchemaStub(
				recordsBySchema: [
					'AnnualReport' => [['id' => 'jr-1', 'status' => 'determined']],
					'Belastingplichtige' => [['id' => 'bp-1', 'eRecognitionLevel' => 'EH3', 'digipoortCertificate' => 'vault://cert']],
					'Innovatiebox' => [['taxReturn' => 'aangifte-1', 'soDeclarationReference' => 'SO-2026-1']],
				]
			)
		);

		$taxReturn = [
			'id' => 'aangifte-1',
			'taxpayer' => 'bp-1',
			'commercialProfit' => 'jr-1',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canIndienen(taxReturnId: 'aangifte-1', object: $taxReturn));

	}//end testCanIndienenWhenAllPreconditionsMet()

	/**
	 * Indiening is denied when the jaarrekening is not yet vastgesteld (REQ-VPB-002).
	 *
	 * @return void
	 */
	public function testCannotIndienenWhenJaarrekeningNotVastgesteld(): void {
		$this->wireObjectService(
			store: $this->buildSchemaStub(
				recordsBySchema: ['AnnualReport' => [['id' => 'jr-2', 'status' => 'draft']]]
			)
		);

		$taxReturn = [
			'id' => 'aangifte-2',
			'taxpayer' => 'bp-2',
			'commercialProfit' => 'jr-2',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canIndienen(taxReturnId: 'aangifte-2', object: $taxReturn));

	}//end testCannotIndienenWhenJaarrekeningNotVastgesteld()

	/**
	 * Indiening is denied when the belastingplichtige only has eHerkenning EH2
	 * (REQ-VPB-009 — EH3 is the Logius minimum).
	 *
	 * @return void
	 */
	public function testCannotIndienenWhenEHerkenningBelowEH3(): void {
		$this->wireObjectService(
			store: $this->buildSchemaStub(
				recordsBySchema: [
					'AnnualReport' => [['id' => 'jr-3', 'status' => 'determined']],
					'Belastingplichtige' => [['id' => 'bp-3', 'eRecognitionLevel' => 'EH2', 'digipoortCertificate' => 'vault://cert']],
				]
			)
		);

		$taxReturn = [
			'id' => 'aangifte-3',
			'taxpayer' => 'bp-3',
			'commercialProfit' => 'jr-3',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canIndienen(taxReturnId: 'aangifte-3', object: $taxReturn));

	}//end testCannotIndienenWhenEHerkenningBelowEH3()

	/**
	 * Indiening is denied when an innovatiebox claim lacks an S&O-verklaring (REQ-VPB-004).
	 *
	 * @return void
	 */
	public function testCannotIndienenWhenInnovatieboxMissingSoVerklaring(): void {
		$this->wireObjectService(
			store: $this->buildSchemaStub(
				recordsBySchema: [
					'AnnualReport' => [['id' => 'jr-4', 'status' => 'gedeponeerd']],
					'Belastingplichtige' => [['id' => 'bp-4', 'eRecognitionLevel' => 'EH3', 'digipoortCertificate' => 'vault://cert']],
					'Innovatiebox' => [['taxReturn' => 'aangifte-4', 'soDeclarationReference' => '']],
				]
			)
		);

		$taxReturn = [
			'id' => 'aangifte-4',
			'taxpayer' => 'bp-4',
			'commercialProfit' => 'jr-4',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canIndienen(taxReturnId: 'aangifte-4', object: $taxReturn));

	}//end testCannotIndienenWhenInnovatieboxMissingSoVerklaring()

	/**
	 * Indiening fails closed when ObjectService throws (CWE-863).
	 *
	 * @return void
	 */
	public function testIndienenExceptionFailsClosed(): void {
		$this->wireObjectService(store: $this->buildFailingObjectServiceStub());
		$this->logger->expects($this->once())->method('error');

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$this->guard->canIndienen(
				taxReturnId: 'aangifte-x',
				object: ['id' => 'aangifte-x', 'taxpayer' => 'bp-x', 'commercialProfit' => 'jr-x']
			)
		);

	}//end testIndienenExceptionFailsClosed()

	/**
	 * An aanslag may be marked ontvangen when the aangifte is ingediend (REQ-VPB-010).
	 *
	 * @return void
	 */
	public function testCanAanslagOntvangenWhenAangifteIngediend(): void {
		$this->wireObjectService(
			store: $this->buildSchemaStub(
				recordsBySchema: ['VpbAangifte' => [['id' => 'aangifte-5', 'status' => 'submitted']]]
			)
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue(
			$this->guard->canAanslagOntvangen(assessmentId: 'aanslag-5', object: ['taxReturn' => 'aangifte-5'])
		);

	}//end testCanAanslagOntvangenWhenAangifteIngediend()

	/**
	 * An aanslag cannot be ontvangen while the aangifte is still concept (REQ-VPB-010).
	 *
	 * @return void
	 */
	public function testCannotAanslagOntvangenWhenAangifteConcept(): void {
		$this->wireObjectService(
			store: $this->buildSchemaStub(
				recordsBySchema: ['VpbAangifte' => [['id' => 'aangifte-6', 'status' => 'draft']]]
			)
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$this->guard->canAanslagOntvangen(assessmentId: 'aanslag-6', object: ['taxReturn' => 'aangifte-6'])
		);

	}//end testCannotAanslagOntvangenWhenAangifteConcept()

	/**
	 * A voeging satisfying article 15 (>=95%, gelijke boekjaren, NL) is allowed (REQ-VPB-007).
	 *
	 * @return void
	 */
	public function testCanVoegenWhenArticle15Satisfied(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue(
			$this->guard->canVoegen(
				unitId: 'fe-1',
				object: ['holdingPercentage' => 100, 'equalFinancialYears' => true, 'establishmentNetherlands' => true]
			)
		);

	}//end testCanVoegenWhenArticle15Satisfied()

	/**
	 * A voeging below 95% bezit is denied (REQ-VPB-007).
	 *
	 * @return void
	 */
	public function testCannotVoegenBelow95Percent(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$this->guard->canVoegen(
				unitId: 'fe-2',
				object: ['holdingPercentage' => 80, 'equalFinancialYears' => true, 'establishmentNetherlands' => true]
			)
		);

	}//end testCannotVoegenBelow95Percent()

	/**
	 * A voeging with a foreign dochter is denied (REQ-VPB-007).
	 *
	 * @return void
	 */
	public function testCannotVoegenWhenNotNl(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$this->guard->canVoegen(
				unitId: 'fe-3',
				object: ['holdingPercentage' => 100, 'equalFinancialYears' => true, 'establishmentNetherlands' => false]
			)
		);

	}//end testCannotVoegenWhenNotNl()

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
