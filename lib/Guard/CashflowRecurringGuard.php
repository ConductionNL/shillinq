<?php

/**
 * Cashflow Recurring Cost Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ADR-031 exception-path lifecycle guard for the CashflowRecurring save precondition.
 * Validates, before any CashflowRecurring record is persisted, that the declarative
 * recurring-cost definition is internally consistent so the horizon-expansion
 * aggregation never has to defend against malformed schedules (REQ-CF-005):
 *   1. standaardBedrag is non-negative.
 *   2. The recurrence anchor matches the frequency: MAANDELIJKS requires a valid
 *      dagVanMaand (1-31); JAARLIJKS requires a valid maandVanJaar (1-12) and a
 *      dagVanMaand (1-31).
 *   3. geldigVan is a parseable date and, when geldigTot is present, geldigTot is
 *      not before geldigVan (a non-empty validity window).
 *   4. CPI indexing (indexatieRegel = CPI_AFGELOPEN_JAAR) is only meaningful for
 *      JAARLIJKS items; on other frequencies it is rejected.
 *   5. (budget-known-costs REQ-BKC-002) When contractReference is set, validFrom/
 *      validTo must fall within the referenced Contract's own startDate/endDate,
 *      open on whichever side the Contract itself leaves open.
 *
 * Referenced from the CashflowRecurring schema's x-openregister-lifecycle.preconditions.save
 * in lib/Settings/register.d/zzp-cashflow-13wk.json.
 *
 * ADR-031 exception reason: cross-field arithmetic/temporal consistency checks
 * (frequency-anchor coupling, validity-window ordering, indexing applicability)
 * cannot yet be expressed in the declarative lifecycle DSL. This guard is the
 * single permitted PHP bridge for this change; replace with declarative conditions
 * when the engine supports cross-field predicates. It computes nothing about the
 * forecast itself — all forecast maths stays in OR aggregations.
 *
 * `budget-known-costs` (REQ-BKC-002) extends this guard with one further
 * check, {@see hasConsistentContractWindow()}: when `contractReference` is
 * set, `validFrom`/`validTo` must fall within the referenced `Contract`'s
 * own `startDate`/`endDate`, open on whichever side the Contract itself
 * leaves open (`design.md` §3c).
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-5
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-002
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Save precondition guard for the CashflowRecurring schema per REQ-CF-005.
 *
 * Fail-closed: any unexpected exception denies the save (CWE-863).
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-5
 */
class CashflowRecurringGuard {

	/**
	 * Frequencies that require a day-of-month anchor.
	 *
	 * @var array<int,string>
	 */
	private const MONTHLY_FREQUENCIES = ['MONTHLY'];

	/**
	 * The Contract schema slug (contract-lifecycle-management).
	 *
	 * @var string
	 */
	private const SCHEMA_CONTRACT = 'Contract';

