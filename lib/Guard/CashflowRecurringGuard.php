<?php

/**
 * Cashflow Recurring Cost Guard
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

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
			if ($this->hasNonNegativeAmount(recurring: $recurring) === false) {
				return false;
			}

			if ($this->hasConsistentRecurrenceAnchor(recurring: $recurring) === false) {
				return false;
			}

			if ($this->hasValidValidityWindow(recurring: $recurring) === false) {
				return false;
			}

			if ($this->hasApplicableIndexation(recurring: $recurring) === false) {
				return false;
			}

			return $this->hasConsistentContractWindow(recurring: $recurring);
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
			$this->logger->info(
				'CashflowRecurringGuard: missing or negative standaardBedrag — denying save',
				['recurId' => ($recurring['recurId'] ?? 'unknown')]
			);
			return false;
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
			if ($this->isValidDayOfMonth(value: ($recurring['dagFromMonth'] ?? null)) === false) {
				$this->logger->info(
					'CashflowRecurringGuard: MAANDELIJKS requires a valid dagVanMaand (1-31) — denying save',
					['recurId' => ($recurring['recurId'] ?? 'unknown')]
				);
				return false;
			}
		}

		if ($frequency === 'ANNUALLY') {
			$month = ($recurring['monthOfYear'] ?? null);
			if (is_int($month) === false || $month < 1 || $month > 12) {
				$this->logger->info(
					'CashflowRecurringGuard: JAARLIJKS requires a valid maandVanJaar (1-12) — denying save',
					['recurId' => ($recurring['recurId'] ?? 'unknown')]
				);
				return false;
			}

			if ($this->isValidDayOfMonth(value: ($recurring['dagFromMonth'] ?? 1)) === false) {
				$this->logger->info(
					'CashflowRecurringGuard: JAARLIJKS requires a valid dagVanMaand (1-31) — denying save',
					['recurId' => ($recurring['recurId'] ?? 'unknown')]
				);
				return false;
			}
		}//end if

		return true;
	}//end hasConsistentRecurrenceAnchor()

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
			$this->logger->info(
				'CashflowRecurringGuard: missing or unparseable geldigVan — denying save',
				['recurId' => ($recurring['recurId'] ?? 'unknown')]
			);
			return false;
		}

		$toRaw = ($recurring['validTo'] ?? null);
		if ($toRaw === null || $toRaw === '') {
			// Indefinite window is valid.
			return true;
		}

		$tot = $this->parseDate(value: (string)$toRaw);
		if ($tot === null) {
			$this->logger->info(
				'CashflowRecurringGuard: unparseable geldigTot — denying save',
				['recurId' => ($recurring['recurId'] ?? 'unknown')]
			);
			return false;
		}

		if ($tot < $from) {
			$this->logger->info(
				'CashflowRecurringGuard: geldigTot precedes geldigVan — denying save',
				['recurId' => ($recurring['recurId'] ?? 'unknown')]
			);
			return false;
		}

		return true;
	}//end hasValidValidityWindow()

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
			$this->logger->info(
				'CashflowRecurringGuard: CPI_AFGELOPEN_JAAR indexing only applies to JAARLIJKS items — denying save',
				['recurId' => ($recurring['recurId'] ?? 'unknown')]
			);
			return false;
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

		$from = $this->parseDate(value: (string)($recurring['validFrom'] ?? ''));
		if ($from === null) {
			// The hasValidValidityWindow() check already denies this case;
			// nothing further to check here.
			return true;
		}

		$contractStart = $this->parseDate(value: (string)($contract['startDate'] ?? ''));
		if ($contractStart !== null && $from < $contractStart) {
			$this->logger->info(
				'CashflowRecurringGuard: validFrom precedes the linked Contract\'s startDate — denying save',
				['recurId' => ($recurring['recurId'] ?? 'unknown'), 'contractReference' => $contractReference]
			);
			return false;
		}

		$toRaw = ($recurring['validTo'] ?? null);
		if ($toRaw === null || $toRaw === '') {
			return true;
		}

		$to = $this->parseDate(value: (string)$toRaw);
		if ($to === null) {
			// The hasValidValidityWindow() check already denies this case.
			return true;
		}

		$contractEnd = $this->parseDate(value: (string)($contract['endDate'] ?? ''));
		if ($contractEnd !== null && $to > $contractEnd) {
			$this->logger->info(
				'CashflowRecurringGuard: validTo follows the linked Contract\'s endDate — denying save',
				['recurId' => ($recurring['recurId'] ?? 'unknown'), 'contractReference' => $contractReference]
			);
			return false;
		}

		return true;

	}//end hasConsistentContractWindow()

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
			$result = $this->objectService
				->setRegister($this->register())
				->setSchema(self::SCHEMA_CONTRACT)
				->find($contractReference);

			if ($result === null) {
				return null;
			}

			$serialized = $result->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}

			return null;
		} catch (\Throwable) {
			return null;
		}//end try

	}//end fetchContract()

	/**
	 * Resolve the OpenRegister register slug from app config.
	 *
	 * @return string The register slug, defaulting to `shillinq`.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
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
