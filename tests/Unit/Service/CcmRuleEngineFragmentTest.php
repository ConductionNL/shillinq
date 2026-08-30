<?php

/**
 * Unit tests for the bookkeeping-ccm-rule-engine register fragment.
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
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
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
 * Verifies the CCM fragment is valid JSON, declares the six CCM schemas with
 * their immutable audit trail and declarative lifecycle / notification /
 * scheduled-workflow metadata, merges additively onto the monolith (ADR-037),
 * and ships the 60-rule seed library plus the SoD matrix.
 */
final class CcmRuleEngineFragmentTest extends TestCase {
	// PHPUnit assertions take positional ($actual, $expected, $message) arguments;
	// the custom named-parameter sniff does not apply to them.
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-ccm-rule-engine.json';

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
	 * The fragment file is present and valid JSON with the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the six CCM schemas (REQ-CCM-001..006).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresSixSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$expected = [
			'CcmRule',
			'CcmFinding',
			'CcmSegregationMatrix',
			'CcmUserFunctionAssignment',
			'CcmBaseline',
			'CcmAuditCommitteeReport',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug'], "$name must carry a slug");
		}

	}//end testFragmentDeclaresSixSchemas()

	/**
	 * Every CCM schema declares an immutable audit trail (REQ-CCM-009).
	 *
	 * @return void
	 */
	public function testEverySchemaDeclaresAuditTrail(): void {
		$schemas = $this->fragment()['components']['schemas'];

		foreach ($schemas as $name => $schema) {
			self::assertArrayHasKey('x-openregister-audit-trail', $schema, "$name must declare an audit trail (REQ-CCM-009)");
			self::assertTrue((bool)$schema['x-openregister-audit-trail'], "$name audit trail must be enabled");
		}

	}//end testEverySchemaDeclaresAuditTrail()

	/**
	 * The finding four-state workflow and report approval gate are declarative
	 * and reference the CcmFindingGuard where a cross-field precondition exists.
	 *
	 * @return void
	 */
	public function testLifecycleTransitionsReferenceGuard(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$finding = $schemas['CcmFinding']['x-openregister-lifecycle'];
		self::assertSame('status', $finding['field']);
		self::assertSame('open', $finding['initialState']);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\CcmFindingGuard::canDismiss',
			$finding['transitions']['dismissFalsePositive']['requires']
		);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\CcmFindingGuard::canConfirm',
			$finding['transitions']['confirmDeficiency']['requires']
		);

		$report = $schemas['CcmAuditCommitteeReport']['x-openregister-lifecycle'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\CcmFindingGuard::canApproveReport',
			$report['transitions']['approve']['requires']
		);

	}//end testLifecycleTransitionsReferenceGuard()

	/**
	 * The three nightly materialisation jobs are declarative scheduled workflows,
	 * not PHP TimedJob classes (REQ-CCM-003 / REQ-CCM-005).
	 *
	 * @return void
	 */
	public function testNightlyJobsAreDeclarativeScheduledWorkflows(): void {
		$schemas = $this->fragment()['components']['schemas'];

		self::assertArrayHasKey(
			'ccm-baseline-materialisation',
			$schemas['CcmBaseline']['x-openregister-scheduled-workflows']
		);
		self::assertArrayHasKey(
			'ccm-sod-materialisation',
			$schemas['CcmUserFunctionAssignment']['x-openregister-scheduled-workflows']
		);
		self::assertArrayHasKey(
			'ccm-finding-auto-escalation',
			$schemas['CcmFinding']['x-openregister-scheduled-workflows']
		);

	}//end testNightlyJobsAreDeclarativeScheduledWorkflows()

	/**
	 * The finding schema declares the auto-escalation notification (REQ-CCM-008).
	 *
	 * @return void
	 */
	public function testFindingDeclaresEscalationNotification(): void {
		$finding = $this->fragment()['components']['schemas']['CcmFinding'];

		self::assertArrayHasKey('x-openregister-notifications', $finding);
		self::assertArrayHasKey('onCreate', $finding['x-openregister-notifications']);
		self::assertArrayHasKey('onAutoEscalate', $finding['x-openregister-notifications']);

	}//end testFindingDeclaresEscalationNotification()

	/**
	 * The seed library ships 60 rules across the eight control families plus the
	 * custom pool (REQ-CCM-007), and a SoD function-code matrix (REQ-CCM-005).
	 *
	 * @return void
	 */
	public function testSeedLibraryShipsSixtyRulesAndSodMatrix(): void {
		$objects = $this->fragment()['components']['objects'];

		$rules = array_filter(
			$objects,
			static fn (array $o): bool => ($o['@self']['schema'] ?? '') === 'CcmRule'
		);
		self::assertCount(60, $rules, 'The rule library must ship 60 seed rules (REQ-CCM-007)');

		$families = [];
		foreach ($rules as $rule) {
			$families[$rule['controlFamily']] = true;
		}

		foreach ([
			'segregation-of-duties',
			'duplicate-detection',
			'anomalous-amount',
			'timing',
			'master-data',
			'approval-bypass',
			'manual-journal',
			'value-chain',
		] as $family) {
			self::assertArrayHasKey($family, $families, "Family $family must be represented in the seed library");
		}

		$sod = array_filter(
			$objects,
			static fn (array $o): bool => ($o['@self']['schema'] ?? '') === 'CcmSegregationMatrix'
		);
		self::assertGreaterThanOrEqual(10, count($sod), 'The SoD matrix must ship function codes (REQ-CCM-005)');

	}//end testSeedLibraryShipsSixtyRulesAndSodMatrix()

	/**
	 * Merging the fragment onto the monolith adds the six schemas without
	 * dropping any existing monolith schema (ADR-037 additive union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$beforeSchemas = array_keys($base['components']['schemas']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('CcmRule', $schemas);
		self::assertArrayHasKey('CcmAuditCommitteeReport', $schemas);

		// Pre-existing monolith schemas survive the merge.
		foreach ($beforeSchemas as $name) {
			self::assertArrayHasKey($name, $schemas, "Monolith schema $name must survive the merge");
		}

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas in the shillinq register, and use
	 * unique slugs (REQ-CCM seed-data pattern).
	 *
	 * @return void
	 */
	public function testSeedObjectsTargetDeclaredSchemas(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		$slugs = [];
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			$schema = $object['@self']['schema'];
			self::assertArrayHasKey($schema, $schemas, "Seed object targets undeclared schema $schema");
			$slug = $object['@self']['slug'];
			self::assertArrayNotHasKey($slug, $slugs, "Duplicate seed slug $slug");
			$slugs[$slug] = true;
		}

	}//end testSeedObjectsTargetDeclaredSchemas()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