	/**
	 * Construct the guard.
	 *
	 * @param IAppConfig $appConfig App config for register slug resolution
	 *                              (REQ-BKC-002's Contract-window check).
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister object service (ADR-083/084),
	 *                                              used to resolve a `contractReference`'s
	 *                                              Contract when one is set.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Save precondition for the CashflowRecurring schema.
	 *
	 * Returns true only when every consistency check passes. Fail-closed:
	 * returns false on any exception (denies the save) per CWE-863.
	 *
	 * @param array<string, mixed> $recurring CashflowRecurring object array supplied by OR.
	 *
	 * @return bool True when the recurring definition may be saved.
	 *
	 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-5
	 */
	public function validateOnSave(array $recurring): bool {
		try {
			return $this->passesEveryCheck(recurring: $recurring);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CashflowRecurringGuard: validateOnSave failed — denying save (fail-closed)',
				[
					'recurId' => ($recurring['recurId'] ?? 'unknown'),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end validateOnSave()

	/**
	 * Run the consistency checks in order, stopping at the first that fails.
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 *
	 * @return bool True when every check passes.
	 */
	private function passesEveryCheck(array $recurring): bool {
		return $this->hasNonNegativeAmount(recurring: $recurring) === true
			&& $this->hasConsistentRecurrenceAnchor(recurring: $recurring) === true
			&& $this->hasValidValidityWindow(recurring: $recurring) === true
			&& $this->hasApplicableIndexation(recurring: $recurring) === true
			&& $this->hasConsistentContractWindow(recurring: $recurring) === true;
	}//end passesEveryCheck()

	/**
	 * Log why a save is denied, with the recurId, and deny it.
	 *
	 * @param string               $message   The denial reason.
	 * @param array<string, mixed> $recurring Recurring object array.
	 *
	 * @return bool Always false.
	 */
	private function denySave(string $message, array $recurring): bool {
		$this->logger->info($message, ['recurId' => ($recurring['recurId'] ?? 'unknown')]);
		return false;
	}//end denySave()

	/**
	 * Log why a save falls outside its linked Contract's window, with the
	 * recurId and the contractReference, and deny it.
	 *
	 * @param string               $message   The denial reason.
	 * @param array<string, mixed> $recurring Recurring object array.
	 *
	 * @return bool Always false.
	 */
	private function denyOutsideContract(string $message, array $recurring): bool {
		$this->logger->info(
			$message,
			['recurId' => ($recurring['recurId'] ?? 'unknown'), 'contractReference' => (string)($recurring['contractReference'] ?? '')]
		);
		return false;
	}//end denyOutsideContract()

	/**
	 * The base amount must be present and non-negative.
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 *
	 * @return bool True when standaardBedrag is a non-negative number.
	 */
	private function hasNonNegativeAmount(array $recurring): bool {
		if (isset($recurring['standardAmount']) === false
			|| is_numeric($recurring['standardAmount']) === false
			|| (float)$recurring['standardAmount'] < 0.0
		) {
			return $this->denySave(
				message: 'CashflowRecurringGuard: missing or negative standaardBedrag — denying save',
				recurring: $recurring
			);
		}

		return true;
	}//end hasNonNegativeAmount()

	/**
	 * The recurrence anchor must match the declared frequency (REQ-CF-005).
	 *
	 * Monthly items need a valid dagVanMaand (1-31); annual items need a valid
	 * maandVanJaar (1-12) and a valid dagVanMaand (1-31) to anchor the occurrence.
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 *
	 * @return bool True when the anchor is consistent with the frequency.
	 */
	private function hasConsistentRecurrenceAnchor(array $recurring): bool {
		$frequency = (string)($recurring['frequency'] ?? '');

		if (in_array($frequency, self::MONTHLY_FREQUENCIES, true) === true) {
			return $this->hasMonthlyAnchor(recurring: $recurring);
		}

		if ($frequency === 'ANNUALLY') {
			return $this->hasAnnualAnchor(recurring: $recurring);
		}

		return true;
	}//end hasConsistentRecurrenceAnchor()

	/**
	 * A monthly item needs a valid dagVanMaand (1-31).
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 *
	 * @return bool True when the day anchor is valid.
	 */
	private function hasMonthlyAnchor(array $recurring): bool {
		if ($this->isValidDayOfMonth(value: ($recurring['dagFromMonth'] ?? null)) === false) {
			return $this->denySave(
				message: 'CashflowRecurringGuard: MAANDELIJKS requires a valid dagVanMaand (1-31) — denying save',
				recurring: $recurring
			);
		}

		return true;
	}//end hasMonthlyAnchor()

	/**
	 * An annual item needs a valid maandVanJaar (1-12) and dagVanMaand (1-31, default 1).
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 *
	 * @return bool True when the month and day anchors are valid.
	 */
	private function hasAnnualAnchor(array $recurring): bool {
		if ($this->isValidMonthOfYear(value: ($recurring['monthOfYear'] ?? null)) === false) {
			return $this->denySave(
				message: 'CashflowRecurringGuard: JAARLIJKS requires a valid maandVanJaar (1-12) — denying save',
				recurring: $recurring
			);
		}

		if ($this->isValidDayOfMonth(value: ($recurring['dagFromMonth'] ?? 1)) === false) {
			return $this->denySave(
				message: 'CashflowRecurringGuard: JAARLIJKS requires a valid dagVanMaand (1-31) — denying save',
				recurring: $recurring
			);
		}

		return true;
	}//end hasAnnualAnchor()

	/**
	 * The geldigVan must parse, and geldigTot (if present) must not precede it.
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 *
	 * @return bool True when the validity window is well-formed.
	 */
	private function hasValidValidityWindow(array $recurring): bool {
		$from = $this->parseDate(value: (string)($recurring['validFrom'] ?? ''));
		if ($from === null) {
			return $this->denySave(
				message: 'CashflowRecurringGuard: missing or unparseable geldigVan — denying save',
				recurring: $recurring
			);
		}

		return $this->hasValidToFrom(recurring: $recurring, from: $from);
	}//end hasValidValidityWindow()

	/**
	 * A geldigTot, when present, must parse and must not precede geldigVan.
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 * @param DateTimeImmutable    $from      The parsed geldigVan.
	 *
	 * @return bool True when geldigTot is absent or well-ordered.
	 */
	private function hasValidToFrom(array $recurring, DateTimeImmutable $from): bool {
		$toRaw = $this->rawValidTo(recurring: $recurring);
		if ($toRaw === null) {
			// Indefinite window is valid.
			return true;
		}

		$tot = $this->parseDate(value: $toRaw);
		if ($tot === null) {
			return $this->denySave(message: 'CashflowRecurringGuard: unparseable geldigTot — denying save', recurring: $recurring);
		}

		if ($tot < $from) {
			return $this->denySave(message: 'CashflowRecurringGuard: geldigTot precedes geldigVan — denying save', recurring: $recurring);
		}

		return true;
	}//end hasValidToFrom()

	/**
	 * The geldigTot as given, or null when absent or empty (an indefinite window).
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 *
	 * @return string|null The raw validTo.
	 */
	private function rawValidTo(array $recurring): ?string {
		$toRaw = ($recurring['validTo'] ?? null);
		if ($toRaw === null || $toRaw === '') {
			return null;
		}

		return (string)$toRaw;
	}//end rawValidTo()

	/**
	 * CPI indexing is only applicable to annual recurrences (REQ-CF-005).
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 *
	 * @return bool True when indexation is FIXED, absent, or annual-applicable.
	 */
	private function hasApplicableIndexation(array $recurring): bool {
		$rule = (string)($recurring['indexationRule'] ?? 'FIXED');
		if ($rule !== 'CPI_PAST_YEAR') {
			return true;
		}

		if ((string)($recurring['frequency'] ?? '') !== 'ANNUALLY') {
			return $this->denySave(
				message: 'CashflowRecurringGuard: CPI_AFGELOPEN_JAAR indexing only applies to JAARLIJKS items — denying save',
				recurring: $recurring
			);
		}

		return true;
	}//end hasApplicableIndexation()

	/**
	 * When `contractReference` is set, `validFrom`/`validTo` must fall within
	 * the referenced `Contract`'s own `startDate`/`endDate` — open on
	 * whichever side the Contract itself leaves open (`budget-known-costs`
	 * REQ-BKC-002, `design.md` §3c). An absent `contractReference` skips this
	 * check entirely; an unresolvable Contract reference is treated the same
	 * way (nothing to bound against) rather than denied, since a dangling
	 * reference is a different, pre-existing data-integrity concern this
	 * change does not own.
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 *
	 * @return bool True when no contract window is violated.
	 *
	 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-002
	 */
	private function hasConsistentContractWindow(array $recurring): bool {
		$contractReference = (string)($recurring['contractReference'] ?? '');
		if ($contractReference === '') {
			return true;
		}

		$contract = $this->fetchContract(contractReference: $contractReference);
		if ($contract === null) {
			return true;
		}

		return $this->startsWithinContract(recurring: $recurring, contract: $contract);

	}//end hasConsistentContractWindow()

	/**
	 * validFrom must not precede the Contract's startDate (open when the
	 * Contract has none); then validTo is checked against its endDate.
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 * @param array<string, mixed> $contract  The linked Contract.
	 *
	 * @return bool True when the window falls within the Contract's.
	 *
	 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-002
	 */
	private function startsWithinContract(array $recurring, array $contract): bool {
		$from = $this->parseDate(value: (string)($recurring['validFrom'] ?? ''));
		if ($from === null) {
			// The hasValidValidityWindow() check already denies this case;
			// nothing further to check here.
			return true;
		}

		$contractStart = $this->parseDate(value: (string)($contract['startDate'] ?? ''));
		if ($contractStart !== null && $from < $contractStart) {
			return $this->denyOutsideContract(
				message: 'CashflowRecurringGuard: validFrom precedes the linked Contract\'s startDate — denying save',
				recurring: $recurring
			);
		}

		return $this->endsWithinContract(recurring: $recurring, contract: $contract);
	}//end startsWithinContract()

	/**
	 * validTo, when set, must not follow the Contract's endDate (open when the
	 * Contract has none).
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 * @param array<string, mixed> $contract  The linked Contract.
	 *
	 * @return bool True when validTo is absent or within the Contract's end.
	 *
	 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-002
	 */
	private function endsWithinContract(array $recurring, array $contract): bool {
		$to = $this->parsedValidTo(recurring: $recurring);
		if ($to === null) {
			// Indefinite, or unparseable — the hasValidValidityWindow() check already denies the latter.
			return true;
		}

		$contractEnd = $this->parseDate(value: (string)($contract['endDate'] ?? ''));
		if ($contractEnd !== null && $to > $contractEnd) {
			return $this->denyOutsideContract(
				message: 'CashflowRecurringGuard: validTo follows the linked Contract\'s endDate — denying save',
				recurring: $recurring
			);
		}

		return true;
	}//end endsWithinContract()

	/**
	 * The parsed geldigTot, or null when absent, empty or unparseable.
	 *
	 * @param array<string, mixed> $recurring Recurring object array.
	 *
	 * @return DateTimeImmutable|null The parsed validTo.
	 */
	private function parsedValidTo(array $recurring): ?DateTimeImmutable {
		$toRaw = $this->rawValidTo(recurring: $recurring);
		if ($toRaw === null) {
			return null;
		}

		return $this->parseDate(value: $toRaw);
	}//end parsedValidTo()

	/**
	 * Fetch the Contract a `contractReference` points at (by id, UUID or
	 * slug), returning null when it cannot be resolved.
	 *
	 * @param string $contractReference The `contractReference` value.
	 *
	 * @return array<string, mixed>|null The Contract object array, or null.
	 */
	private function fetchContract(string $contractReference): ?array {
		try {
			$result = ObjectIdentifier::findScoped(
				scoped: $this->objectService
					->setRegister($this->register())
					->setSchema(self::SCHEMA_CONTRACT),
				id: $contractReference
			);

			return $this->contractArray(result: $result);
		} catch (\Throwable) {
			return null;
		}//end try

	}//end fetchContract()

	/**
	 * The found Contract as an array, or null when none was found or it does
	 * not serialise to one.
	 *
	 * @param mixed $result The lookup result.
	 *
	 * @return array<string, mixed>|null The Contract object array, or null.
	 */
	private function contractArray(mixed $result): ?array {
		if ($result === null) {
			return null;
		}

		$serialized = $result->jsonSerialize();
		if (is_array($serialized) === true) {
			return $serialized;
		}

		return null;
	}//end contractArray()

	/**
	 * Resolve the OpenRegister register slug from app config.
	 *
	 * @return string The register slug, defaulting to `shillinq`.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;

	}//end register()

	/**
	 * Validate a day-of-month integer in the inclusive range 1-31.
	 *
	 * @param mixed $value The candidate day value.
	 *
	 * @return bool True when value is an integer in [1,31].
	 */
	private function isValidDayOfMonth(mixed $value): bool {
		return (is_int($value) === true && $value >= 1 && $value <= 31);
	}//end isValidDayOfMonth()

	/**
	 * Validate a month-of-year integer in the inclusive range 1-12.
	 *
	 * @param mixed $value The candidate month value.
	 *
	 * @return bool True when value is an integer in [1,12].
	 */
	private function isValidMonthOfYear(mixed $value): bool {
		return (is_int($value) === true && $value >= 1 && $value <= 12);
	}//end isValidMonthOfYear()

	/**
	 * Parse an ISO date string into an immutable date, or null on failure.
	 *
	 * @param string $value The date string.
	 *
	 * @return DateTimeImmutable|null The parsed date, or null when unparseable/empty.
	 */
	private function parseDate(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Throwable) {
			return null;
		}

	}//end parseDate()
}//end class
