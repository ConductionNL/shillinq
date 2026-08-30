<?php

/**
 * Unit tests for the bookkeeping-ifrs-rj-dual-gaap register fragment.
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
 * @spec openspec/changes/bookkeeping-ifrs-rj-dual-gaap/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
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
 * Verifies the dual-GAAP fragment is valid JSON, declares the six dual-GAAP
 * schemas with their declarative lifecycle / aggregation metadata (ADR-037 /
 * ADR-031), merges additively onto the monolith without disturbing existing
 * schemas, ships internally-consistent seed objects, and wires the lifecycle
 * transitions to the DualGaapGuard.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DualGaapFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-ifrs-rj-dual-gaap.json';

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
	 * The fragment declares all six dual-GAAP schemas (REQ-DGAAP-001..010).
	 *
	 * @return void
	 */
	public function testDeclaresAllSixSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$expected = [
			'AccountingFramework',
			'ChartOfAccountsMapping',
			'DualTransaction',
			'ReconciliationBridge',
			'StandardSpecificCalculation',
			'FrameworkElection',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug']);
		}

	}//end testDeclaresAllSixSchemas()

	/**
	 * DualTransaction declares all the divergence reason codes the spec enumerates
	 * (REQ-DGAAP-003).
	 *
	 * @return void
	 */
	public function testDualTransactionDeclaresReasonCodes(): void {
		$schema = $this->fragment()['components']['schemas']['DualTransaction'];
		$codes = $schema['properties']['divergenceReasonCode']['enum'];
		foreach ([
			'LEASE_IFRS16',
			'PENSION_IAS19',
			'ECL_IFRS9',
			'REVENUE_IFRS15',
			'IMPAIRMENT_IAS36',
			'BORROWING_COST_IAS23',
			'DEFERRED_TAX_IAS12',
			'BUSINESS_COMBINATION_IFRS3',
		] as $code) {
			self::assertContains($code, $codes, "DualTransaction must enumerate $code");
		}

		$classification = $schema['properties']['divergenceClassification']['enum'];
		self::assertSame(['permanent', 'temporary', 'reclassification'], $classification);

	}//end testDualTransactionDeclaresReasonCodes()

	/**
	 * DualTransaction and FrameworkElection declare lifecycles wired to the guard
	 * (ADR-031 exception path, REQ-DGAAP-003 / REQ-DGAAP-010).
	 *
	 * @return void
	 */
	public function testLifecyclesWireToGuard(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$dt = $schemas['DualTransaction']['x-openregister-lifecycle'];
		self::assertSame('open', $dt['initialState']);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\DualGaapGuard::canReconcileTransaction',
			$dt['transitions']['reconcile']['requires']
		);

		$fe = $schemas['FrameworkElection']['x-openregister-lifecycle'];
		self::assertSame('draft', $fe['initialState']);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\DualGaapGuard::canActivateElection',
			$fe['transitions']['activate']['requires']
		);

	}//end testLifecyclesWireToGuard()

	/**
	 * The reconciliation bridge is declared as an aggregation (ADR-031,
	 * REQ-DGAAP-005).
	 *
	 * @return void
	 */
	public function testReconciliationBridgeDeclaresAggregation(): void {
		$schema = $this->fragment()['components']['schemas']['ReconciliationBridge'];
		self::assertArrayHasKey('x-openregister-aggregations', $schema);
		self::assertArrayHasKey('bridgeByPeriodStandard', $schema['x-openregister-aggregations']);

	}//end testReconciliationBridgeDeclaresAggregation()

	/**
	 * Merging the fragment adds the six schemas without dropping the monolith's
	 * existing schemas (ADR-037 disjoint union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('AccountingFramework', $schemas);
		self::assertArrayHasKey('DualTransaction', $schemas);
		// A pre-existing monolith schema survives the merge.
		self::assertArrayHasKey('Account', $schemas);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas, and each ReconciliationBridge
	 * closing balance equals opening + sum(adjustments) (REQ-DGAAP-005);
	 * each reconciled temporary DualTransaction carries a deferred-tax effect
	 * (REQ-DGAAP-006).
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

			$schema = $object['@self']['schema'];

			if ($schema === 'ReconciliationBridge') {
				$opening = (int)round(((float)$object['openingBalanceRj']) * 100);
				$sum = 0;
				foreach ($object['adjustments'] as $adj) {
					$sum += (int)round(((float)$adj['amount']) * 100);
				}

				$closing = (int)round(((float)$object['closingBalanceIfrs']) * 100);
				self::assertSame(
					($opening + $sum),
					$closing,
					'Bridge ' . $object['@self']['slug'] . ' must satisfy closing = opening + sum(adjustments)'
				);
			}

			if ($schema === 'DualTransaction'
				&& ($object['state'] ?? '') === 'reconciled'
				&& ($object['divergenceClassification'] ?? '') === 'temporary'
			) {
				self::assertArrayHasKey('deferredTaxEffect', $object);
				self::assertNotNull($object['deferredTaxEffect']);
				self::assertGreaterThan(0.0, (float)$object['deferredTaxEffect']);
			}
		}//end foreach

	}//end testSeedObjectsAreConsistent()

	/**
	 * The five worked examples from design.md are seeded (lease, pension, ECL,
	 * a reconciliation bridge and a framework election).
	 *
	 * @return void
	 */
	public function testWorkedExamplesSeeded(): void {
		$bySchema = [];
		foreach ($this->fragment()['components']['objects'] as $object) {
			$bySchema[$object['@self']['schema']] = true;
		}

		foreach ([
			'AccountingFramework',
			'ChartOfAccountsMapping',
			'DualTransaction',
			'ReconciliationBridge',
			'StandardSpecificCalculation',
			'FrameworkElection',
		] as $schema) {
			self::assertArrayHasKey($schema, $bySchema, "Expected at least one seed for $schema");
		}

	}//end testWorkedExamplesSeeded()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
