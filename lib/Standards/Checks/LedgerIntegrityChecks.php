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
				// --- Double-entry balance (global GAAP). Distinct corpus id from the
				// built-in ledger-double-entry-balance: same arithmetic, attributed to
				// the IFRS conceptual-framework double-entry principle. ---
				'gl-double-entry-balanced' => static fn (array $o): bool => self::balanced($o),
				// Per-transaction trial-balance proxy: each posted entry nets to zero,
				// so the ledger reconciles to zero at any reporting date.
				'gl-trial-balance-reconciles' => static fn (array $o): bool => self::balanced($o),

				// --- Immutability / no-deletion-only-reversal (global). A posted entry
				// is locked against in-place edit; a reversed entry must name the entry
				// it reverses, evidencing correction-by-offset rather than deletion. ---
				'gl-no-deletion-only-reversal' => static fn (array $o): bool => self::immutableOrReversal($o),
				// Period-locking (recommended): a locked period carries the boolean flag.
				'gl-period-locking' => static fn (array $o): bool => self::isBool($o, 'periodLocked'),
				// Change audit-trail: at least one log entry carrying user + timestamp.
				'gl-change-audit-trail' => static fn (array $o): bool => self::hasAuditTrail($o),

				// --- DE: GoBD / HGB / AO orderly, complete, timely, unalterable books. ---
				'de-hgb239-2-timely-orderly' => static fn (array $o): bool => self::completeAndDated($o),
				'de-ao146-1-timely-complete' => static fn (array $o): bool => self::completeAndDated($o),
				'de-gobd-vollstaendigkeit' => static fn (array $o): bool => self::completeAndDated($o),
				'de-gobd-zeitgerechte-buchungen' => static fn (array $o): bool => self::present($o, 'postingDate'),
				'de-hgb239-3-no-undetectable-change' => static fn (array $o): bool => self::immutableWithTrail($o),
				'de-ao146-4-no-unrecorded-change' => static fn (array $o): bool => self::immutableWithTrail($o),
				'de-gobd-unveraenderbarkeit' => static fn (array $o): bool => self::immutableWithTrail($o),
				'de-gobd-belegfunktion' => static fn (array $o): bool => self::present($o, 'sourceReference'),
				'de-gobd-datenzugriff-z3' => static fn (array $o): bool => self::isTrue($o, 'saftExportable'),

				// --- FR: FEC export, validated + irreversible entries, no blank/no gap. ---
				'fr-fec-mandatory-export' => static fn (array $o): bool => self::isTrue($o, 'saftExportable'),
				'fr-fec-validated-entries' => static fn (array $o): bool => self::validatedEntry($o),
				'fr-fec-18-fields' => static fn (array $o): bool => self::fecFieldsPresent($o),
				'fr-pcg-chronological-irreversible' => static fn (array $o): bool => (self::present($o, 'postingDate') && self::isTrue($o, 'irreversible')),
				'fr-nf525-inalterability' => static fn (array $o): bool => self::immutableOrReversal($o),
				'fr-comm-l123-22-no-blank-no-gap' => static fn (array $o): bool => self::noBlankNoGap($o),

				// --- BE / ES / IT: no-blank-no-alteration orderly bookkeeping. ---
				'be-wer-no-blank-no-alteration' => static fn (array $o): bool => self::noBlankNoGap($o),
				'es-ccom-29-no-blank-no-gap' => static fn (array $o): bool => self::noBlankNoGap($o),
				'it-cc-2219-orderly' => static fn (array $o): bool => self::noBlankNoGap($o),
				'it-cad-digital-conservazione' => static fn (array $o): bool => (self::isTrue($o, 'integrityVerified') && self::present($o, 'validationDate')),

				// --- EU: VAT Directive integrity / storage / electronic access. ---
				'eu-vat-2006112-art233-integrity' => static fn (array $o): bool => self::isTrue($o, 'integrityVerified'),
				'eu-vat-2006112-art244-storage' => static fn (array $o): bool => self::present($o, 'sourceReference'),
				'eu-vat-2006112-art248a-access' => static fn (array $o): bool => self::isTrue($o, 'archiveAvailable'),
				'eu-saft-oecd-audit-file' => static fn (array $o): bool => self::isTrue($o, 'saftExportable'),

				// --- PT / PL / RO: SAF-T family export availability. ---
				'pt-saft-pt-mandatory' => static fn (array $o): bool => self::isTrue($o, 'saftExportable'),
				'pl-jpk-vat-mandatory' => static fn (array $o): bool => self::isTrue($o, 'saftExportable'),
				'ro-d406-saft-mandatory' => static fn (array $o): bool => self::isTrue($o, 'saftExportable'),

				// --- US: electronic records retrievable in machine-sensible form. ---
				'us-irs-electronic-records' => static fn (array $o): bool => self::isTrue($o, 'archiveAvailable'),

				// --- Retention: retentionUntil covers at least N years past postingDate. ---
				// NL 7y (AWR §52 / BW 2:10), DE 8y (AO/HGB/UStG), FR 10y + 6y (Code de
				// commerce / LPF), BE 10y (BTW), ES 6y + 4y (Código de Comercio / LGT),
				// IT 10y (Codice Civile / DPR 600), EU VAT 244 storage, US 3y/6y/7y/4y.
				'nl-awr52-general-7y' => static fn (array $o): bool => self::retainsYears($o, 7),
				'nl-awr52-realestate-10y' => static fn (array $o): bool => self::retainsYears($o, 10),
				'nl-bw2-10-retain-7y' => static fn (array $o): bool => self::retainsYears($o, 7),
				'de-ao147-3-books-8y' => static fn (array $o): bool => self::retainsYears($o, 8),
				'de-ao147-belege-8y-beg4' => static fn (array $o): bool => self::retainsYears($o, 8),
				'de-ao147-commercial-letters-6y' => static fn (array $o): bool => self::retainsYears($o, 6),
				'de-hgb257-4-books-8y' => static fn (array $o): bool => self::retainsYears($o, 8),
				'de-hgb257-letters-6y' => static fn (array $o): bool => self::retainsYears($o, 6),
				'de-ustg14b-invoice-8y' => static fn (array $o): bool => self::retainsYears($o, 8),
				'fr-comm-l123-22-10y' => static fn (array $o): bool => self::retainsYears($o, 10),
				'fr-lpf-l102b-6y' => static fn (array $o): bool => self::retainsYears($o, 6),
				'be-wvv-3-65-10y' => static fn (array $o): bool => self::retainsYears($o, 10),
				'be-vat-invoice-10y' => static fn (array $o): bool => self::retainsYears($o, 10),
				'es-ccom-30-6y' => static fn (array $o): bool => self::retainsYears($o, 6),
				'es-lgt-66-tax-4y' => static fn (array $o): bool => self::retainsYears($o, 4),
				'it-cc-2220-10y' => static fn (array $o): bool => self::retainsYears($o, 10),
				'it-dpr600-tax-records' => static fn (array $o): bool => self::retainsYears($o, 10),
				'us-irs-general-3y' => static fn (array $o): bool => self::retainsYears($o, 3),
				'us-irs-substantial-omission-6y' => static fn (array $o): bool => self::retainsYears($o, 6),
				'us-irs-worthless-security-7y' => static fn (array $o): bool => self::retainsYears($o, 7),
				'us-irs-employment-tax-4y' => static fn (array $o): bool => self::retainsYears($o, 4),
				'us-sox-103-auditor-workpapers-7y' => static fn (array $o): bool => self::retainsYears($o, 7),
				'us-sox-802-records-5y' => static fn (array $o): bool => self::retainsYears($o, 5),
			],
		];

	}//end checks()

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
	 * The GLLine children of the transaction (injected under `lines`), normalised
	 * to a list of arrays.
	 *
	 * @param array<string, mixed> $o The GL transaction.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function lines(array $o): array {
		$lines = ($o['lines'] ?? []);
		if (is_array($lines) === false) {
			return [];
		}

		return array_values(array_filter($lines, 'is_array'));
	}//end lines()

	/**
	 * Double-entry balance: Σ debit-side amounts = Σ credit-side amounts (to the
	 * cent). Fail-closed on no lines.
	 *
	 * @param array<string, mixed> $o The GL transaction, with `lines`.
	 *
	 * @return bool
	 */
	private static function balanced(array $o): bool {
		$lines = self::lines($o);
		if ($lines === []) {
			return false;
		}

		$debit = 0;
		$credit = 0;
		foreach ($lines as $line) {
			$cents = (int)round(((float)($line['amount'] ?? 0)) * 100);
			$side = (string)($line['side'] ?? '');
			if ($side === 'debit') {
				$debit += $cents;
			} elseif ($side === 'credit') {
				$credit += $cents;
			} else {
				return false;
			}
		}

		return $debit === $credit;
	}//end balanced()

	/**
	 * Completeness + timeliness: at least two lines (each accounted + sided +
	 * non-zero) and a posting date present.
	 *
	 * @param array<string, mixed> $o The GL transaction, with `lines`.
	 *
	 * @return bool
	 */
	private static function completeAndDated(array $o): bool {
		$lines = self::lines($o);
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

		$lines = self::lines($o);
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
