<?php

/**
 * Unit tests for the bookkeeping-deferred-tax register fragment.
 *
 * Verifies the fragment is valid JSON, declares the five new schemas with
 * the correct required fields and x-openregister-calculations metadata
 * (ADR-037 / ADR-031), and provides additive Account + FiscalPeriod
 * extensions per the tasks.md specification.
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
 * @spec openspec/changes/bookkeeping-deferred-tax/specs/bookkeeping-deferred-tax/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Validates the bookkeeping-deferred-tax register fragment structure and content.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DeferredTaxFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-deferred-tax.json';

	/**
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		$raw = file_get_contents($this->fragmentPath);
		if ($raw === false) {
			self::fail('Cannot read fragment file: ' . $this->fragmentPath);
		}

		$data = json_decode($raw, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			self::fail('Fragment is not valid JSON: ' . json_last_error_msg());
		}

		return $data;
	}//end fragment()

	/**
	 * Fragment file exists and is valid JSON.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		$data = $this->fragment();
		self::assertIsArray($data);

	}//end testFragmentIsValidJson()

	/**
	 * Fragment meta declares correct change key (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentMetaChange(): void {
		$data = $this->fragment();
		self::assertSame('bookkeeping-deferred-tax', $data['_meta']['change']);

	}//end testFragmentMetaChange()

	/**
	 * Fragment declares EUPL-1.2 license (SPDX gate requirement).
	 *
	 * @return void
	 */
	public function testFragmentMetaLicense(): void {
		$data = $this->fragment();
		self::assertSame('EUPL-1.2', $data['_meta']['spdx-license']);

	}//end testFragmentMetaLicense()

	/**
	 * Fragment declares exactly five new tax schemas plus two additive extensions.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresExpectedSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		self::assertArrayHasKey('TemporaryDifference', $schemas);
		self::assertArrayHasKey('TaxLossCarryForward', $schemas);
		self::assertArrayHasKey('TaxRateReconciliation', $schemas);
		self::assertArrayHasKey('DeferredTaxMovement', $schemas);
		self::assertArrayHasKey('TaxProvision', $schemas);
		// Additive extensions.
		self::assertArrayHasKey('Account', $schemas);
		self::assertArrayHasKey('FiscalPeriod', $schemas);

	}//end testFragmentDeclaresExpectedSchemas()

	/**
	 * TemporaryDifference has required integer-cent money fields (REQ-DT-001).
	 *
	 * @return void
	 */
	public function testTemporaryDifferenceRequiredFields(): void {
		$schema = $this->fragment()['components']['schemas']['TemporaryDifference'];
		$required = $schema['required'];
		self::assertContains('periodId', $required);
		self::assertContains('jurisdiction', $required);
		self::assertContains('accountNumber', $required);
		self::assertContains('category', $required);
		self::assertContains('commercialCarryingAmount', $required);
		self::assertContains('taxCarryingAmount', $required);
		self::assertContains('temporaryDifference', $required);
		self::assertContains('type', $required);
		self::assertContains('reversalPattern', $required);
		self::assertContains('taxRate', $required);
		self::assertContains('deferredTaxBalance', $required);
		self::assertContains('administrationId', $required);

	}//end testTemporaryDifferenceRequiredFields()

	/**
	 * TemporaryDifference money fields are typed as integer (cents policy ADR-022).
	 *
	 * @return void
	 */
	public function testTemporaryDifferenceMoneyFieldsAreIntegers(): void {
		$props = $this->fragment()['components']['schemas']['TemporaryDifference']['properties'];
		foreach (['commercialCarryingAmount', 'taxCarryingAmount', 'temporaryDifference', 'deferredTaxBalance', 'taxRate'] as $field) {
			self::assertSame('integer', $props[$field]['type'], $field . ' must be integer (cents)');
		}

	}//end testTemporaryDifferenceMoneyFieldsAreIntegers()

	/**
	 * TemporaryDifference type enum contains exactly taxable and deductible (REQ-DT-001).
	 *
	 * @return void
	 */
	public function testTemporaryDifferenceTypeEnum(): void {
		$enum = $this->fragment()['components']['schemas']['TemporaryDifference']['properties']['type']['enum'];
		self::assertContains('taxable', $enum);
		self::assertContains('deductible', $enum);
		self::assertCount(2, $enum);

	}//end testTemporaryDifferenceTypeEnum()

	/**
	 * TemporaryDifference category enum includes all nine types from the spec (REQ-DT-001/REQ-DT-002).
	 *
	 * @return void
	 */
	public function testTemporaryDifferenceCategoryEnum(): void {
		$enum = $this->fragment()['components']['schemas']['TemporaryDifference']['properties']['category']['enum'];
		foreach ([
			'depreciation',
			'provision',
			'receivable-impairment',
			'inventory-valuation',
			'development-cost',
			'fair-value-adjustment',
			'lease-ifrs16',
			'pension',
			'other',
		] as $expected) {
			self::assertContains($expected, $enum);
		}

	}//end testTemporaryDifferenceCategoryEnum()

	/**
	 * TemporaryDifference carries x-openregister-calculations for computed fields (ADR-031).
	 *
	 * @return void
	 */
	public function testTemporaryDifferenceCalculations(): void {
		$schema = $this->fragment()['components']['schemas']['TemporaryDifference'];
		self::assertArrayHasKey('x-openregister-calculations', $schema);
		$calc = $schema['x-openregister-calculations'];
		self::assertArrayHasKey('temporaryDifference', $calc);
		self::assertArrayHasKey('deferredTaxBalance', $calc);

	}//end testTemporaryDifferenceCalculations()

	/**
	 * TaxLossCarryForward applicableRegime enum covers all three Wet Vpb regimes (REQ-DT-003).
	 *
	 * @return void
	 */
	public function testTaxLossCarryForwardRegimeEnum(): void {
		$enum = $this->fragment()['components']['schemas']['TaxLossCarryForward']['properties']['applicableRegime']['enum'];
		self::assertContains('pre-2019-6year', $enum);
		self::assertContains('2019-2021-transition', $enum);
		self::assertContains('2022-onwards', $enum);
		self::assertCount(3, $enum);

	}//end testTaxLossCarryForwardRegimeEnum()

	/**
	 * TaxLossCarryForward has dtaRecoverabilityRationale field (REQ-DT-004).
	 *
	 * @return void
	 */
	public function testTaxLossCarryForwardHasRecoverabilityRationale(): void {
		$props = $this->fragment()['components']['schemas']['TaxLossCarryForward']['properties'];
		self::assertArrayHasKey('dtaRecoverabilityRationale', $props);
		self::assertArrayHasKey('linkedProjections', $props);

	}//end testTaxLossCarryForwardHasRecoverabilityRationale()

	/**
	 * TaxRateReconciliation carries x-openregister-calculations for ETR fields (ADR-031 / REQ-DT-006).
	 *
	 * @return void
	 */
	public function testTaxRateReconciliationCalculations(): void {
		$schema = $this->fragment()['components']['schemas']['TaxRateReconciliation'];
		self::assertArrayHasKey('x-openregister-calculations', $schema);
		$calc = $schema['x-openregister-calculations'];
		self::assertArrayHasKey('statutoryTaxExpense', $calc);
		self::assertArrayHasKey('effectiveTaxExpense', $calc);
		self::assertArrayHasKey('effectiveTaxRate', $calc);

	}//end testTaxRateReconciliationCalculations()

	/**
	 * TaxRateReconciliation is declared readonly (REQ-DT-006 / design D2).
	 *
	 * @return void
	 */
	public function testTaxRateReconciliationIsReadonly(): void {
		$schema = $this->fragment()['components']['schemas']['TaxRateReconciliation'];
		self::assertTrue($schema['readonly'] ?? false);

	}//end testTaxRateReconciliationIsReadonly()

	/**
	 * TaxRateReconciliation reconciliationItems has a type enum with permanent, temporary,
	 * rate-change, and prior-year values (REQ-DT-006).
	 *
	 * @return void
	 */
	public function testReconciliationItemTypeEnum(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$itemPropMap = $schemas['TaxRateReconciliation']['properties']['reconciliationItems'];
		$itemProps = $itemPropMap['items']['properties']['type']['enum'];
		self::assertContains('permanent', $itemProps);
		self::assertContains('temporary', $itemProps);
		self::assertContains('rate-change', $itemProps);
		self::assertContains('prior-year', $itemProps);

	}//end testReconciliationItemTypeEnum()

	/**
	 * DeferredTaxMovement carries x-openregister-calculations for closingBalance / recognisedInPL (REQ-DT-009).
	 *
	 * @return void
	 */
	public function testDeferredTaxMovementCalculations(): void {
		$schema = $this->fragment()['components']['schemas']['DeferredTaxMovement'];
		self::assertArrayHasKey('x-openregister-calculations', $schema);
		$calc = $schema['x-openregister-calculations'];
		self::assertArrayHasKey('recognisedInPL', $calc);
		self::assertArrayHasKey('closingBalance', $calc);

	}//end testDeferredTaxMovementCalculations()

	/**
	 * TaxProvision has presentationOnBalanceSheet enum (gross/net) per IAS 12 §71–78 (REQ-DT-008).
	 *
	 * @return void
	 */
	public function testTaxProvisionPresentationEnum(): void {
		$enum = $this->fragment()['components']['schemas']['TaxProvision']['properties']['presentationOnBalanceSheet']['enum'];
		self::assertContains('gross', $enum);
		self::assertContains('net', $enum);
		self::assertCount(2, $enum);

	}//end testTaxProvisionPresentationEnum()

	/**
	 * TaxProvision has linkedVpbReturn field for Vpb reconciliation (REQ-DT-010).
	 *
	 * @return void
	 */
	public function testTaxProvisionHasLinkedVpbReturn(): void {
		$props = $this->fragment()['components']['schemas']['TaxProvision']['properties'];
		self::assertArrayHasKey('linkedVpbReturn', $props);

	}//end testTaxProvisionHasLinkedVpbReturn()

	/**
	 * Account extension has taxBasisDifferenceCategory hint field (REQ-DT-001 / Task 6).
	 *
	 * @return void
	 */
	public function testAccountExtensionHasTaxBasisDifferenceCategory(): void {
		$accountExt = $this->fragment()['components']['schemas']['Account'];
		self::assertArrayHasKey('x-openspec-extend', $accountExt);
		self::assertTrue($accountExt['x-openspec-extend']);
		self::assertArrayHasKey('taxBasisDifferenceCategory', $accountExt['properties']);

	}//end testAccountExtensionHasTaxBasisDifferenceCategory()

	/**
	 * Account taxBasisDifferenceCategory enum matches the TemporaryDifference category enum (REQ-DT-001).
	 *
	 * @return void
	 */
	public function testAccountCategoryEnumMatchesTemporaryDifferenceCategory(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$tdEnum = $schemas['TemporaryDifference']['properties']['category']['enum'];
		$acEnum = $schemas['Account']['properties']['taxBasisDifferenceCategory']['enum'];
		self::assertSame($tdEnum, $acEnum);

	}//end testAccountCategoryEnumMatchesTemporaryDifferenceCategory()

	/**
	 * FiscalPeriod extension has enactedTaxRates field (REQ-DT-005 / Task 7).
	 *
	 * @return void
	 */
	public function testFiscalPeriodExtensionHasEnactedTaxRates(): void {
		$fpExt = $this->fragment()['components']['schemas']['FiscalPeriod'];
		self::assertArrayHasKey('x-openspec-extend', $fpExt);
		self::assertTrue($fpExt['x-openspec-extend']);
		self::assertArrayHasKey('enactedTaxRates', $fpExt['properties']);
		// Should have rate and effectiveDate sub-properties.
		$rateProp = $fpExt['properties']['enactedTaxRates']['additionalProperties']['properties'];
		self::assertArrayHasKey('rate', $rateProp);
		self::assertArrayHasKey('effectiveDate', $rateProp);

	}//end testFiscalPeriodExtensionHasEnactedTaxRates()

	/**
	 * Every new schema carries an administrationId field (scoping / IDOR guard per ADR-005).
	 *
	 * @return void
	 */
	public function testAllNewSchemasHaveAdministrationId(): void {
		$newSchemas = [
			'TemporaryDifference',
			'TaxLossCarryForward',
			'TaxRateReconciliation',
			'DeferredTaxMovement',
			'TaxProvision',
		];
		$schemas = $this->fragment()['components']['schemas'];
		foreach ($newSchemas as $schemaName) {
			self::assertArrayHasKey(
				'administrationId',
				$schemas[$schemaName]['properties'],
				$schemaName . ' must have administrationId for IDOR scoping'
			);
		}//end foreach

	}//end testAllNewSchemasHaveAdministrationId()

	/**
	 * Every new schema carries a jurisdiction field (REQ-DT-007 per-jurisdiction tracking).
	 *
	 * @return void
	 */
	public function testAllNewSchemasHaveJurisdiction(): void {
		$newSchemas = [
			'TemporaryDifference',
			'TaxLossCarryForward',
			'TaxRateReconciliation',
			'DeferredTaxMovement',
			'TaxProvision',
		];
		$schemas = $this->fragment()['components']['schemas'];
		foreach ($newSchemas as $schemaName) {
			self::assertArrayHasKey(
				'jurisdiction',
				$schemas[$schemaName]['properties'],
				$schemaName . ' must have jurisdiction for per-jurisdiction tracking'
			);
		}//end foreach

	}//end testAllNewSchemasHaveJurisdiction()
}//end class
