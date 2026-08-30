<?php

/**
 * Validation-rules integration test for member 03 of the
 * bookkeeping-waterschappen-bbv-variant chain.
 *
 * Loads the slice-03 register fragment via the same deep-merge that
 * SettingsService runs at install time, asserts that the declarative
 * validation block on BBVProgramme and BudgetBBVMapping materialises with
 * the constraints REQ-BBVW-008 / giant Phase 4 require, and then evaluates
 * the rules against a small in-process fixture set to verify:
 *
 *  - BBVProgramme programmeCode regex accepts "1.1" and "2.3.2" and rejects
 *    "1-2-3" (the scenario in spec.md).
 *  - BBVProgramme programmeName length is bounded at 255 characters.
 *  - BBVProgramme fiscalYear bounds reject 1899 and 2101.
 *  - BBVProgramme programmeCode uniqueness rule rejects a second record
 *    sharing (administrationId, fiscalYear, programmeCode).
 *  - BudgetBBVMapping glAccountNumber FK rule rejects mappings that
 *    reference an unknown GL account.
 *  - BudgetBBVMapping effectiveTo cross-field validator rejects an end
 *    date earlier than effectiveFrom (the scenario in spec.md).
 *  - BudgetBBVMapping allocationSumPerAccount rule rejects a mapping that
 *    would push the per-(administrationId, glAccountNumber, fiscalYear)
 *    sum above 100% (the over-allocation scenario in spec.md), while
 *    accepting sums in the 99.9–100.1% tolerance band.
 *
 * The OpenRegister runtime is not invoked — the fragment JSON is the
 * source of truth, and the rule semantics are evaluated in-process by
 * helper methods that mirror what the OpenRegister save pipeline MUST do.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-03-validation-rules/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the slice-03 validation block materialises on BBVProgramme +
 * BudgetBBVMapping and that the declared rules accept valid records and
 * reject the invariants the spec.md scenarios require.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WaterschappenBbv03ValidationRulesIntegrationTest extends TestCase {

	/**
	 * Programme-code regex carried from the slice-03 spec (REQ-BBVW-008).
	 *
	 * @var string
	 */
	private const PROGRAMME_CODE_REGEX = '/^\d+\.\d+(\.\d+)?$/';

	/**
	 * Tolerance band for the per-account allocation sum rule. Sums above
	 * 100 + this value SHALL be rejected; sums within ±this of 100 are
	 * accepted as the "rounded to 100%" case.
	 *
	 * @var float
	 */
	private const ALLOCATION_TOLERANCE = 0.1;

	/**
	 * Load the base shillinq_register.json + merge every register.d/*.json
	 * fragment exactly the way SettingsService does at install time. Returns
	 * the merged OpenAPI components object.
	 *
	 * @return array<string,mixed>
	 */
	private function loadMergedComponents(): array {
		$basePath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';
		$baseRaw = file_get_contents($basePath);
		if ($baseRaw === false) {
			self::fail('Could not read shillinq_register.json base config.');
		}

		$base = json_decode($baseRaw, true);
		if (is_array($base) === false) {
			self::fail('shillinq_register.json base config is not valid JSON.');
		}

		$fragmentDir = __DIR__ . '/../../../lib/Settings/register.d';
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

			$base = self::deepMerge(base: $base, overlay: $fragmentData);
		}

		return ($base['components'] ?? []);
	}//end loadMergedComponents()

	/**
	 * Deep-merge an overlay onto a base; mirror of
	 * SettingsService::deepMergeConfig (assoc arrays merge by key, list
	 * arrays concatenate, scalars overwrite).
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge.
	 *
	 * @return array<mixed>
	 */
	private static function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
			) {
				$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
				$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
				if ($baseIsList === true && $overlayIsList === true) {
					$base[$key] = array_merge($base[$key], $value);
				} else {
					$base[$key] = self::deepMerge(base: $base[$key], overlay: $value);
				}
			} else {
				$base[$key] = $value;
			}
		}

		return $base;
	}//end deepMerge()

	/**
	 * The slice-03 fragment SHALL materialise programmeName length bounds,
	 * programmeCode regex, fiscalYear integer bounds, and the
	 * programmeCodeUniquePerYear validation rule on BBVProgramme.
	 *
	 * @return void
	 */
	public function testProgrammeValidationBlockMaterialises(): void {
		$components = $this->loadMergedComponents();
		self::assertArrayHasKey('schemas', $components);
		self::assertArrayHasKey('BBVProgramme', $components['schemas']);

		$programme = $components['schemas']['BBVProgramme'];
		self::assertArrayHasKey('properties', $programme);

		// ProgrammeName: required + non-empty + capped at 255 chars (REQ-BBVW-008).
		$name = $programme['properties']['programmeName'];
		self::assertSame(255, $name['maxLength']);
		self::assertSame(1, $name['minLength']);

		// ProgrammeCode: required + regex + non-empty (REQ-BBVW-008).
		$code = $programme['properties']['programmeCode'];
		self::assertSame('^\\d+\\.\\d+(\\.\\d+)?$', $code['pattern']);
		self::assertSame(1, $code['minLength']);

		// FiscalYear: integer 1900-2100 per REQ-BBVW-008.
		$year = $programme['properties']['fiscalYear'];
		self::assertSame('integer', $year['type']);
		self::assertSame(1900, $year['minimum']);
		self::assertSame(2100, $year['maximum']);

		// Status enum is owned by slice 02 (draft|active|archived) — assert
		// the enum still contains active + archived as REQ-BBVW-008 requires.
		self::assertContains('active', $programme['properties']['status']['enum']);
		self::assertContains('archived', $programme['properties']['status']['enum']);

		// Cross-record uniqueness rule.
		self::assertArrayHasKey('x-openregister-validation', $programme);
		self::assertArrayHasKey(
			'programmeCodeUniquePerYear',
			$programme['x-openregister-validation']
		);
		$rule = $programme['x-openregister-validation']['programmeCodeUniquePerYear'];
		self::assertSame('unique', $rule['rule']);
		self::assertSame(
			['administrationId', 'fiscalYear', 'programmeCode'],
			$rule['fields']
		);

	}//end testProgrammeValidationBlockMaterialises()

	/**
	 * The slice-03 fragment SHALL materialise glAccountNumber FK existence,
	 * allocationPercentage 0–100 with multipleOf 0.01, effectiveFrom +
	 * effectiveTo ISO-date patterns, the effectiveToOnOrAfterFrom
	 * cross-field validator, and the allocationSumPerAccount rule on
	 * BudgetBBVMapping.
	 *
	 * @return void
	 */
	public function testMappingValidationBlockMaterialises(): void {
		$components = $this->loadMergedComponents();
		self::assertArrayHasKey('BudgetBBVMapping', $components['schemas']);

		$mapping = $components['schemas']['BudgetBBVMapping'];

		// AllocationPercentage: number 0-100 with 0.01 precision (REQ-BBVW-008).
		$allocation = $mapping['properties']['allocationPercentage'];
		self::assertSame('number', $allocation['type']);
		self::assertSame(0, $allocation['minimum']);
		self::assertSame(100, $allocation['maximum']);
		self::assertSame(0.01, $allocation['multipleOf']);

		// EffectiveFrom + effectiveTo: ISO-8601 date pattern (REQ-BBVW-008).
		self::assertSame('^\\d{4}-\\d{2}-\\d{2}$', $mapping['properties']['effectiveFrom']['pattern']);
		self::assertSame('^\\d{4}-\\d{2}-\\d{2}$', $mapping['properties']['effectiveTo']['pattern']);
		self::assertTrue(($mapping['properties']['effectiveTo']['nullable'] ?? false));

		// Cross-field date-order validator.
		self::assertArrayHasKey('x-openregister-calculations', $mapping);
		self::assertArrayHasKey(
			'effectiveToOnOrAfterFrom',
			$mapping['x-openregister-calculations']
		);

		// FK existence + sum invariant.
		self::assertArrayHasKey('x-openregister-validation', $mapping);
		$validation = $mapping['x-openregister-validation'];

		self::assertArrayHasKey('glAccountForeignKey', $validation);
		$fk = $validation['glAccountForeignKey'];
		self::assertSame('foreignKeyExists', $fk['rule']);
		self::assertSame('glAccountNumber', $fk['localField']);
		self::assertSame('Account', $fk['foreignSchema']);
		self::assertSame('accountNumber', $fk['foreignField']);
		self::assertSame(['administrationId'], $fk['scopeFields']);

		self::assertArrayHasKey('allocationSumPerAccount', $validation);
		$sumRule = $validation['allocationSumPerAccount'];
		self::assertSame('sumWithinBounds', $sumRule['rule']);
		self::assertSame('allocationPercentage', $sumRule['sumField']);
		self::assertSame(
			['administrationId', 'glAccountNumber', 'fiscalYear'],
			$sumRule['groupBy']
		);
		self::assertSame(100, $sumRule['maximum']);
		self::assertSame(self::ALLOCATION_TOLERANCE, $sumRule['tolerance']);

	}//end testMappingValidationBlockMaterialises()

	/**
	 * The programmeCode regex SHALL accept dotted-numeric codes ("1.1",
	 * "2.3.2") and reject hyphenated codes ("1-2-3"). Carries the spec.md
	 * scenario "Programme with an invalid code is rejected".
	 *
	 * @return void
	 */
	public function testProgrammeCodeRegexAcceptsValidAndRejectsInvalid(): void {
		$validCodes = ['1.1', '2.3.2', '10.20.30'];
		foreach ($validCodes as $code) {
			self::assertSame(
				1,
				preg_match(self::PROGRAMME_CODE_REGEX, $code),
				'programmeCode "' . $code . '" must satisfy the REQ-BBVW-008 regex.'
			);
		}

		$invalidCodes = ['1-2-3', 'abc', '1', '1.', '.1', '1.a.2', '1..2'];
		foreach ($invalidCodes as $code) {
			self::assertSame(
				0,
				preg_match(self::PROGRAMME_CODE_REGEX, $code),
				'programmeCode "' . $code . '" must be rejected by the REQ-BBVW-008 regex.'
			);
		}

	}//end testProgrammeCodeRegexAcceptsValidAndRejectsInvalid()

	/**
	 * Programme uniqueness rule: a second record sharing the
	 * (administrationId, fiscalYear, programmeCode) tuple SHALL be rejected.
	 *
	 * @return void
	 */
	public function testProgrammeCodeUniquenessRule(): void {
		$existing = [
			['administrationId' => 'adm-waterschap-1', 'fiscalYear' => 2026, 'programmeCode' => '2.3.2'],
			['administrationId' => 'adm-waterschap-1', 'fiscalYear' => 2026, 'programmeCode' => '1.1'],
			['administrationId' => 'adm-waterschap-1', 'fiscalYear' => 2025, 'programmeCode' => '2.3.2'],
		];

		// Same admin + fiscalYear + code → duplicate.
		$duplicate = ['administrationId' => 'adm-waterschap-1', 'fiscalYear' => 2026, 'programmeCode' => '2.3.2'];
		self::assertFalse($this->isUniqueProgramme($duplicate, $existing));

		// Same admin + fiscalYear + different code → unique.
		$newCode = ['administrationId' => 'adm-waterschap-1', 'fiscalYear' => 2026, 'programmeCode' => '3.1.1'];
		self::assertTrue($this->isUniqueProgramme($newCode, $existing));

		// Same code but different fiscalYear → unique (year-scoped).
		$nextYear = ['administrationId' => 'adm-waterschap-1', 'fiscalYear' => 2027, 'programmeCode' => '2.3.2'];
		self::assertTrue($this->isUniqueProgramme($nextYear, $existing));

		// Same code but different administration → unique (admin-scoped).
		$otherAdmin = ['administrationId' => 'adm-waterschap-2', 'fiscalYear' => 2026, 'programmeCode' => '2.3.2'];
		self::assertTrue($this->isUniqueProgramme($otherAdmin, $existing));

	}//end testProgrammeCodeUniquenessRule()

	/**
	 * Fiscal-year bounds SHALL reject < 1900 and > 2100.
	 *
	 * @return void
	 */
	public function testFiscalYearBoundsRejectOutOfRange(): void {
		$components = $this->loadMergedComponents();
		$year = $components['schemas']['BBVProgramme']['properties']['fiscalYear'];

		$belowBound = 1899;
		$aboveBound = 2101;
		$atLowerEdge = 1900;
		$atUpperEdge = 2100;

		self::assertTrue($belowBound < $year['minimum']);
		self::assertTrue($aboveBound > $year['maximum']);
		self::assertTrue($atLowerEdge >= $year['minimum']);
		self::assertTrue($atUpperEdge <= $year['maximum']);

	}//end testFiscalYearBoundsRejectOutOfRange()

	/**
	 * ProgrammeName SHALL be capped at 255 chars and reject the empty
	 * string per REQ-BBVW-008.
	 *
	 * @return void
	 */
	public function testProgrammeNameLengthBounds(): void {
		$components = $this->loadMergedComponents();
		$name = $components['schemas']['BBVProgramme']['properties']['programmeName'];

		$emptyString = '';
		self::assertTrue(strlen($emptyString) < $name['minLength']);

		$maxValid = str_repeat('a', 255);
		$tooLong = str_repeat('a', 256);
		self::assertTrue(strlen($maxValid) <= $name['maxLength']);
		self::assertTrue(strlen($tooLong) > $name['maxLength']);

	}//end testProgrammeNameLengthBounds()

	/**
	 * EffectiveTo SHALL be on or after effectiveFrom; otherwise the
	 * cross-field validator rejects the record. Carries the spec.md
	 * scenario "Mapping with an end date before the start date is
	 * rejected".
	 *
	 * @return void
	 */
	public function testEffectiveToOnOrAfterFrom(): void {
		// Valid: open-ended (effectiveTo null).
		self::assertTrue($this->isEffectivePeriodValid('2026-01-01', null));

		// Valid: same day.
		self::assertTrue($this->isEffectivePeriodValid('2026-01-01', '2026-01-01'));

		// Valid: end after start.
		self::assertTrue($this->isEffectivePeriodValid('2026-01-01', '2026-12-31'));

		// Invalid: spec.md scenario — end before start.
		self::assertFalse($this->isEffectivePeriodValid('2026-06-01', '2026-01-01'));

	}//end testEffectiveToOnOrAfterFrom()

	/**
	 * GlAccountForeignKey rule SHALL reject mappings that reference an
	 * unknown GL account within the same administration, and SHALL accept
	 * mappings that reference a known GL account.
	 *
	 * @return void
	 */
	public function testGlAccountForeignKeyExistence(): void {
		$chartOfAccounts = [
			['administrationId' => 'adm-waterschap-1', 'accountNumber' => '4100'],
			['administrationId' => 'adm-waterschap-1', 'accountNumber' => '5000'],
			['administrationId' => 'adm-waterschap-2', 'accountNumber' => '4100'],
		];

		// Known account in the same admin → valid.
		self::assertTrue(
			$this->isGlAccountKnown(
				['administrationId' => 'adm-waterschap-1', 'glAccountNumber' => '4100'],
				$chartOfAccounts
			)
		);

		// Unknown account → rejected.
		self::assertFalse(
			$this->isGlAccountKnown(
				['administrationId' => 'adm-waterschap-1', 'glAccountNumber' => '9999'],
				$chartOfAccounts
			)
		);

		// Account exists in a different admin → rejected (admin-scoped FK).
		self::assertFalse(
			$this->isGlAccountKnown(
				['administrationId' => 'adm-waterschap-1', 'glAccountNumber' => '5000'],
				[['administrationId' => 'adm-waterschap-2', 'accountNumber' => '5000']]
			)
		);

	}//end testGlAccountForeignKeyExistence()

	/**
	 * Per-account allocation sum rule SHALL reject a new mapping that
	 * would push the per-(administrationId, glAccountNumber, fiscalYear)
	 * sum above the 100% + tolerance ceiling. Carries the spec.md scenario
	 * "Over-allocation is rejected".
	 *
	 * @return void
	 */
	public function testAllocationSumOverHundredIsRejected(): void {
		// Spec.md scenario: GL 4100 already at 90% in FY2026, new 15%
		// mapping would push to 105% — must be rejected.
		$existing = [
			[
				'administrationId' => 'adm-waterschap-1',
				'glAccountNumber' => '4100',
				'fiscalYear' => 2026,
				'allocationPercentage' => 90.0,
			],
		];
		$newMapping = [
			'administrationId' => 'adm-waterschap-1',
			'glAccountNumber' => '4100',
			'fiscalYear' => 2026,
			'allocationPercentage' => 15.0,
		];

		self::assertFalse(
			$this->isAllocationSumWithinBounds($newMapping, $existing),
			'Adding 15% to a GL account already at 90% must be rejected (would be 105%).'
		);

		// Sums in [0, 99.9) are accepted (work-in-progress).
		$partialNew = [
			'administrationId' => 'adm-waterschap-1',
			'glAccountNumber' => '4100',
			'fiscalYear' => 2026,
			'allocationPercentage' => 5.0,
		];
		self::assertTrue(
			$this->isAllocationSumWithinBounds($partialNew, $existing),
			'Adding 5% to a GL account at 90% must be accepted (95% total, < 100).'
		);

		// Sums in [99.9, 100.1] are accepted (rounding tolerance).
		$exactComplement = [
			'administrationId' => 'adm-waterschap-1',
			'glAccountNumber' => '4100',
			'fiscalYear' => 2026,
			'allocationPercentage' => 10.0,
		];
		self::assertTrue(
			$this->isAllocationSumWithinBounds($exactComplement, $existing),
			'Adding 10% to a GL account at 90% must be accepted (100% total, == max).'
		);

		// Boundary at the upper tolerance edge: 100.1% accepted.
		$upperTolerance = [
			'administrationId' => 'adm-waterschap-1',
			'glAccountNumber' => '4100',
			'fiscalYear' => 2026,
			'allocationPercentage' => 10.1,
		];
		self::assertTrue(
			$this->isAllocationSumWithinBounds($upperTolerance, $existing),
			'Adding 10.1% to a GL account at 90% must be accepted (100.1% total, at tolerance ceiling).'
		);

		// Just above tolerance: 100.11% rejected.
		$justOverTolerance = [
			'administrationId' => 'adm-waterschap-1',
			'glAccountNumber' => '4100',
			'fiscalYear' => 2026,
			'allocationPercentage' => 10.11,
		];
		self::assertFalse(
			$this->isAllocationSumWithinBounds($justOverTolerance, $existing),
			'Adding 10.11% to a GL account at 90% must be rejected (100.11% > 100.1).'
		);

		// Sum across multiple existing mappings on the same GL account.
		$multiExisting = [
			['administrationId' => 'adm-waterschap-1', 'glAccountNumber' => '5000', 'fiscalYear' => 2026, 'allocationPercentage' => 40.0],
			['administrationId' => 'adm-waterschap-1', 'glAccountNumber' => '5000', 'fiscalYear' => 2026, 'allocationPercentage' => 30.0],
			['administrationId' => 'adm-waterschap-1', 'glAccountNumber' => '5000', 'fiscalYear' => 2026, 'allocationPercentage' => 20.0],
		];
		// 40 + 30 + 20 = 90; add 11% → 101% → rejected.
		$multiNew = ['administrationId' => 'adm-waterschap-1', 'glAccountNumber' => '5000', 'fiscalYear' => 2026, 'allocationPercentage' => 11.0];
		self::assertFalse($this->isAllocationSumWithinBounds($multiNew, $multiExisting));

		// Same GL account number but different administrationId → sum is
		// scoped per admin, so the new mapping must not be counted against
		// the other admin's total.
		$crossAdminExisting = [
			['administrationId' => 'adm-waterschap-2', 'glAccountNumber' => '4100', 'fiscalYear' => 2026, 'allocationPercentage' => 95.0],
		];
		$crossAdminNew = [
			'administrationId' => 'adm-waterschap-1',
			'glAccountNumber' => '4100',
			'fiscalYear' => 2026,
			'allocationPercentage' => 50.0,
		];
		self::assertTrue(
			$this->isAllocationSumWithinBounds($crossAdminNew, $crossAdminExisting),
			'Allocation sum is scoped per administrationId (REQ-BBVW-008).'
		);

		// Same GL account but different fiscalYear → scoped per year.
		$crossYearExisting = [
			['administrationId' => 'adm-waterschap-1', 'glAccountNumber' => '4100', 'fiscalYear' => 2025, 'allocationPercentage' => 95.0],
		];
		$crossYearNew = [
			'administrationId' => 'adm-waterschap-1',
			'glAccountNumber' => '4100',
			'fiscalYear' => 2026,
			'allocationPercentage' => 50.0,
		];
		self::assertTrue(
			$this->isAllocationSumWithinBounds($crossYearNew, $crossYearExisting),
			'Allocation sum is scoped per fiscalYear (REQ-BBVW-008).'
		);

	}//end testAllocationSumOverHundredIsRejected()

	/**
	 * Evaluate the programmeCodeUniquePerYear declarative rule against an
	 * in-process set of existing programme records. Returns true when the
	 * proposed record is unique within (administrationId, fiscalYear,
	 * programmeCode), false otherwise.
	 *
	 * @param array<string,mixed> $proposed The proposed BBVProgramme record.
	 * @param array<int,array<string,mixed>> $existing The existing BBVProgramme records.
	 *
	 * @return bool
	 */
	private function isUniqueProgramme(array $proposed, array $existing): bool {
		foreach ($existing as $row) {
			if (((string)$row['administrationId']) === ((string)$proposed['administrationId'])
				&& ((int)$row['fiscalYear']) === ((int)$proposed['fiscalYear'])
				&& ((string)$row['programmeCode']) === ((string)$proposed['programmeCode'])
			) {
				return false;
			}
		}

		return true;
	}//end isUniqueProgramme()

	/**
	 * Evaluate the effectiveToOnOrAfterFrom cross-field validator. Returns
	 * true when effectiveTo is null or ≥ effectiveFrom.
	 *
	 * @param string $effectiveFrom The mapping's effectiveFrom date.
	 * @param string|null $effectiveTo The mapping's effectiveTo date (may be null).
	 *
	 * @return bool
	 */
	private function isEffectivePeriodValid(string $effectiveFrom, ?string $effectiveTo): bool {
		if ($effectiveTo === null) {
			return true;
		}

		return $effectiveTo >= $effectiveFrom;
	}//end isEffectivePeriodValid()

	/**
	 * Evaluate the glAccountForeignKey rule. Returns true when the
	 * proposed mapping's glAccountNumber exists in the chart of accounts
	 * within the same administrationId.
	 *
	 * @param array<string,mixed> $proposed The proposed BudgetBBVMapping record.
	 * @param array<int,array<string,mixed>> $chart The Chart-of-Accounts records.
	 *
	 * @return bool
	 */
	private function isGlAccountKnown(array $proposed, array $chart): bool {
		foreach ($chart as $row) {
			if (((string)$row['administrationId']) === ((string)$proposed['administrationId'])
				&& ((string)$row['accountNumber']) === ((string)$proposed['glAccountNumber'])
			) {
				return true;
			}
		}

		return false;
	}//end isGlAccountKnown()

	/**
	 * Evaluate the allocationSumPerAccount rule. Returns true when the
	 * proposed mapping's allocationPercentage, added to the sum of all
	 * existing mappings sharing the same (administrationId, glAccountNumber,
	 * fiscalYear), is ≤ 100 + ALLOCATION_TOLERANCE.
	 *
	 * @param array<string,mixed> $proposed The proposed BudgetBBVMapping record.
	 * @param array<int,array<string,mixed>> $existing All existing BudgetBBVMapping records.
	 *
	 * @return bool
	 */
	private function isAllocationSumWithinBounds(array $proposed, array $existing): bool {
		$siblings = array_filter(
			$existing,
			static function (array $row) use ($proposed): bool {
				return ((string)$row['administrationId']) === ((string)$proposed['administrationId'])
					&& ((string)$row['glAccountNumber']) === ((string)$proposed['glAccountNumber'])
					&& ((int)$row['fiscalYear']) === ((int)$proposed['fiscalYear']);
			}
		);

		$sum = (float)$proposed['allocationPercentage'];
		foreach ($siblings as $row) {
			$sum += (float)$row['allocationPercentage'];
		}

		return $sum <= (100 + self::ALLOCATION_TOLERANCE);
	}//end isAllocationSumWithinBounds()
}//end class
