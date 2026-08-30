<?php

/**
 * Fiscal-scoping + audit-trail integration test for member 09 of the
 * bookkeeping-waterschappen-bbv-variant chain.
 *
 * The slice has two cross-cutting deliverables (REQ-BBVW-006 +
 * REQ-BBVW-007). This integration test asserts the static / declarative
 * half of both:
 *
 *   1. Fiscal-year scoping: the BBVProgramme schema carries a non-nullable
 *      `fiscalYear` field, BudgetBBVMapping carries an `effectiveFrom`
 *      half-open window, and Administration carries the
 *      `fiscalYearStartMonth/Day` config the FiscalYearContextService
 *      reads — so an active fiscal year can be resolved server-side.
 *   2. Audit-trail integration: BBVProgramme and BudgetBBVMapping are
 *      registered as ordinary OpenRegister-managed schemas (ADR-022) —
 *      no `x-openregister-audit: false` opt-out and no app-local audit
 *      table — which means OR's immutable audit trail captures
 *      create/update/delete on both registers automatically, as
 *      required by REQ-BBVW-007 (giant D6).
 *
 * No OpenRegister runtime is exercised — the fragment JSON is deep-merged
 * into the base shillinq_register.json the same way SettingsService does
 * at install time, then the resulting OpenAPI components are inspected.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-09-fiscal-audit/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies fiscal-year-scoping fields are present on the BBV schemas and
 * audit-trail capture is not opted out at the schema or register level.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WaterschappenBbv09FiscalAuditIntegrationTest extends TestCase {

	/**
	 * Schemas whose audit-trail integration the slice MUST verify.
	 *
	 * @var array<int,string>
	 */
	private const BBV_SCHEMAS = [
		'BBVProgramme',
		'BudgetBBVMapping',
	];

	/**
	 * Load the base shillinq_register.json + merge every register.d/*.json
	 * fragment exactly the way SettingsService does at install time. Returns
	 * the merged OpenAPI components object.
	 *
	 * @return array<string,mixed>
	 */
	private function loadMergedComponents(): array {
		$basePath = __DIR__ . '/../../lib/Settings/shillinq_register.json';
		$baseRaw = file_get_contents($basePath);
		if ($baseRaw === false) {
			self::fail('Could not read shillinq_register.json base config.');
		}

		$base = json_decode($baseRaw, true);
		if (is_array($base) === false) {
			self::fail('shillinq_register.json base config is not valid JSON.');
		}

		$fragmentDir = __DIR__ . '/../../lib/Settings/register.d';
		$fragments = glob($fragmentDir . '/*.json');
		if ($fragments === false) {
			$fragments = [];
		}

		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragmentRaw = file_get_contents($fragmentPath);
			if ($fragmentRaw === false) {
				continue;
			}

			$fragmentData = json_decode($fragmentRaw, true);
			if (is_array($fragmentData) === false) {
				continue;
			}

			$base = $this->deepMerge($base, $fragmentData);
		}

		$components = ($base['components'] ?? []);
		if (is_array($components) === false) {
			self::fail('Merged register config has no components map.');
		}

		return $components;
	}//end loadMergedComponents()

	/**
	 * Recursive deep-merge used by SettingsService::loadRegisterConfigData.
	 *
	 * @param array<mixed,mixed> $base Base array.
	 * @param array<mixed,mixed> $patch Patch overlaid on top.
	 *
	 * @return array<mixed,mixed>
	 */
	private function deepMerge(array $base, array $patch): array {
		foreach ($patch as $key => $value) {
			if (is_array($value) === true
				&& array_key_exists($key, $base) === true
				&& is_array($base[$key]) === true
			) {
				$base[$key] = $this->deepMerge($base[$key], $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;
	}//end deepMerge()

	/**
	 * The BBVProgramme schema MUST declare a non-nullable integer
	 * fiscalYear property — the implicit fiscal-year scope (REQ-BBVW-006)
	 * is impossible without it.
	 *
	 * @return void
	 */
	public function testBbvProgrammeCarriesFiscalYearProperty(): void {
		$components = $this->loadMergedComponents();
		$schemas = ($components['schemas'] ?? []);

		self::assertArrayHasKey(
			'BBVProgramme',
			$schemas,
			'BBVProgramme schema MUST exist for slice-09 fiscal scoping.'
		);

		$programme = $schemas['BBVProgramme'];
		self::assertArrayHasKey(
			'fiscalYear',
			($programme['properties'] ?? []),
			'BBVProgramme MUST declare a fiscalYear property.'
		);

		$fiscalYear = $programme['properties']['fiscalYear'];
		self::assertSame(
			'integer',
			($fiscalYear['type'] ?? null),
			'fiscalYear MUST be typed as integer.'
		);
		self::assertContains(
			'fiscalYear',
			($programme['required'] ?? []),
			'fiscalYear MUST be required on BBVProgramme.'
		);

	}//end testBbvProgrammeCarriesFiscalYearProperty()

	/**
	 * BudgetBBVMapping has no fiscalYear column but MUST carry the
	 * effective-date window the dashboard uses to derive the year scope.
	 *
	 * @return void
	 */
	public function testBudgetBbvMappingCarriesEffectiveFromWindow(): void {
		$components = $this->loadMergedComponents();
		$schemas = ($components['schemas'] ?? []);

		self::assertArrayHasKey(
			'BudgetBBVMapping',
			$schemas,
			'BudgetBBVMapping schema MUST exist for slice-09 fiscal scoping.'
		);

		$mapping = $schemas['BudgetBBVMapping'];
		$props = ($mapping['properties'] ?? []);
		self::assertArrayHasKey(
			'effectiveFrom',
			$props,
			'BudgetBBVMapping MUST declare an effectiveFrom date window.'
		);

	}//end testBudgetBbvMappingCarriesEffectiveFromWindow()

	/**
	 * Administration MUST carry the fiscalYearStartMonth + Day fields the
	 * FiscalYearContextService reads to derive the active fiscal-year
	 * boundary (REQ-MA-002).
	 *
	 * @return void
	 */
	public function testAdministrationCarriesFiscalYearStartFields(): void {
		$components = $this->loadMergedComponents();
		$schemas = ($components['schemas'] ?? []);

		self::assertArrayHasKey(
			'Administration',
			$schemas,
			'Administration schema MUST exist for slice-09 FY resolution.'
		);

		$admin = $schemas['Administration'];
		$props = ($admin['properties'] ?? []);
		self::assertArrayHasKey(
			'fiscalYearStartMonth',
			$props,
			'Administration MUST declare fiscalYearStartMonth.'
		);
		self::assertArrayHasKey(
			'fiscalYearStartDay',
			$props,
			'Administration MUST declare fiscalYearStartDay.'
		);

	}//end testAdministrationCarriesFiscalYearStartFields()

	/**
	 * Neither BBVProgramme nor BudgetBBVMapping may opt out of OR's
	 * immutable audit trail. The OR default for any registered schema is
	 * "audited" — only an explicit `x-openregister-audit: false` (or an
	 * equivalent register-level toggle) would suppress capture. REQ-BBVW-007
	 * forbids any such opt-out: every CRUD on these registers MUST flow
	 * through the OR audit trail (giant D6 / ADR-022).
	 *
	 * @return void
	 */
	public function testBbvSchemasDoNotOptOutOfAuditTrail(): void {
		$components = $this->loadMergedComponents();
		$schemas = ($components['schemas'] ?? []);

		foreach (self::BBV_SCHEMAS as $schemaName) {
			self::assertArrayHasKey(
				$schemaName,
				$schemas,
				$schemaName . ' schema MUST exist for slice-09 audit verification.'
			);

			$schema = $schemas[$schemaName];

			// OR opt-outs would surface as one of these toggles; their
			// presence with a falsey value is forbidden by REQ-BBVW-007.
			foreach ([
				'x-openregister-audit',
				'x-openregister-audit-trail',
				'x-openregister-immutable-audit',
			] as $optOutKey
			) {
				if (array_key_exists($optOutKey, $schema) === false) {
					continue;
				}

				self::assertNotFalse(
					$schema[$optOutKey],
					$schemaName . ' MUST NOT opt out of the OR audit trail (' . $optOutKey . ' was false).'
				);
			}
		}//end foreach

	}//end testBbvSchemasDoNotOptOutOfAuditTrail()

	/**
	 * The slice MUST NOT have introduced an app-local audit table or
	 * service for BBV registers (giant D6 / ADR-022): OR is the single
	 * source of audit truth. We verify the shipped lib/Service directory
	 * does not contain a BBV-specific *AuditService* and the slice-09
	 * fragment does not declare an app-local *Audit* schema for the BBV
	 * registers.
	 *
	 * @return void
	 */
	public function testSliceDoesNotShipAppLocalAuditService(): void {
		$servicesDir = __DIR__ . '/../../lib/Service';
		$candidates = glob($servicesDir . '/*BBV*Audit*.php');
		if ($candidates === false) {
			$candidates = [];
		}

		self::assertSame(
			[],
			$candidates,
			'Slice 09 MUST NOT introduce an app-local BBV audit service '
			. '(giant D6 / ADR-022).'
		);

		$components = $this->loadMergedComponents();
		$schemas = ($components['schemas'] ?? []);

		// BBV-prefixed *Audit* schemas would be the app-local-audit anti-pattern.
		foreach (array_keys($schemas) as $name) {
			if (is_string($name) === false) {
				continue;
			}

			if (preg_match('/^(?:BBV|BudgetBBV).*Audit/i', $name) === 1) {
				self::fail(
					'Slice 09 MUST NOT introduce an app-local BBV audit '
					. 'schema; the OR immutable audit trail captures CRUD '
					. 'on BBVProgramme + BudgetBBVMapping automatically. '
					. 'Found: ' . $name
				);
			}
		}

	}//end testSliceDoesNotShipAppLocalAuditService()
}//end class
