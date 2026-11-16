<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\NationalExtraTailChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the statement-model, inventory, filing-deadline, e-invoicing mandate
 * and SAF-T branches of the remaining national checks.
 *
 * @covers \OCA\Shillinq\Standards\Checks\NationalExtraTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class NationalExtraTailChecksBehaviourTest extends TestCase {

	/**
	 * Objects, the rule they are checked against and the expected verdict.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: bool}>
	 */
	public static function cases(): array {
		$fs = 'FinancialStatement';
		$em = 'EInvoiceMandate';
		$sp = 'SaftExportProfile';
		$bs = ['balanceSheet' => ['fixedAssets' => 1, 'currentAssets' => 2, 'equity' => 3, 'provisions' => 0, 'liabilities' => 0]];
		$pl = ['profitAndLoss' => ['netTurnover' => 10, 'operatingExpenses' => 5, 'financialResult' => 0, 'result' => 5]];
		$normal = $bs + $pl + ['statementModel' => 'normal', 'components' => ['balance', 'pnl', ' Notes '], 'equityChangesStatementPresent' => true, 'cashFlowStatementPresent' => 'yes'];
		$mandate = ['enabled' => true, 'structuredFormat' => 'UBL'];
		$saft = ['capable' => true];
		return [
			// Italian statements.
			'it euro rounding' => [$fs, 'it-cc-art2423-euro-rounding', ['currency' => ' eur ', 'roundedToEuro' => 'yes'], true],
			'it not rounded' => [$fs, 'it-cc-art2423-euro-rounding', ['currency' => 'EUR', 'roundedToEuro' => 'no'], false],
			'it pnl complete' => [$fs, 'it-cc-art2425-income-statement-schema', $pl, true],
			'it pnl line not numeric' => [$fs, 'it-cc-art2425-income-statement-schema', ['profitAndLoss' => ['result' => 'x'] + $pl['profitAndLoss']], false],
			'it pnl not an array' => [$fs, 'it-cc-art2425-income-statement-schema', ['profitAndLoss' => 5], false],
			'it oic12 all components' => [$fs, 'it-oic12-statement-structure', ['components' => ['BALANCE', 'pnl', 'notes']], true],
			'it oic12 notes missing' => [$fs, 'it-oic12-statement-structure', ['components' => ['balance', 'pnl']], false],
			'it oic12 components not a list' => [$fs, 'it-oic12-statement-structure', ['components' => 'balance,pnl,notes'], false],
			'it lower of cost and nrv' => [$fs, 'it-cc-art2426-9-inventories-lower-of', ['inventoryMeasurementBasis' => 'Lower of cost and NRV'], true],
			'it lower of cost and realisable value' => [$fs, 'it-cc-art2426-9-inventories-lower-of', ['inventoryMeasurementBasis' => 'lower of cost or realisable value'], true],
			'it cost only' => [$fs, 'it-cc-art2426-9-inventories-lower-of', ['inventoryMeasurementBasis' => 'cost'], false],
			'it no basis' => [$fs, 'it-cc-art2426-9-inventories-lower-of', [], false],
			'it oic13 lower of and fifo' => [$fs, 'it-oic13-inventories', ['inventoryMeasurementBasis' => 'lower nrv', 'inventoryCostFormula' => 'FIFO'], true],
			'it oic13 unknown formula' => [$fs, 'it-oic13-inventories', ['inventoryMeasurementBasis' => 'lower nrv', 'inventoryCostFormula' => 'specific'], false],
			'it cost formula disclosed' => [$fs, 'it-cc-art2426-10-inventory-cost-formula', ['inventoryCostFormula' => 'Weighted average', 'inventoryCostFormulaDisclosed' => true], true],
			'it cost formula undisclosed' => [$fs, 'it-cc-art2426-10-inventory-cost-formula', ['inventoryCostFormula' => 'weighted_average'], false],
			'it abbreviated for small' => [$fs, 'it-cc-art2435bis-abbreviated', ['statementModel' => 'abbreviated', 'sizeClass' => 'Small'], true],
			'it abbreviated for large' => [$fs, 'it-cc-art2435bis-abbreviated', ['statementModel' => 'abbreviated', 'sizeClass' => 'large'], false],
			'it abbreviated without size' => [$fs, 'it-cc-art2435bis-abbreviated', ['statementModel' => 'micro'], false],
			'it full model any size' => [$fs, 'it-cc-art2435bis-abbreviated', ['statementModel' => 'ordinary', 'sizeClass' => 'large'], true],
			'it oic34 before 2024' => [$fs, 'it-oic34-effective-2024', ['reportingPeriodStart' => '2023-01-01'], true],
			'it oic34 applied' => [$fs, 'it-oic34-effective-2024', ['reportingPeriodStart' => '2024-01-01', 'oic34Applied' => 1], true],
			'it oic34 not applied' => [$fs, 'it-oic34-effective-2024', ['reportingPeriodStart' => '2024-01-01'], false],
			'it oic34 no start date' => [$fs, 'it-oic34-effective-2024', [], false],
			'it oic34 unparsable start' => [$fs, 'it-oic34-effective-2024', ['reportingPeriodStart' => 'someday'], false],
			// Spanish statements.
			'es norma10 lifo' => [$fs, 'es-pgc-norma10-existencias', ['inventoryMeasurementBasis' => 'lower of cost and nrv', 'inventoryCostFormula' => 'lifo'], true],
			'es norma14 before 2021' => [$fs, 'es-pgc-norma14-icac-2021', ['reportingPeriodStart' => '2020-12-31'], true],
			'es norma14 not applied' => [$fs, 'es-pgc-norma14-icac-2021', ['reportingPeriodStart' => '2021-01-01'], false],
			'es normal model complete' => [$fs, 'es-pgc-cuentas-anuales-normal', $normal, true],
			'es normal model no cash flow' => [$fs, 'es-pgc-cuentas-anuales-normal', ['cashFlowStatementPresent' => false] + $normal, false],
			'es normal model no notes' => [$fs, 'es-pgc-cuentas-anuales-normal', ['components' => ['balance', 'pnl']] + $normal, false],
			'es abreviado model skips normal' => [$fs, 'es-pgc-cuentas-anuales-normal', ['statementModel' => 'abreviado'], true],
			'es abreviado for medium' => [$fs, 'es-pgc-cuentas-anuales-abreviado', ['statementModel' => 'Abreviado', 'sizeClass' => 'medium'], false],
			'es pymes for micro' => [$fs, 'es-pgc-pymes', ['statementModel' => 'PGC-PYMES', 'sizeClass' => 'micro'], true],
			'es pymes for large' => [$fs, 'es-pgc-pymes', ['statementModel' => 'pymes', 'sizeClass' => 'large'], false],
			'es non-pymes model' => [$fs, 'es-pgc-pymes', ['statementModel' => 'normal', 'sizeClass' => 'large'], true],
			// Belgian statements.
			'be full model' => [$fs, 'be-car-annual-accounts-models', ['statementModel' => 'full', 'sizeClass' => 'large'], true],
			'be short model for small' => [$fs, 'be-car-annual-accounts-models', ['statementModel' => 'short', 'sizeClass' => 'small'], true],
			'be short model for large' => [$fs, 'be-car-annual-accounts-models', ['statementModel' => 'short', 'sizeClass' => 'large'], false],
			'be no model' => [$fs, 'be-car-annual-accounts-models', ['sizeClass' => 'small'], false],
			'be nbb filed in time' => [$fs, 'be-nbb-filing', ['adoptionDate' => '2027-06-01', 'filedDate' => '2027-07-01', 'reportingPeriodEnd' => '2026-12-31'], true],
			'be nbb filed too long after adoption' => [$fs, 'be-nbb-filing', ['adoptionDate' => '2027-05-01', 'filedDate' => '2027-06-01', 'reportingPeriodEnd' => '2026-12-31'], false],
			'be nbb filed before adoption' => [$fs, 'be-nbb-filing', ['adoptionDate' => '2027-05-01', 'filedDate' => '2027-04-30', 'reportingPeriodEnd' => '2026-12-31'], false],
			'be nbb filed past seven months' => [$fs, 'be-nbb-filing', ['adoptionDate' => '2027-07-15', 'filedDate' => '2027-08-01', 'reportingPeriodEnd' => '2026-12-31'], false],
			'be nbb no period end' => [$fs, 'be-nbb-filing', ['adoptionDate' => '2027-06-01', 'filedDate' => '2027-06-02'], false],
			'be nbb no adoption date' => [$fs, 'be-nbb-filing', ['filedDate' => '2027-06-02', 'reportingPeriodEnd' => '2026-12-31'], false],
			// E-invoicing mandates.
			'it sdi via channel' => [$em, 'it-einvoice-sdi-b2b', $mandate + ['transmissionChannel' => 'SDI'], true],
			'it sdi via format' => [$em, 'it-einvoice-sdi-b2b', ['enabled' => true, 'structuredFormat' => 'FatturaPA 1.2'], true],
			'it sdi wrong channel and format' => [$em, 'it-einvoice-sdi-b2b', $mandate + ['transmissionChannel' => 'email'], false],
			'it sdi disabled' => [$em, 'it-einvoice-sdi-b2b', ['enabled' => false, 'structuredFormat' => 'FatturaPA', 'transmissionChannel' => 'SdI'], false],
			'it sdi no format' => [$em, 'it-einvoice-sdi-b2b', ['enabled' => true, 'transmissionChannel' => 'SdI'], false],
			'es verifactu chained' => [$em, 'es-verifactu-certified-software', $mandate + ['transmissionChannel' => 'AEAT', 'chainedRecords' => true], true],
			'es verifactu unchained' => [$em, 'es-verifactu-certified-software', $mandate + ['transmissionChannel' => 'AEAT'], false],
			'es ticketbai chained' => [$em, 'es-ticketbai-basque', $mandate + ['transmissionChannel' => 'regional', 'chainedRecords' => 'true'], true],
			'es sii not real time' => [$em, 'es-sii-real-time-vat', $mandate + ['transmissionChannel' => 'AEAT', 'realTime' => false], false],
			'be peppol live in time' => [$em, 'be-einvoice-b2b-peppol', $mandate + ['transmissionChannel' => 'peppol', 'liveFrom' => '2026-01-01'], true],
			'be peppol live too late' => [$em, 'be-einvoice-b2b-peppol', $mandate + ['transmissionChannel' => 'peppol', 'liveFrom' => '2026-01-02'], false],
			'be peppol no live date' => [$em, 'be-einvoice-b2b-peppol', $mandate + ['transmissionChannel' => 'peppol'], false],
			'pt billing with qr and atcud' => [$em, 'pt-saft-pt-billing', $mandate + ['transmissionChannel' => 'e-Fatura', 'qrCode' => true, 'atcud' => true], true],
			'pt billing without atcud' => [$em, 'pt-saft-pt-billing', $mandate + ['transmissionChannel' => 'e-Fatura', 'qrCode' => true], false],
			'ro efactura' => [$em, 'ro-efactura-b2b', $mandate + ['transmissionChannel' => 'RO e-Factura'], true],
			'hu online szamla real time' => [$em, 'hu-rtir-online-szamla', $mandate + ['transmissionChannel' => 'OnlineSzamla', 'realTime' => true], true],
			'gr mydata' => [$em, 'gr-mydata-ledgers', ['enabled' => true, 'structuredFormat' => 'myDATA XML'], true],
			'lt isaf' => [$em, 'lt-isaf-vat-invoices', $mandate + ['transmissionChannel' => 'VMI'], true],
			'fr einvoice live by sept 2026' => [$em, 'fr-einvoice-b2b-2026', $mandate + ['transmissionChannel' => 'PPF', 'liveFrom' => '2026-09-01T00:00:00Z'], true],
			'fr einvoice live too late' => [$em, 'fr-einvoice-b2b-2026', $mandate + ['transmissionChannel' => 'PPF', 'liveFrom' => '2026-09-02'], false],
			'pl ksef' => [$em, 'pl-ksef-b2b', $mandate + ['transmissionChannel' => 'KSeF'], true],
			'de xrechnung live by 2025' => [$em, 'de-einvoice-b2b-2025', ['enabled' => true, 'structuredFormat' => 'XRechnung', 'liveFrom' => '2024-12-31'], true],
			'de unparsable live date' => [$em, 'de-einvoice-b2b-2025', ['enabled' => true, 'structuredFormat' => 'XRechnung', 'liveFrom' => 'soon'], false],
			// SAF-T exports.
			'pt saft by variant' => [$sp, 'pt-saft-pt-accounting', $saft + ['standard' => 'accounting export'], true],
			'pt saft by generic name' => [$sp, 'pt-saft-pt-accounting', $saft + ['standard' => 'saf-t 1.04'], true],
			'pt saft by country' => [$sp, 'pt-saft-pt-accounting', $saft + ['standard' => 'PT export'], true],
			'pt saft unknown standard' => [$sp, 'pt-saft-pt-accounting', $saft + ['standard' => 'csv dump'], false],
			'pt saft not capable' => [$sp, 'pt-saft-pt-accounting', ['capable' => false, 'standard' => 'SAF-T'], false],
			'pt saft no standard' => [$sp, 'pt-saft-pt-accounting', $saft + ['standard' => ' '], false],
			'ro d406 all areas' => [$sp, 'ro-saft-d406', $saft + ['standard' => 'D406', 'coveredAreas' => ['General-Ledger', 'accounts-receivable', 'accounts-payable', 'fixed-assets', ' inventory ']], true],
			'ro d406 inventory missing' => [$sp, 'ro-saft-d406', $saft + ['standard' => 'D406', 'coveredAreas' => ['general-ledger', 'accounts-receivable', 'accounts-payable', 'fixed-assets']], false],
			'ro d406 areas not a list' => [$sp, 'ro-saft-d406', $saft + ['standard' => 'D406', 'coveredAreas' => 'all'], false],
			'lt isaft' => [$sp, 'lt-isaft-accounting', $saft + ['standard' => 'i.SAF-T'], true],
			'no saft financial' => [$sp, 'no-saft-financial', $saft + ['standard' => 'NO financial'], true],
			'at saft on demand' => [$sp, 'at-saft-on-demand', $saft + ['standard' => 'SAF-T AT', 'onDemand' => true], true],
			'at saft not on demand' => [$sp, 'at-saft-on-demand', $saft + ['standard' => 'SAF-T AT'], false],
			'lu faia' => [$sp, 'lu-saft-fec', $saft + ['standard' => 'FAIA 2.01'], true],
			'fr fec not on demand' => [$sp, 'fr-fec-on-demand', $saft + ['standard' => 'FEC', 'onDemand' => 'no'], false],
			'pl jpk' => [$sp, 'pl-jpk-saft', $saft + ['standard' => 'JPK_KR'], true],
			'dk saft on demand' => [$sp, 'dk-saft-on-demand', $saft + ['standard' => 'saft', 'onDemand' => '1'], true],
			'bg saft' => [$sp, 'bg-saft-2026', $saft + ['standard' => 'BG'], true],
		];

	}//end cases()

	/**
	 * Each check returns the pinned verdict.
	 *
	 * @param string               $type     The object type.
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The object checked.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testVerdict(string $type, string $ruleId, array $object, bool $expected): void {
		$check = NationalExtraTailChecks::checks()[$type][$ruleId];
		$this->assertSame($expected, $check($object, ['jurisdiction' => 'NL']));

	}//end testVerdict()
}//end class
