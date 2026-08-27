<?php

/**
 * Unit tests for the bookkeeping-emu-reporting register fragment.
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
 * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the EMU-reporting fragment is valid JSON, declares the four EMU
 * entities with their lifecycle/aggregation/RBAC blocks, ships the seed objects,
 * and merges additively onto the monolith register (ADR-037).
 */
final class EmuReportingFragmentTest extends TestCase {
	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-emu-reporting.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * The fragment file is present and valid JSON.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		self::assertArrayHasKey('schemas', $data['components']);
	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the four EMU entities.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresFourEntities(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$schemas = $data['components']['schemas'];

		foreach (['EMUReport', 'EMUAdjustment', 'CashFlowItem', 'DebtPosition'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertArrayHasKey('x-openregister-rbac', $schemas[$name], "$name must declare RBAC");
			self::assertArrayHasKey('type', $schemas[$name]);
		}
	}//end testFragmentDeclaresFourEntities()

	/**
	 * EMUReport declares the concept → ingediend → herzien lifecycle with the
	 * submission guard on the indienen transition.
	 *
	 * @return void
	 */
	public function testEmuReportLifecycleAndSubmissionGuard(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$lifecycle = $data['components']['schemas']['EMUReport']['x-openregister-lifecycle'];

		self::assertSame('status', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);
		foreach (['draft', 'submitted', 'herzien'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "EMUReport must declare state $state");
		}

		self::assertArrayHasKey('indienen', $lifecycle['transitions']);
		self::assertSame(
			'OCA\\Shillinq\\Guard\\EmuSubmissionGuard::requireApproval',
			$lifecycle['transitions']['indienen']['requires']
		);

		// Retention is 10 years per Archiefwet (REQ-EMU-012).
		self::assertSame('P10Y', $lifecycle['retention']['period']);
	}//end testEmuReportLifecycleAndSubmissionGuard()

	/**
	 * EMUAdjustment declares the eight Wet Hof art. 3 macro-rule types.
	 *
	 * @return void
	 */
	public function testEmuAdjustmentDeclaresEightTypes(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$enum = $data['components']['schemas']['EMUAdjustment']['properties']['type']['enum'];

		$expected = [
			'elimination-depreciation',
			'elimination-provision-contribution',
			'elimination-withdrawal-reserve',
			'addition-gross-investment',
			'addition-repayment',
			'elimination-book-profit-divestment',
			'correction-transaction-moment',
			'intercompany-elimination',
		];
		self::assertCount(8, $enum);
		foreach ($expected as $type) {
			self::assertContains($type, $enum, "EMUAdjustment.type must include $type");
		}
	}//end testEmuAdjustmentDeclaresEightTypes()

	/**
	 * DebtPosition declares the ESA2010 categorieEurostat enum and the bruto
	 * schuld aggregation filtered on teltMeeInEmuSchuld.
	 *
	 * @return void
	 */
	public function testDebtPositionEsa2010ClassificationAndAggregation(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$schema = $data['components']['schemas']['DebtPosition'];

		$enum = $schema['properties']['categoryEurostat']['enum'];
		foreach (['off_2-deposits', 'off_3-securities', 'off_4-loans', 'off_7-derivatives'] as $cat) {
			self::assertContains($cat, $enum, "categorieEurostat must include $cat");
		}

		// `metric`/`field`, not `sum`. AggregationRunner reads neither `sum` nor
		// `source`, so this computed nothing at all.
		$agg = $schema['x-openregister-aggregations']['brutoSchuldPerCategorie'];
		self::assertTrue($agg['filter']['teltMeeInEmuDebt']);
		self::assertSame('sum', $agg['metric']);
		self::assertSame('outstandingDebt', $agg['field']);
		self::assertArrayNotHasKey('sum', $agg, '`sum` is not an engine key');
		self::assertArrayNotHasKey('source', $agg, '`source` is not an engine key');

		// The per-report correlation is a groupBy DIMENSION now: `reportId:
		// "@self.reportId"` needed a parent row that no caller supplies, so it
		// stayed a literal string and matched nothing.
		self::assertContains('reportId', $agg['groupBy']);
		self::assertArrayNotHasKey('reportId', $agg['filter']);
	}//end testDebtPositionEsa2010ClassificationAndAggregation()

	/**
	 * The fragment ships one seed object per EMU entity.
	 *
	 * @return void
	 */
	public function testFragmentShipsSeedObjects(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertArrayHasKey('objects', $data);

		$bySchema = [];
		foreach ($data['objects'] as $obj) {
			$bySchema[$obj['@self']['schema']] = true;
		}
		foreach (['EMUReport', 'EMUAdjustment', 'CashFlowItem', 'DebtPosition'] as $name) {
			self::assertArrayHasKey($name, $bySchema, "Fragment must seed a $name object");
		}
	}//end testFragmentShipsSeedObjects()

	/**
	 * Merging the fragment onto the monolith adds the four schemas and unions the
	 * seed objects without dropping any existing schema or object (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = json_decode((string)file_get_contents($this->fragmentPath), true);

		$schemaCountBefore = count($base['components']['schemas']);
		$objectCountBefore = count($base['objects']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('EMUReport', $schemas);
		self::assertArrayHasKey('DebtPosition', $schemas);
		self::assertSame($schemaCountBefore + 4, count($schemas), 'Exactly four schemas added, none lost');
		self::assertSame($objectCountBefore + 4, count($merged['objects']), 'Four seed objects unioned');

		// A pre-existing schema survives the merge.
		self::assertArrayHasKey('GLLine', $schemas);
	}//end testFragmentMergesAdditivelyOntoMonolith()
}//end class
