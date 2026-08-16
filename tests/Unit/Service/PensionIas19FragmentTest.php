<?php

/**
 * Unit tests for the bookkeeping-pension-ias19 register fragment.
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
 * @spec openspec/changes/bookkeeping-pension-ias19/specs/bookkeeping-pension-ias19/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the IAS 19 pension fragment is valid JSON, declares the six pension
 * schemas with their lifecycle + aggregation metadata (ADR-037 / ADR-031),
 * merges additively onto the monolith without disturbing existing schemas, and
 * ships seed objects targeting only the declared schemas. Encodes the REQ-PEN-001
 * (PUC for DB), REQ-PEN-004 (OCI non-recycling) and REQ-PEN-008 (DC) invariants
 * that the schema metadata must express.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PensionIas19FragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-pension-ias19.json';

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
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment is present and valid JSON with the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares all six pension schemas (REQ-PEN-001..REQ-PEN-008).
	 *
	 * @return void
	 */
	public function testDeclaresSixPensionSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$expected = [
			'PensionPlan',
			'ActuarialValuation',
			'PensionMovement',
			'PensionAssumptionSensitivity',
			'PensionAssetDetail',
			'PensionDisclosureTabel',
		];
		foreach ($expected as $slug) {
			self::assertArrayHasKey($slug, $schemas, "Fragment must declare $slug");
			self::assertSame($slug, $schemas[$slug]['slug']);
		}

	}//end testDeclaresSixPensionSchemas()

	/**
	 * The PensionPlan planType and ActuarialValuation methodology carry the
	 * enums that enforce DB/DC handling (REQ-PEN-001, REQ-PEN-008).
	 *
	 * @return void
	 */
	public function testPlanTypeAndMethodologyEnums(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$planType = $schemas['PensionPlan']['properties']['planType']['enum'];
		self::assertSame(['DB', 'DC', 'CDC', 'hybrid'], $planType);

		// REQ-PEN-001: a DB plan's valuation methodology must be PUC; DC for contribution-only.
		$methodology = $schemas['ActuarialValuation']['properties']['methodology']['enum'];
		self::assertContains('PUC', $methodology);
		self::assertContains('DC', $methodology);

	}//end testPlanTypeAndMethodologyEnums()

	/**
	 * The PensionMovement separates the three IAS 19R buckets via computed
	 * P&L / OCI fields (REQ-PEN-003, REQ-PEN-004).
	 *
	 * @return void
	 */
	public function testMovementCarriesThreeBucketFields(): void {
		$props = $this->fragment()['components']['schemas']['PensionMovement']['properties'];
		foreach (['serviceCostCurrent', 'netInterestCost', 'actuarialLossGainDBO', 'netPensionMovementPL', 'netPensionMovementOCI'] as $field) {
			self::assertArrayHasKey($field, $props, "PensionMovement must declare $field");
		}

	}//end testMovementCarriesThreeBucketFields()

	/**
	 * ActuarialValuation and PensionPlan carry x-openregister-lifecycle blocks
	 * with guard references (ADR-031, REQ-PEN-001, REQ-PEN-002).
	 *
	 * @return void
	 */
	public function testLifecyclesReferenceGuard(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$valLifecycle = $schemas['ActuarialValuation']['x-openregister-lifecycle'];
		self::assertSame('approvalStatus', $valLifecycle['field']);
		self::assertSame('draft', $valLifecycle['initialState']);
		self::assertStringContainsString(
			'PensionIas19Guard::canApproveValuation',
			$valLifecycle['transitions']['approve']['requires']
		);
		self::assertStringContainsString(
			'PensionIas19Guard::canLockValuation',
			$valLifecycle['transitions']['lock']['requires']
		);

		$planLifecycle = $schemas['PensionPlan']['x-openregister-lifecycle'];
		self::assertStringContainsString(
			'PensionIas19Guard::canActivatePlan',
			$planLifecycle['transitions']['activate']['requires']
		);

	}//end testLifecyclesReferenceGuard()

	/**
	 * Roll-forward, sensitivity and disclosure aggregations are declared
	 * (ADR-031, REQ-PEN-003, REQ-PEN-006, REQ-PEN-007).
	 *
	 * @return void
	 */
	public function testDeclaresAggregations(): void {
		$schemas = $this->fragment()['components']['schemas'];

		self::assertArrayHasKey('pensionRollForward', $schemas['PensionMovement']['x-openregister-aggregations']);
		self::assertArrayHasKey('pensionSensitivityDeltas', $schemas['PensionAssumptionSensitivity']['x-openregister-aggregations']);
		self::assertArrayHasKey('pensionDisclosureGeneration', $schemas['PensionDisclosureTabel']['x-openregister-aggregations']);

	}//end testDeclaresAggregations()

	/**
	 * The roll-forward aggregation computes dboClosing and the P&L/OCI split as
	 * disjoint expressions (REQ-PEN-003, REQ-PEN-004).
	 *
	 * @return void
	 */
	public function testRollForwardExpressionsAreDisjoint(): void {
		$ops = $this->fragment()['components']['schemas']['PensionMovement']['x-openregister-aggregations']['pensionRollForward']['operations'];

		self::assertStringContainsString('dboOpening', $ops['dboClosing']['expression']);
		// P&L bucket = service + past service + net interest - settlement; it must
		// NOT reference the actuarial (OCI) remeasurement fields (REQ-PEN-004).
		self::assertStringNotContainsString('actuarial', $ops['netPensionMovementPL']['expression']);
		// OCI bucket references only the actuarial remeasurement fields.
		self::assertStringContainsString('actuarialLossGainDBO', $ops['netPensionMovementOCI']['expression']);
		self::assertStringContainsString('actuarialGainLossAssets', $ops['netPensionMovementOCI']['expression']);

	}//end testRollForwardExpressionsAreDisjoint()

	/**
	 * Merging the fragment adds the six pension schemas without dropping any
	 * pre-existing monolith schema (ADR-037 disjoint union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$baseCount = count($base['components']['schemas']);
		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('PensionPlan', $schemas);
		self::assertArrayHasKey('ActuarialValuation', $schemas);
		// No pre-existing schema is lost; the fragment is purely additive.
		self::assertGreaterThanOrEqual($baseCount + 6, count($schemas));

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas and the shillinq register.
	 *
	 * @return void
	 */
	public function testSeedObjectsAreConsistent(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);
			self::assertArrayHasKey('administrationId', $object);
		}

	}//end testSeedObjectsAreConsistent()

	/**
	 * Both a DB and a DC seed plan ship so operators see each disclosure path
	 * (design.md Seed Data; REQ-PEN-001, REQ-PEN-008).
	 *
	 * @return void
	 */
	public function testSeedsShipDbAndDcPlans(): void {
		$objects = $this->fragment()['components']['objects'];
		$types = [];
		foreach ($objects as $object) {
			if ($object['@self']['schema'] === 'PensionPlan') {
				$types[$object['planType']] = true;
			}
		}

		self::assertArrayHasKey('DB', $types);
		self::assertArrayHasKey('DC', $types);

	}//end testSeedsShipDbAndDcPlans()

	/**
	 * REQ-PEN-003 / REQ-PEN-004 — PensionMovement carries a declarative
	 * `x-openregister-posting-recipe` block (Task 16) consumed by the
	 * bookkeeping-general-ledger JournalEntry materialiser. The recipe
	 * pins three buckets (serviceCost → personeelslasten 4xxx, netInterest
	 * → financiële lasten 6xxx, remeasurementOCI → OCI 8xxx) with the OCI
	 * bucket flagged `recyclable: false` per REQ-PEN-004.
	 *
	 * @return void
	 */
	public function testPensionMovementCarriesGlPostingRecipe(): void {
		$recipe = $this->fragment()['components']['schemas']['PensionMovement']['x-openregister-posting-recipe'];

		self::assertSame('PensionMovement', $recipe['sourceSchema']);
		self::assertSame('JournalEntry', $recipe['targetSchema']);
		self::assertSame('linkedJournalEntries', $recipe['linkBackField']);

		$buckets = [];
		foreach ($recipe['buckets'] as $bucket) {
			$buckets[$bucket['name']] = $bucket;
		}

		self::assertArrayHasKey('serviceCost', $buckets);
		self::assertArrayHasKey('netInterest', $buckets);
		self::assertArrayHasKey('remeasurementOCI', $buckets);

		// REQ-PEN-003: three account categories per IAS 19R bucket.
		self::assertSame('4100-4199', $buckets['serviceCost']['accountRange']);
		self::assertSame('6600-6699', $buckets['netInterest']['accountRange']);
		self::assertSame('8000-8999', $buckets['remeasurementOCI']['accountRange']);

		// REQ-PEN-004: the OCI bucket is explicitly non-recycling.
		self::assertFalse(
			$buckets['remeasurementOCI']['recyclable'],
			'OCI bucket must declare recyclable: false to enforce REQ-PEN-004 non-recycling'
		);

	}//end testPensionMovementCarriesGlPostingRecipe()

	/**
	 * REQ-DT-001 — PensionMovement carries a declarative
	 * `x-openregister-deferred-tax-hint` block (Task 17) consumed by the
	 * bookkeeping-deferred-tax detector. The hint pins category=pension
	 * (matching the merged TemporaryDifference enum) and type=deductible
	 * (NL Vpb rule: pension provisions are only deductible when paid out).
	 *
	 * @return void
	 */
	public function testPensionMovementCarriesDeferredTaxHint(): void {
		$hint = $this->fragment()['components']['schemas']['PensionMovement']['x-openregister-deferred-tax-hint'];

		self::assertSame('TemporaryDifference', $hint['targetSchema']);
		self::assertSame('pension', $hint['category']);
		self::assertSame('deductible', $hint['type']);
		self::assertSame('long-term', $hint['reversalPattern']);

		// Commercial side covers both P&L and OCI movement; the tax side
		// covers only the paid (employer contributions) portion.
		self::assertStringContainsString('netPensionMovementPL', $hint['commercialCarryingSource']);
		self::assertStringContainsString('netPensionMovementOCI', $hint['commercialCarryingSource']);
		self::assertSame('employerContributions', $hint['taxCarryingSource']);

		// REQ-DT-009: the OCI component must flow to TaxProvision.recognisedInOCI.
		self::assertSame('netPensionMovementOCI', $hint['ociComponentSource']);

	}//end testPensionMovementCarriesDeferredTaxHint()

	/**
	 * REQ-PEN-007 — PensionDisclosureTabel carries a declarative
	 * `x-openregister-disclosure-source` block (Task 18) consumed by the
	 * bookkeeping-financial-statements Note renderer. The contract pins
	 * the consumer schema/field, the source field, the lifecycle gate
	 * (status ∈ {approved, published}) and supported render modes.
	 *
	 * @return void
	 */
	public function testDisclosureTabelExposesFinancialStatementsSource(): void {
		$source = $this->fragment()['components']['schemas']['PensionDisclosureTabel']['x-openregister-disclosure-source'];

		self::assertSame('bookkeeping-financial-statements', $source['consumerSpec']);
		self::assertSame('Note', $source['consumerSchema']);
		self::assertSame('tableContent', $source['sourceField']);
		self::assertContains('markdown', $source['renderModes']);
		self::assertContains('html', $source['renderModes']);

		// Lifecycle gate: only approved/published tables flow into the jaarrekening.
		self::assertSame(['approved', 'published'], $source['selector']['status']);

	}//end testDisclosureTabelExposesFinancialStatementsSource()

	/**
	 * REQ-PEN-010 — PensionPlan carries a declarative
	 * `x-openregister-hrmq-roster-source` block (Task 19) pinning the
	 * full HRMQ deelnemersbestand contract: projection, write-back into
	 * the latest draft ActuarialValuation, divergence threshold and the
	 * lock-guard pointer enforcing rosterReconciled. Also asserts the
	 * `linkedProvisionId` FK (Task 15) for voorzieningen-claims.
	 *
	 * @return void
	 */
	public function testPensionPlanDeclaresCrossAppIntegrationContracts(): void {
		$plan = $this->fragment()['components']['schemas']['PensionPlan'];

		// Task 15: voorzieningen-claims provision FK.
		self::assertArrayHasKey('linkedProvisionId', $plan['properties']);
		self::assertTrue($plan['properties']['linkedProvisionId']['nullable']);

		// Task 19: HRMQ roster source.
		$roster = $plan['x-openregister-hrmq-roster-source'];
		self::assertSame('hrmq', $roster['sourceApp']);
		self::assertSame('linkedHrmqGroup', $roster['groupRef']);
		self::assertSame('ActuarialValuation', $roster['writeBack']['targetSchema']);
		self::assertSame(5, $roster['divergenceWarning']['thresholdPct']);
		self::assertStringContainsString(
			'PensionIas19Guard::canLockValuation',
			$roster['lockGuard']
		);

	}//end testPensionPlanDeclaresCrossAppIntegrationContracts()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
