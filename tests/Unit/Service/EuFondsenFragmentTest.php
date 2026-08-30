<?php

/**
 * Unit tests for the bookkeeping-single-audit-eu-fondsen register fragment.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the T3 EU-fondsen fragment is valid JSON, declares the seven
 * EU-fondsen schemas with lifecycles, seeds the eligibility-rule + sample
 * project objects, and merges additively onto the monolith (ADR-037).
 */
final class EuFondsenFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-single-audit-eu-fondsen.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Decode the fragment JSON.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end fragment()

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
	 * The fragment file is present and valid JSON with a schemas object.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey('schemas', $data['components']);
	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares all seven EU-fondsen schemas (REQ-EUF-001 t/m 009).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresSevenSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$expected = [
			'EuProject',
			'EligibilityRule',
			'SegregatedLedger',
			'EuExpenditure',
			'SupportingDocument',
			'IrregularityReport',
			'AuditTrail',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
		}

		self::assertCount(7, $schemas);
	}//end testFragmentDeclaresSevenSchemas()

	/**
	 * State-bearing schemas declare a state-field lifecycle; AuditTrail is append-only.
	 *
	 * @return void
	 */
	public function testLifecyclesAreDeclared(): void {
		$schemas = $this->fragment()['components']['schemas'];

		foreach (['EuProject', 'EligibilityRule', 'SegregatedLedger', 'EuExpenditure', 'SupportingDocument', 'IrregularityReport'] as $name) {
			self::assertArrayHasKey('x-openregister-lifecycle', $schemas[$name], "$name must declare a lifecycle");
			self::assertSame('state', $schemas[$name]['x-openregister-lifecycle']['field'], "$name lifecycle drives the state field");
		}

		// AuditTrail is append-only (REQ-EUF-009).
		self::assertTrue(
			$schemas['AuditTrail']['x-openregister-lifecycle']['append-only'],
			'AuditTrail must be append-only'
		);
	}//end testLifecyclesAreDeclared()

	/**
	 * EuExpenditure submit/declare transitions reference the EuExpenditureGuard (REQ-EUF-004/005/011).
	 *
	 * @return void
	 */
	public function testEuExpenditureTransitionsReferenceGuard(): void {
		$transitions = $this->fragment()['components']['schemas']['EuExpenditure']['x-openregister-lifecycle']['transitions'];

		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\EuExpenditureGuard::canDeclare',
			$transitions['declare']['requires']
		);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\EuExpenditureGuard::canSubmit',
			$transitions['submit']['requires']
		);
	}//end testEuExpenditureTransitionsReferenceGuard()

	/**
	 * Every required cost-category enum is consistent across the schemas.
	 *
	 * @return void
	 */
	public function testCostCategoryEnumIsConsistent(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$expected = ['personeel', 'kapitaal', 'externe_dienstverlening', 'reis_verblijf', 'indirecte_kosten'];

		self::assertSame($expected, $schemas['EuExpenditure']['properties']['costCategory']['enum']);
		self::assertSame($expected, $schemas['EligibilityRule']['properties']['applicableCostCategories']['items']['enum']);
	}//end testCostCategoryEnumIsConsistent()

	/**
	 * The fragment seeds the four fund eligibility-rules + one sample project.
	 *
	 * @return void
	 */
	public function testFragmentSeedsEligibilityRulesAndProject(): void {
		$objects = $this->fragment()['objects'];
		self::assertIsArray($objects);

		$bySchema = [];
		foreach ($objects as $object) {
			$schema = ($object['@self']['schema'] ?? '');
			$bySchema[$schema] = (($bySchema[$schema] ?? 0) + 1);
		}

		self::assertSame(4, ($bySchema['EligibilityRule'] ?? 0), 'Must seed ERDF/ESF+/JTF/RRF rules');
		self::assertSame(1, ($bySchema['EuProject'] ?? 0), 'Must seed one sample project');

		// The ESF+ rule excludes political campaigns per art 5(2) (REQ-EUF-011 scenario 2).
		$esf = null;
		foreach ($objects as $object) {
			if (($object['@self']['slug'] ?? '') === 'eligibility-esfplus-2021-1057-art5') {
				$esf = $object;
			}
		}

		self::assertNotNull($esf);
		self::assertContains('politieke campagne (art 5(2))', $esf['excludedActivities']);
	}//end testFragmentSeedsEligibilityRulesAndProject()

	/**
	 * Merging the fragment onto the monolith adds the schemas without dropping
	 * any pre-existing monolith schema (ADR-037 additive union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$baseSchemaCount = count($base['components']['schemas']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('EuProject', $schemas);
		self::assertArrayHasKey('AuditTrail', $schemas);

		// No pre-existing monolith schema dropped.
		foreach (array_keys($base['components']['schemas']) as $name) {
			self::assertArrayHasKey($name, $schemas, "Monolith schema $name must survive merge");
		}

		self::assertSame($baseSchemaCount + 7, count($schemas), 'Exactly seven schemas added');
	}//end testFragmentMergesAdditivelyOntoMonolith()
}//end class
