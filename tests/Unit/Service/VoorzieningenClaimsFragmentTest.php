<?php

/**
 * Unit tests for the bookkeeping-voorzieningen-claims register fragment.
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
 * @spec openspec/changes/bookkeeping-voorzieningen-claims/specs/bookkeeping-voorzieningen-claims/spec.md
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
 * Verifies the IAS 37 / RJ 252 voorzieningen-claims fragment is valid JSON,
 * declares the ten schemas (Provision, ProvisionMovement, ContingentLiability,
 * six type-specific detail registers + ProvisionDisclosureTabel) with their
 * lifecycle + aggregation + calculation metadata (ADR-037 / ADR-031), merges
 * additively onto the monolith without disturbing existing schemas, ships seed
 * objects targeting only the declared schemas, and encodes the
 * REQ-PROV-001 (three-criteria), REQ-PROV-004 (roll-forward),
 * REQ-PROV-008 (disclosure table), REQ-PROV-016 (GL linkage),
 * REQ-PROV-017 (unwinding) and REQ-PROV-019 (prospective schattingswijziging)
 * invariants that the schema metadata must express.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VoorzieningenClaimsFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-voorzieningen-claims.json';

	/**
	 * Absolute path to the manifest fragment.
	 *
	 * @var string
	 */
	private string $manifestPath = __DIR__ . '/../../../src/manifest.d/bookkeeping-voorzieningen-claims.json';

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
	 * The fragment declares the core triplet + six type-specific detail
	 * registers + the disclosure-table (REQ-PROV-001 / REQ-PROV-004 /
	 * REQ-PROV-007 / REQ-PROV-008).
	 *
	 * @return void
	 */
	public function testDeclaresAllRequiredSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$expected = [
			'Provision',
			'ProvisionMovement',
			'ContingentLiability',
			'PensioenvoorzieningDetail',
			'JubileumvoorzieningDetail',
			'HerstructureringsvoorzieningDetail',
			'GarantievoorzieningDetail',
			'MilieuvoorzieningDetail',
			'ClaimsVoorzieningDetail',
			'ProvisionDisclosureTabel',
		];
		foreach ($expected as $slug) {
			self::assertArrayHasKey($slug, $schemas, "Fragment must declare $slug");
			self::assertSame($slug, $schemas[$slug]['slug']);
		}

	}//end testDeclaresAllRequiredSchemas()

	/**
	 * Provision carries the three-criteria recognition fields as required
	 * properties (REQ-PROV-001).
	 *
	 * @return void
	 */
	public function testProvisionEncodesThreeCriteriaAsRequired(): void {
		$schema = $this->fragment()['components']['schemas']['Provision'];

		foreach (['legalOrConstructiveObligation', 'obligatingEvent', 'probabilityOfOutflow', 'bestEstimate', 'bestEstimateRationale'] as $field) {
			self::assertContains($field, $schema['required'], "Provision must mark $field required");
		}

		$obligation = $schema['properties']['legalOrConstructiveObligation']['enum'];
		self::assertSame(['legal', 'constructive'], $obligation);

		$probability = $schema['properties']['probabilityOfOutflow'];
		self::assertSame(0, $probability['minimum']);
		self::assertSame(1, $probability['maximum']);

	}//end testProvisionEncodesThreeCriteriaAsRequired()

	/**
	 * Provision exposes a draft → active lifecycle whose activate transition
	 * is guarded by ProvisionGuard::canActivateProvision (ADR-031 reference)
	 * (REQ-PROV-001, REQ-PROV-005, REQ-PROV-006, REQ-PROV-010, REQ-PROV-018).
	 *
	 * @return void
	 */
	public function testProvisionLifecycleReferencesGuard(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$lifecycle = $schemas['Provision']['x-openregister-lifecycle'];

		self::assertSame('status', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);
		self::assertArrayHasKey('active', $lifecycle['states']);
		self::assertArrayHasKey('under-review', $lifecycle['states']);
		self::assertArrayHasKey('startHerwaardering', $lifecycle['transitions']);
		self::assertStringContainsString(
			'ProvisionGuard::canActivateProvision',
			$lifecycle['transitions']['activate']['requires']
		);

	}//end testProvisionLifecycleReferencesGuard()

	/**
	 * Provision carries the discountedValue x-openregister-calculations target
	 * implementing IAS 37 §45 PV (REQ-PROV-003).
	 *
	 * @return void
	 */
	public function testProvisionDiscountedValueCalculationDeclared(): void {
		$calculations = $this->fragment()['components']['schemas']['Provision']['x-openregister-calculations'];
		self::assertArrayHasKey('discountedValue', $calculations);
		self::assertSame('discountedValue', $calculations['discountedValue']['target']);
		self::assertStringContainsString('bestEstimate', $calculations['discountedValue']['expression']);
		self::assertStringContainsString('discountRateApplied', $calculations['discountedValue']['expression']);

	}//end testProvisionDiscountedValueCalculationDeclared()

	/**
	 * ProvisionMovement exposes a single irreversible close transition guarded
	 * by ProvisionGuard::canCloseMovement (REQ-PROV-004 / REQ-PROV-019).
	 *
	 * @return void
	 */
	public function testProvisionMovementCloseIsIrreversible(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$lifecycle = $schemas['ProvisionMovement']['x-openregister-lifecycle'];

		self::assertSame(['open', 'closed'], array_keys($lifecycle['states']));
		self::assertSame(['close'], array_keys($lifecycle['transitions']));
		self::assertSame('open', $lifecycle['transitions']['close']['from']);
		self::assertSame('closed', $lifecycle['transitions']['close']['to']);
		self::assertStringContainsString(
			'ProvisionGuard::canCloseMovement',
			$lifecycle['transitions']['close']['requires']
		);

	}//end testProvisionMovementCloseIsIrreversible()

	/**
	 * ProvisionMovement declares the IAS 37 §84 roll-forward closingBalance
	 * formula both as a calculation target and as a per-period aggregation
	 * (REQ-PROV-004).
	 *
	 * @return void
	 */
	public function testProvisionMovementRollForwardFormula(): void {
		$schema = $this->fragment()['components']['schemas']['ProvisionMovement'];
		$calculations = $schema['x-openregister-calculations'];
		$aggregations = $schema['x-openregister-aggregations'];

		$expr = $calculations['closingBalance']['expression'];
		foreach ([
			'openingBalance',
			'additions',
			'additionsAcquired',
			'usedDuringPeriod',
			'releasedUnused',
			'unwindingOfDiscount',
			'effectOfChangeInDiscountRate',
			'effectOfChangeInEstimate',
			'translationDifferences',
		] as $bucket) {
			self::assertStringContainsString($bucket, $expr, "closingBalance expression must reference $bucket");
		}

		self::assertArrayHasKey('provisionRollForward', $aggregations);
		self::assertSame(['ProvisionMovement.provision', 'ProvisionMovement.period'], $aggregations['provisionRollForward']['groupBy']);

	}//end testProvisionMovementRollForwardFormula()

	/**
	 * ContingentLiability uses the IAS 37 probability buckets (REQ-PROV-007 /
	 * REQ-PROV-015).
	 *
	 * @return void
	 */
	public function testContingentLiabilityProbabilityBuckets(): void {
		$schema = $this->fragment()['components']['schemas']['ContingentLiability'];
		$buckets = $schema['properties']['probabilityCategory']['enum'];
		self::assertSame(['remote', 'possible', 'probable-but-no-reliable-estimate'], $buckets);

	}//end testContingentLiabilityProbabilityBuckets()

	/**
	 * ClaimsVoorzieningDetail requires the legalAdviceMemo FK (REQ-PROV-006).
	 *
	 * @return void
	 */
	public function testClaimsDetailRequiresLegalAdviceMemo(): void {
		$schema = $this->fragment()['components']['schemas']['ClaimsVoorzieningDetail'];
		self::assertContains('legalAdviceMemo', $schema['required']);

	}//end testClaimsDetailRequiresLegalAdviceMemo()

	/**
	 * HerstructureringsvoorzieningDetail requires detailedPlanDate and
	 * planCommunicatedTo for the IAS 37 §72 timeliness + §75 communication
	 * tests (REQ-PROV-005).
	 *
	 * @return void
	 */
	public function testHerstructureringDetailRequiresPlanFields(): void {
		$schema = $this->fragment()['components']['schemas']['HerstructureringsvoorzieningDetail'];
		self::assertContains('detailedPlanDate', $schema['required']);
		self::assertContains('planCommunicatedTo', $schema['required']);

	}//end testHerstructureringDetailRequiresPlanFields()

	/**
	 * PensioenvoorzieningDetail constrains actuarialMethod to PUC per IAS 19
	 * (REQ-PROV-014).
	 *
	 * @return void
	 */
	public function testPensioenDetailActuarialMethodIsPuc(): void {
		$schema = $this->fragment()['components']['schemas']['PensioenvoorzieningDetail'];
		self::assertSame(['PUC'], $schema['properties']['actuarialMethod']['enum']);

	}//end testPensioenDetailActuarialMethodIsPuc()

	/**
	 * MilieuvoorzieningDetail constrains regulatoryFramework to Wbb / Wm /
	 * EU-IED (REQ-PROV-013).
	 *
	 * @return void
	 */
	public function testMilieuDetailRegulatoryFrameworkEnum(): void {
		$schema = $this->fragment()['components']['schemas']['MilieuvoorzieningDetail'];
		self::assertSame(['Wbb', 'Wm', 'EU-IED'], $schema['properties']['regulatoryFramework']['enum']);

	}//end testMilieuDetailRegulatoryFrameworkEnum()

	/**
	 * ProvisionDisclosureTabel declares the join + sum aggregation that emits
	 * the per-period, per-provisionType jaarrekening-disclosure record
	 * (REQ-PROV-008).
	 *
	 * @return void
	 */
	public function testDisclosureTableAggregationIsDeclared(): void {
		$schema = $this->fragment()['components']['schemas']['ProvisionDisclosureTabel'];
		$agg = $schema['x-openregister-aggregations']['provisionDisclosureGeneration'];

		// `from`/`metrics`, not `source`/`operations`. AggregationRunner reads
		// neither of the old keys, so this aggregation produced none of the eight
		// figures below — it just returned nothing, under HTTP 200.
		self::assertSame('ProvisionMovement', $agg['from']);
		self::assertArrayNotHasKey('source', $agg, '`source` is not an engine key');
		self::assertArrayNotHasKey('operations', $agg, '`operations` is not an engine key');
		self::assertSame(['Provision.provisionType', 'ProvisionMovement.period'], $agg['groupBy']);

		$byAlias = [];
		foreach ($agg['metrics'] as $metric) {
			$byAlias[$metric['as']] = $metric;
		}
		foreach (['openingBalance', 'additions', 'used', 'released', 'unwinding', 'estimatesChange', 'closingBalance', 'count'] as $bucket) {
			self::assertArrayHasKey($bucket, $byAlias, "Disclosure aggregation must produce $bucket");
		}

	}//end testDisclosureTableAggregationIsDeclared()

	/**
	 * Merging the fragment adds the ten voorzieningen-claims schemas without
	 * dropping any pre-existing monolith schema (ADR-037 disjoint union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$baseCount = count($base['components']['schemas']);
		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		foreach (['Provision', 'ProvisionMovement', 'ContingentLiability', 'ProvisionDisclosureTabel'] as $slug) {
			self::assertArrayHasKey($slug, $schemas);
		}

		// Ten net new schemas, with no pre-existing schema lost.
		self::assertGreaterThanOrEqual($baseCount + 10, count($schemas));

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas and the shillinq register;
	 * three Provision specimens cover garantie / milieu / claims and two
	 * ContingentLiability specimens cover possible + remote (REQ-PROV-007 /
	 * REQ-PROV-015 / seed plan in design.md).
	 *
	 * @return void
	 */
	public function testSeedObjectsAreConsistent(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);

		$provisionTypes = [];
		$contingentBuckets = [];
		$hasProvisionMovement = false;

		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);
			self::assertArrayHasKey('administrationId', $object);

			if ($object['@self']['schema'] === 'Provision') {
				$provisionTypes[$object['provisionType']] = true;
			}

			if ($object['@self']['schema'] === 'ContingentLiability') {
				$contingentBuckets[$object['probabilityCategory']] = true;
			}

			if ($object['@self']['schema'] === 'ProvisionMovement') {
				$hasProvisionMovement = true;
			}
		}

		foreach (['guarantee', 'environment', 'claims'] as $type) {
			self::assertArrayHasKey($type, $provisionTypes, "Seed must include provisionType=$type");
		}

		foreach (['possible', 'remote'] as $bucket) {
			self::assertArrayHasKey($bucket, $contingentBuckets, "Seed must include contingent probability=$bucket");
		}

		self::assertTrue($hasProvisionMovement, 'Seed must ship at least one ProvisionMovement specimen');

	}//end testSeedObjectsAreConsistent()

	/**
	 * The manifest fragment declares the three navigation entries + index
	 * pages under the existing Bookkeeping menu group (Task 18 / REQ-PROV-008).
	 *
	 * @return void
	 */
	public function testManifestNavigationEntriesAreDeclared(): void {
		self::assertFileExists($this->manifestPath);
		$manifest = json_decode((string)file_get_contents($this->manifestPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());

		$menuIds = [];
		foreach ($manifest['menu'][0]['children'] as $child) {
			$menuIds[] = $child['id'];
		}

		self::assertSame('Bookkeeping', $manifest['menu'][0]['id']);
		foreach (['Provisions', 'ProvisionMovements', 'ContingentLiabilities'] as $expected) {
			self::assertContains($expected, $menuIds, "Manifest must declare menu entry $expected");
		}

		$pageIds = array_column($manifest['pages'], 'id');
		foreach (['Provisions', 'ProvisionMovements', 'ContingentLiabilities'] as $expected) {
			self::assertContains($expected, $pageIds, "Manifest must declare index page $expected");
		}

	}//end testManifestNavigationEntriesAreDeclared()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
