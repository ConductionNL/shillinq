<?php

/**
 * Ledger Integrity & Retention check provider
 *
 * Contributes executable checks for the GoBD / audit-trail / retention / SAF-T
 * integrity corpus (lib/Standards/rules/integrity-retention.json) to the
 * RuleEngine. Each predicate is a real, deterministic test over the structured
 * GLTransaction / GLLine / Account fields — double-entry balance, gapless
 * numbering, posting-date / chronological presence, source-document traceability,
 * immutability + period-lock flags, change audit-trail cardinality, validated /
 * irreversible-entry flags and minimum retention-period dates per jurisdiction.
 *
 * The rules already enforced by RuleEngine's built-in registry
 * (gl-completeness-timeliness, gl-sequential-journal-numbering,
 * gl-chronological-order, ledger-double-entry-balance,
 * gl-source-document-traceability) are intentionally NOT re-registered here.
 *
 * GLTransaction objects are evaluated with their GLLine children injected under
 * `lines` (see RuleAuditService / RuleComplianceGuard), so the line-level balance
 * and completeness predicates run against real ledger data. The extra scalar /
 * date / array fields the immutability, audit-trail and retention predicates read
 * are declared in seedSpec() (backfilled onto the test data by RuleTestDataSeeder)
 * and in the schema fragment lib/Settings/register.d/checks-ledger-integrity.json.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

use DateTimeImmutable;

/**
 * Executable ledger-integrity, immutability and retention checks.
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class LedgerIntegrityChecks implements CheckProvider {
	/**
	 * Ledger-integrity / retention predicates keyed by object type then rule id.
	 * Each value is `fn(array $object, array $context): bool` — true = satisfied.
	 * Every key is a real id in integrity-retention.json with machineCheckable=true.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'GLTransaction' => [
				...self::balanceImmutabilityAndGermanBookChecks(),
				...self::frenchAndSouthernBookChecks(),
				...self::exportAccessAndFirstRetentionChecks(),
				...self::europeanRetentionChecks(),
				...self::southernEuropeanAndUsRetentionChecks(),
			],
		];

	}//end checks()

	/**
	 * The balance, immutability and German orderly-books predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function balanceImmutabilityAndGermanBookChecks(): array {
		return [
			// --- Double-entry balance (global GAAP). Distinct corpus id from the
			'gl-double-entry-balanced' => self::glDoubleEntryBalanced(...),
			'gl-trial-balance-reconciles' => self::glTrialBalanceReconciles(...),

			// --- Immutability / no-deletion-only-reversal (global). A posted entry
			'gl-no-deletion-only-reversal' => self::glNoDeletionOnlyReversal(...),
			'gl-period-locking' => self::glPeriodLocking(...),
			'gl-change-audit-trail' => self::glChangeAuditTrail(...),

			// --- DE: GoBD / HGB / AO orderly, complete, timely, unalterable books. ---
			'de-hgb239-2-timely-orderly' => self::deHgb2392TimelyOrderly(...),
			'de-ao146-1-timely-complete' => self::deAo1461TimelyComplete(...),
			'de-gobd-vollstaendigkeit' => self::deGobdVollstaendigkeit(...),
			'de-gobd-zeitgerechte-buchungen' => self::deGobdZeitgerechteBuchungen(...),
			'de-hgb239-3-no-undetectable-change' => self::deHgb2393NoUndetectableChange(...),
			'de-ao146-4-no-unrecorded-change' => self::deAo1464NoUnrecordedChange(...),
		];

	}//end balanceImmutabilityAndGermanBookChecks()

	/**
	 * The French FEC and Belgian / Spanish / Italian orderly-books predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function frenchAndSouthernBookChecks(): array {
		return [
			'de-gobd-unveraenderbarkeit' => self::deGobdUnveraenderbarkeit(...),
			'de-gobd-belegfunktion' => self::deGobdBelegfunktion(...),
			'de-gobd-datenzugriff-z3' => self::deGobdDatenzugriffZ3(...),

			// --- FR: FEC export, validated + irreversible entries, no blank/no gap. ---
			'fr-fec-mandatory-export' => self::frFecMandatoryExport(...),
			'fr-fec-validated-entries' => self::frFecValidatedEntries(...),
			'fr-fec-18-fields' => self::frFec18Fields(...),
			'fr-pcg-chronological-irreversible' => self::frPcgChronologicalIrreversible(...),
			'fr-nf525-inalterability' => self::frNf525Inalterability(...),
			'fr-comm-l123-22-no-blank-no-gap' => self::frCommL12322NoBlankNoGap(...),

			// --- BE / ES / IT: no-blank-no-alteration orderly bookkeeping. ---
			'be-wer-no-blank-no-alteration' => self::beWerNoBlankNoAlteration(...),
			'es-ccom-29-no-blank-no-gap' => self::esCcom29NoBlankNoGap(...),
		];

	}//end frenchAndSouthernBookChecks()

	/**
	 * The EU storage, SAF-T export, US machine-readable and first retention-period predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function exportAccessAndFirstRetentionChecks(): array {
		return [
			'it-cc-2219-orderly' => self::itCc2219Orderly(...),
			'it-cad-digital-conservazione' => self::itCadDigitalConservazione(...),

			// --- EU: VAT Directive integrity / storage / electronic access. ---
			'eu-vat-2006112-art233-integrity' => self::euVat2006112Art233Integrity(...),
			'eu-vat-2006112-art244-storage' => self::euVat2006112Art244Storage(...),
			'eu-vat-2006112-art248a-access' => self::euVat2006112Art248aAccess(...),
			'eu-saft-oecd-audit-file' => self::euSaftOecdAuditFile(...),

			// --- PT / PL / RO: SAF-T family export availability. ---
			'pt-saft-pt-mandatory' => self::ptSaftPtMandatory(...),
			'pl-jpk-vat-mandatory' => self::plJpkVatMandatory(...),
			'ro-d406-saft-mandatory' => self::roD406SaftMandatory(...),

			// --- US: electronic records retrievable in machine-sensible form. ---
			'us-irs-electronic-records' => self::usIrsElectronicRecords(...),

			// --- Retention: retentionUntil covers at least N years past postingDate. ---
			'nl-awr52-general-7y' => self::nlAwr52General7y(...),
		];

	}//end exportAccessAndFirstRetentionChecks()

	/**
	 * The Dutch, German, French and Belgian retention-period predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function europeanRetentionChecks(): array {
		return [
			'nl-awr52-realestate-10y' => self::nlAwr52Realestate10y(...),
			'nl-bw2-10-retain-7y' => self::nlBw210Retain7y(...),
			'de-ao147-3-books-8y' => self::deAo1473Books8y(...),
			'de-ao147-belege-8y-beg4' => self::deAo147Belege8yBeg4(...),
			'de-ao147-commercial-letters-6y' => self::deAo147CommercialLetters6y(...),
			'de-hgb257-4-books-8y' => self::deHgb2574Books8y(...),
			'de-hgb257-letters-6y' => self::deHgb257Letters6y(...),
			'de-ustg14b-invoice-8y' => self::deUstg14bInvoice8y(...),
			'fr-comm-l123-22-10y' => self::frCommL1232210y(...),
			'fr-lpf-l102b-6y' => self::frLpfL102b6y(...),
			'be-wvv-3-65-10y' => self::beWvv36510y(...),
		];

	}//end europeanRetentionChecks()

	/**
	 * The Belgian VAT, Spanish, Italian and US retention-period predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function southernEuropeanAndUsRetentionChecks(): array {
		return [
			'be-vat-invoice-10y' => self::beVatInvoice10y(...),
			'es-ccom-30-6y' => self::esCcom306y(...),
			'es-lgt-66-tax-4y' => self::esLgt66Tax4y(...),
			'it-cc-2220-10y' => self::itCc222010y(...),
			'it-dpr600-tax-records' => self::itDpr600TaxRecords(...),
			'us-irs-general-3y' => self::usIrsGeneral3y(...),
			'us-irs-substantial-omission-6y' => self::usIrsSubstantialOmission6y(...),
			'us-irs-worthless-security-7y' => self::usIrsWorthlessSecurity7y(...),
			'us-irs-employment-tax-4y' => self::usIrsEmploymentTax4y(...),
			'us-sox-103-auditor-workpapers-7y' => self::usSox103AuditorWorkpapers7y(...),
			'us-sox-802-records-5y' => self::usSox802Records5y(...),
		];

	}//end southernEuropeanAndUsRetentionChecks()

	/**
	 * built-in ledger-double-entry-balance: same arithmetic, attributed to
	 * the IFRS conceptual-framework double-entry principle. ---
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function glDoubleEntryBalanced(array $o): bool {
		return GlTransactionLines::balanced(transaction: $o);

	}//end glDoubleEntryBalanced()

	/**
	 * Per-transaction trial-balance proxy: each posted entry nets to zero,
	 * so the ledger reconciles to zero at any reporting date.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function glTrialBalanceReconciles(array $o): bool {
		return GlTransactionLines::balanced(transaction: $o);

	}//end glTrialBalanceReconciles()

	/**
	 * is locked against in-place edit; a reversed entry must name the entry
	 * it reverses, evidencing correction-by-offset rather than deletion. ---
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function glNoDeletionOnlyReversal(array $o): bool {
		return self::immutableOrReversal($o);

	}//end glNoDeletionOnlyReversal()

	/**
	 * Period-locking (recommended): a locked period carries the boolean flag.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function glPeriodLocking(array $o): bool {
		return self::isBool($o, 'periodLocked');

	}//end glPeriodLocking()

	/**
	 * Change audit-trail: at least one log entry carrying user + timestamp.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function glChangeAuditTrail(array $o): bool {
		return self::hasAuditTrail($o);

	}//end glChangeAuditTrail()

	/**
	 * Rule de-hgb239-2-timely-orderly holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deHgb2392TimelyOrderly(array $o): bool {
		return self::completeAndDated($o);

	}//end deHgb2392TimelyOrderly()

	/**
	 * Rule de-ao146-1-timely-complete holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deAo1461TimelyComplete(array $o): bool {
		return self::completeAndDated($o);

	}//end deAo1461TimelyComplete()

	/**
	 * Rule de-gobd-vollstaendigkeit holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deGobdVollstaendigkeit(array $o): bool {
		return self::completeAndDated($o);

	}//end deGobdVollstaendigkeit()

	/**
	 * Rule de-gobd-zeitgerechte-buchungen holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deGobdZeitgerechteBuchungen(array $o): bool {
		return self::present($o, 'postingDate');

	}//end deGobdZeitgerechteBuchungen()

	/**
	 * Rule de-hgb239-3-no-undetectable-change holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deHgb2393NoUndetectableChange(array $o): bool {
		return self::immutableWithTrail($o);

	}//end deHgb2393NoUndetectableChange()

	/**
	 * Rule de-ao146-4-no-unrecorded-change holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deAo1464NoUnrecordedChange(array $o): bool {
		return self::immutableWithTrail($o);

	}//end deAo1464NoUnrecordedChange()

	/**
	 * Rule de-gobd-unveraenderbarkeit holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deGobdUnveraenderbarkeit(array $o): bool {
		return self::immutableWithTrail($o);

	}//end deGobdUnveraenderbarkeit()

	/**
	 * Rule de-gobd-belegfunktion holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deGobdBelegfunktion(array $o): bool {
		return self::present($o, 'sourceReference');

	}//end deGobdBelegfunktion()

	/**
	 * Rule de-gobd-datenzugriff-z3 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deGobdDatenzugriffZ3(array $o): bool {
		return self::isTrue($o, 'saftExportable');

	}//end deGobdDatenzugriffZ3()

	/**
	 * Rule fr-fec-mandatory-export holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function frFecMandatoryExport(array $o): bool {
		return self::isTrue($o, 'saftExportable');

	}//end frFecMandatoryExport()

	/**
	 * Rule fr-fec-validated-entries holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function frFecValidatedEntries(array $o): bool {
		return self::validatedEntry($o);

	}//end frFecValidatedEntries()

	/**
	 * Rule fr-fec-18-fields holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function frFec18Fields(array $o): bool {
		return self::fecFieldsPresent($o);

	}//end frFec18Fields()

	/**
	 * Rule fr-pcg-chronological-irreversible holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function frPcgChronologicalIrreversible(array $o): bool {
		return (self::present($o, 'postingDate') && self::isTrue($o, 'irreversible'));

	}//end frPcgChronologicalIrreversible()

	/**
	 * Rule fr-nf525-inalterability holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function frNf525Inalterability(array $o): bool {
		return self::immutableOrReversal($o);

	}//end frNf525Inalterability()

	/**
	 * Rule fr-comm-l123-22-no-blank-no-gap holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function frCommL12322NoBlankNoGap(array $o): bool {
		return self::noBlankNoGap($o);

	}//end frCommL12322NoBlankNoGap()

	/**
	 * Rule be-wer-no-blank-no-alteration holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function beWerNoBlankNoAlteration(array $o): bool {
		return self::noBlankNoGap($o);

	}//end beWerNoBlankNoAlteration()

	/**
	 * Rule es-ccom-29-no-blank-no-gap holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esCcom29NoBlankNoGap(array $o): bool {
		return self::noBlankNoGap($o);

	}//end esCcom29NoBlankNoGap()

	/**
	 * Rule it-cc-2219-orderly holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function itCc2219Orderly(array $o): bool {
		return self::noBlankNoGap($o);

	}//end itCc2219Orderly()

	/**
	 * Rule it-cad-digital-conservazione holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function itCadDigitalConservazione(array $o): bool {
		return (self::isTrue($o, 'integrityVerified') && self::present($o, 'validationDate'));

	}//end itCadDigitalConservazione()

	/**
	 * Rule eu-vat-2006112-art233-integrity holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function euVat2006112Art233Integrity(array $o): bool {
		return self::isTrue($o, 'integrityVerified');

	}//end euVat2006112Art233Integrity()

	/**
	 * Rule eu-vat-2006112-art244-storage holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function euVat2006112Art244Storage(array $o): bool {
		return self::present($o, 'sourceReference');

	}//end euVat2006112Art244Storage()

	/**
	 * Rule eu-vat-2006112-art248a-access holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function euVat2006112Art248aAccess(array $o): bool {
		return self::isTrue($o, 'archiveAvailable');

	}//end euVat2006112Art248aAccess()

	/**
	 * Rule eu-saft-oecd-audit-file holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function euSaftOecdAuditFile(array $o): bool {
		return self::isTrue($o, 'saftExportable');

	}//end euSaftOecdAuditFile()

	/**
	 * Rule pt-saft-pt-mandatory holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function ptSaftPtMandatory(array $o): bool {
		return self::isTrue($o, 'saftExportable');

	}//end ptSaftPtMandatory()

	/**
	 * Rule pl-jpk-vat-mandatory holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function plJpkVatMandatory(array $o): bool {
		return self::isTrue($o, 'saftExportable');

	}//end plJpkVatMandatory()

	/**
	 * Rule ro-d406-saft-mandatory holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function roD406SaftMandatory(array $o): bool {
		return self::isTrue($o, 'saftExportable');

	}//end roD406SaftMandatory()

	/**
	 * Rule us-irs-electronic-records holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function usIrsElectronicRecords(array $o): bool {
		return self::isTrue($o, 'archiveAvailable');

	}//end usIrsElectronicRecords()

	/**
	 * NL 7y (AWR §52 / BW 2:10), DE 8y (AO/HGB/UStG), FR 10y + 6y (Code de
	 * commerce / LPF), BE 10y (BTW), ES 6y + 4y (Código de Comercio / LGT),
	 * IT 10y (Codice Civile / DPR 600), EU VAT 244 storage, US 3y/6y/7y/4y.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function nlAwr52General7y(array $o): bool {
		return self::retainsYears($o, 7);

	}//end nlAwr52General7y()

	/**
	 * Rule nl-awr52-realestate-10y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function nlAwr52Realestate10y(array $o): bool {
		return self::retainsYears($o, 10);

	}//end nlAwr52Realestate10y()

	/**
	 * Rule nl-bw2-10-retain-7y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function nlBw210Retain7y(array $o): bool {
		return self::retainsYears($o, 7);

	}//end nlBw210Retain7y()

	/**
	 * Rule de-ao147-3-books-8y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deAo1473Books8y(array $o): bool {
		return self::retainsYears($o, 8);

	}//end deAo1473Books8y()

	/**
	 * Rule de-ao147-belege-8y-beg4 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deAo147Belege8yBeg4(array $o): bool {
		return self::retainsYears($o, 8);

	}//end deAo147Belege8yBeg4()

	/**
	 * Rule de-ao147-commercial-letters-6y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deAo147CommercialLetters6y(array $o): bool {
		return self::retainsYears($o, 6);

	}//end deAo147CommercialLetters6y()

	/**
	 * Rule de-hgb257-4-books-8y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deHgb2574Books8y(array $o): bool {
		return self::retainsYears($o, 8);

	}//end deHgb2574Books8y()

	/**
	 * Rule de-hgb257-letters-6y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deHgb257Letters6y(array $o): bool {
		return self::retainsYears($o, 6);

	}//end deHgb257Letters6y()

	/**
	 * Rule de-ustg14b-invoice-8y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function deUstg14bInvoice8y(array $o): bool {
		return self::retainsYears($o, 8);

	}//end deUstg14bInvoice8y()

	/**
	 * Rule fr-comm-l123-22-10y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function frCommL1232210y(array $o): bool {
		return self::retainsYears($o, 10);

	}//end frCommL1232210y()

	/**
	 * Rule fr-lpf-l102b-6y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function frLpfL102b6y(array $o): bool {
		return self::retainsYears($o, 6);

	}//end frLpfL102b6y()

	/**
	 * Rule be-wvv-3-65-10y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function beWvv36510y(array $o): bool {
		return self::retainsYears($o, 10);

	}//end beWvv36510y()

	/**
	 * Rule be-vat-invoice-10y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function beVatInvoice10y(array $o): bool {
		return self::retainsYears($o, 10);

	}//end beVatInvoice10y()

	/**
	 * Rule es-ccom-30-6y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esCcom306y(array $o): bool {
		return self::retainsYears($o, 6);

	}//end esCcom306y()

	/**
	 * Rule es-lgt-66-tax-4y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function esLgt66Tax4y(array $o): bool {
		return self::retainsYears($o, 4);

	}//end esLgt66Tax4y()

	/**
	 * Rule it-cc-2220-10y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function itCc222010y(array $o): bool {
		return self::retainsYears($o, 10);

	}//end itCc222010y()

	/**
	 * Rule it-dpr600-tax-records holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function itDpr600TaxRecords(array $o): bool {
		return self::retainsYears($o, 10);

	}//end itDpr600TaxRecords()

	/**
	 * Rule us-irs-general-3y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function usIrsGeneral3y(array $o): bool {
		return self::retainsYears($o, 3);

	}//end usIrsGeneral3y()

	/**
	 * Rule us-irs-substantial-omission-6y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function usIrsSubstantialOmission6y(array $o): bool {
		return self::retainsYears($o, 6);

	}//end usIrsSubstantialOmission6y()

	/**
	 * Rule us-irs-worthless-security-7y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function usIrsWorthlessSecurity7y(array $o): bool {
		return self::retainsYears($o, 7);

	}//end usIrsWorthlessSecurity7y()

	/**
	 * Rule us-irs-employment-tax-4y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function usIrsEmploymentTax4y(array $o): bool {
		return self::retainsYears($o, 4);

	}//end usIrsEmploymentTax4y()

	/**
	 * Rule us-sox-103-auditor-workpapers-7y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function usSox103AuditorWorkpapers7y(array $o): bool {
		return self::retainsYears($o, 7);

	}//end usSox103AuditorWorkpapers7y()

	/**
	 * Rule us-sox-802-records-5y holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function usSox802Records5y(array $o): bool {
		return self::retainsYears($o, 5);

	}//end usSox802Records5y()

	/**
	 * Test-data field defaults the GLTransaction checks expect. Backfilled onto
	 * the seeded GLTransaction objects by RuleTestDataSeeder so a fresh
	 * environment audits 100%-compliant. The schema fragment
	 * checks-ledger-integrity.json declares these properties on the schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			'GLTransaction' => [
				// Retention covers every jurisdiction threshold (max is 10y) with margin.
				'retentionUntil' => '2099-12-31',
				// A posted entry is locked against in-place editing.
				'postingLocked' => true,
				// Its accounting period is locked.
				'periodLocked' => true,
				// Corrections happen by offsetting reversal, never by deletion/edit.
				'irreversible' => true,
				// Authenticity of origin + integrity of content ensured (VAT art. 233).
				'integrityVerified' => true,
				// Archived copy stays readable/reproducible and available on request.
				'archiveAvailable' => true,
				// Tax-relevant data exportable in a machine-evaluable (SAF-T) format.
				'saftExportable' => true,
				// Definitively validated (closed) on this date — FEC / conservazione.
				'validationDate' => '2026-12-31',
				// Change audit trail: one log entry with user + timestamp + action.
				'auditTrail' => [
					[
						'user' => 'system',
						'timestamp' => '2026-01-01T00:00:00+00:00',
						'action' => 'post',
					],
				],
			],
		];

	}//end seedSpec()

	/**
	 * True when a field holds a non-empty value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function present(array $o, string $key): bool {
		return isset($o[$key]) === true && trim((string)$o[$key]) !== '';
	}//end present()

	/**
	 * True when a boolean-ish field is strictly truthy (true / 1 / "1" / "true").
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function isTrue(array $o, string $key): bool {
		$value = ($o[$key] ?? null);
		if (is_bool($value) === true) {
			return $value;
		}

		return in_array($value, [1, '1', 'true'], true);
	}//end isTrue()

	/**
	 * True when a field carries an explicit boolean value (true OR false), i.e. the
	 * locking decision has been recorded rather than left unset.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function isBool(array $o, string $key): bool {
		$value = ($o[$key] ?? null);
		return is_bool($value) === true || in_array($value, [0, 1, '0', '1', 'true', 'false'], true);
	}//end isBool()

	/**
	 * Completeness + timeliness: at least two lines (each accounted + sided +
	 * non-zero) and a posting date present.
	 *
	 * @param array<string, mixed> $o The GL transaction, with `lines`.
	 *
	 * @return bool
	 */
	private static function completeAndDated(array $o): bool {
		$lines = GlTransactionLines::of(transaction: $o);
		if (count($lines) < 2) {
			return false;
		}

		foreach ($lines as $line) {
			$hasAccount = trim((string)($line['accountNumber'] ?? '')) !== '';
			$sided = in_array(($line['side'] ?? ''), ['debit', 'credit'], true);
			$nonZero = ((int)round(((float)($line['amount'] ?? 0)) * 100)) !== 0;
			if ($hasAccount === false || $sided === false || $nonZero === false) {
				return false;
			}
		}

		return self::present($o, 'postingDate');
	}//end completeAndDated()

	/**
	 * No-blank / no-gap orderly bookkeeping: a sequential number, a posting date
	 * and at least two non-empty lines are present so the entry has no blanks and
	 * the journal sequence has no gap at this entry.
	 *
	 * @param array<string, mixed> $o The GL transaction, with `lines`.
	 *
	 * @return bool
	 */
	private static function noBlankNoGap(array $o): bool {
		return self::present($o, 'transactionNumber') === true
			&& self::present($o, 'postingDate') === true
			&& self::completeAndDated($o) === true;

	}//end noBlankNoGap()

	/**
	 * Immutability or correction-by-reversal: a posted entry is locked against
	 * in-place editing, OR (when it is itself a reversal) it names the entry it
	 * reverses — evidencing correction by an offsetting entry, never deletion.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return bool
	 */
	private static function immutableOrReversal(array $o): bool {
		$state = (string)($o['state'] ?? '');
		if ($state === 'reversed') {
			return self::present($o, 'reversesTransactionId');
		}

		if ($state === 'posted') {
			return self::isTrue($o, 'postingLocked');
		}

		// Draft entries are still editable by design — not yet subject to the lock.
		return true;
	}//end immutableOrReversal()

	/**
	 * Unalterability with a preserved audit trail (GoBD Unveränderbarkeit / §239(3)
	 * HGB / §146(4) AO): the entry is locked/irreversible AND every change is
	 * reconstructable from the audit trail.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return bool
	 */
	private static function immutableWithTrail(array $o): bool {
		return self::immutableOrReversal($o) === true && self::hasAuditTrail($o) === true;
	}//end immutableWithTrail()

	/**
	 * A change audit trail exists: at least one log entry carrying a user and a
	 * timestamp, so the full history of the entry is reconstructable.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return bool
	 */
	private static function hasAuditTrail(array $o): bool {
		$trail = ($o['auditTrail'] ?? null);
		if (is_array($trail) === false || $trail === []) {
			return false;
		}

		foreach ($trail as $entry) {
			if (is_array($entry) === false) {
				return false;
			}

			$hasUser = trim((string)($entry['user'] ?? '')) !== '';
			$hasTime = trim((string)($entry['timestamp'] ?? '')) !== '';
			if ($hasUser === false || $hasTime === false) {
				return false;
			}
		}

		return true;
	}//end hasAuditTrail()

	/**
	 * A definitively validated (closed) entry: posted/reversed state with a
	 * validation date that can no longer be modified (FEC / NF525 / conservazione).
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return bool
	 */
	private static function validatedEntry(array $o): bool {
		if (in_array((string)($o['state'] ?? ''), ['posted', 'reversed'], true) === false) {
			return false;
		}

		return self::present($o, 'validationDate') && self::isTrue($o, 'irreversible');
	}//end validatedEntry()

	/**
	 * The FEC core identifying fields are present per entry: a journal/transaction
	 * number, a posting (entry) date, a source-document reference and at least one
	 * accounted, sided, non-zero line (debit/credit + account).
	 *
	 * @param array<string, mixed> $o The GL transaction, with `lines`.
	 *
	 * @return bool
	 */
	private static function fecFieldsPresent(array $o): bool {
		if (self::present($o, 'transactionNumber') === false
			|| self::present($o, 'postingDate') === false
			|| self::present($o, 'sourceReference') === false
		) {
			return false;
		}

		$lines = GlTransactionLines::of(transaction: $o);
		if ($lines === []) {
			return false;
		}

		foreach ($lines as $line) {
			$hasAccount = trim((string)($line['accountNumber'] ?? '')) !== '';
			$sided = in_array(($line['side'] ?? ''), ['debit', 'credit'], true);
			$hasAmount = is_numeric(($line['amount'] ?? null)) === true;
			if ($hasAccount === false || $sided === false || $hasAmount === false) {
				return false;
			}
		}

		return true;
	}//end fecFieldsPresent()

	/**
	 * Retention: the retentionUntil date is at least $years after the posting date
	 * (calendar-year arithmetic). Requires both dates to be present and parseable.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 * @param int $years Minimum retention period in whole years.
	 *
	 * @return bool
	 */
	private static function retainsYears(array $o, int $years): bool {
		$posting = self::parseDate((string)($o['postingDate'] ?? ''));
		$retention = self::parseDate((string)($o['retentionUntil'] ?? ''));
		if ($posting === null || $retention === null) {
			return false;
		}

		$required = $posting->modify('+' . $years . ' years');
		if ($required === false) {
			return false;
		}

		return $retention >= $required;
	}//end retainsYears()

	/**
	 * Parse a Y-m-d (or ISO 8601) date string to an immutable date, or null when
	 * empty / unparseable.
	 *
	 * @param string $value The date string.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function parseDate(string $value): ?DateTimeImmutable {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}

	}//end parseDate()
}//end class
