<?php

/**
 * Doorsnijdingsverbod Validator
 *
 * Enforces the doorsnijdingsverbod (non-duplication rule, Wet Vpb art. 12bd
 * lid 2, REQ-IBA-004): costs allocated to an innovatiebox asset
 * (IBExpenseAllocation with exclusief_in_winstbepaling=true) MUST NOT be
 * deducted again in the regular general ledger. The validator scans both feeds
 * per administration + boekjaar and flags any (grootboekrekening, kostenplaats)
 * pair that appears in BOTH. The findings are non-blocking warnings during the
 * year, but block the year-end close until resolved.
 *
 * Reads use the real OpenRegister ObjectService API (find/findAll, ADR-022) and
 * are scoped to the supplied administration (server-resolved, never client
 * trust). The pure-detection step (detectDuplicates) takes plain arrays so it
 * is unit-testable without OpenRegister.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Detects innovatiebox/GL cost duplication (doorsnijdingsverbod, REQ-IBA-004).
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-004
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class DoorsnijdingsVerbodValidator {
	/**
	 * Construct the validator with OpenRegister's ObjectService injected
	 * (ADR-083 rule 1).
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param InnovatieboxAuditEventLogger|null $auditLogger Optional audit-event logger. When
	 *                                                       provided, every validateNoDuplication
	 *                                                       run emits a DoorsnijdingsVerbod.check_run
	 *                                                       event with the findings (REQ-IBA-008).
	 *                                                       Optional so the existing unit tests can
	 *                                                       construct the validator without the
	 *                                                       OpenRegister event chain.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly ?InnovatieboxAuditEventLogger $auditLogger = null,
	) {
	}//end __construct()

	/**
	 * Run the doorsnijdingsverbod check for an administration + boekjaar (REQ-IBA-004).
	 *
	 * Fetches the exclusive IBExpenseAllocation rows and the GL deduction lines
	 * (GLLine) for the year, then cross-checks (grootboekrekening, kostenplaats)
	 * pairs. Returns the findings and whether the year-end close may proceed.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param int $financialYear Fiscal year to validate.
	 *
	 * @return array{findings: array<int,array<string,mixed>>, blocking: bool, total: int}
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-004
	 */
	public function validateNoDuplication(string $administrationId, int $financialYear): array {
		$allocations = $this->fetchExclusiveAllocations(administrationId: $administrationId, financialYear: $financialYear);
		$glLines = $this->fetchGlDeductions(administrationId: $administrationId, financialYear: $financialYear);

		$findings = $this->detectDuplicates(allocations: $allocations, glLines: $glLines);

		if ($this->auditLogger !== null) {
			$totalAmount = 0.0;
			foreach ($findings as $finding) {
				$totalAmount += (float)($finding['amount'] ?? 0);
			}

			if ($findings !== []) {
				$auditReason = 'doorsnijdingsverbod_duplicate';
			} else {
				$auditReason = null;
			}

			$this->auditLogger->record(
				options: [
					'event_type' => InnovatieboxAuditEventLogger::EVENT_DOORSNIJDINGSVERBOD_CHECK_RUN,
					'administrationId' => $administrationId,
					'financialYear' => $financialYear,
					'reason' => $auditReason,
					'details' => [
						'findings' => $findings,
						'total_pairs' => count($findings),
						'total_bedrag' => $totalAmount,
						'blocking' => ($findings !== []),
					],
				]
			);
		}//end if

		return [
			'findings' => $findings,
			'blocking' => ($findings !== []),
			'total' => count($findings),
		];

	}//end validateNoDuplication()

	/**
	 * Pure-logic duplication detection (REQ-IBA-004).
	 *
	 * Builds the set of (grootboekrekening, kostenplaats) pairs present in the
	 * GL deduction lines, then flags any exclusive allocation whose pair is in
	 * that set. Each finding carries the pair, the allocated amount and a
	 * human-readable message.
	 *
	 * @param array<int,array<string,mixed>> $allocations Exclusive IBExpenseAllocation rows.
	 * @param array<int,array<string,mixed>> $glLines GL deduction lines (account + kostenplaats).
	 *
	 * @return array<int,array<string,mixed>> Duplication findings (empty when clean).
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-004
	 */
	public function detectDuplicates(array $allocations, array $glLines): array {
		$glPairs = [];
		foreach ($glLines as $line) {
			$account = (string)($line['accountNumber'] ?? ($line['generalLedgerAccount'] ?? ''));
			$place = (string)($line['costCentre'] ?? '');
			if ($account === '') {
				continue;
			}

			$glPairs[$account . '|' . $place] = true;
		}

		$findings = [];
		foreach ($allocations as $allocation) {
			if (($allocation['excluding_in_profitdetermination'] ?? false) !== true) {
				continue;
			}

			$account = (string)($allocation['generalLedgerAccount'] ?? '');
			$place = (string)($allocation['costCentre'] ?? '');
			if ($account === '') {
				continue;
			}

			if (isset($glPairs[$account . '|' . $place]) === true) {
				$amount = (float)($allocation['amount'] ?? 0);
				$placeText = '-';
				if ($place !== '') {
					$placeText = $place;
				}

				$findings[] = [
					'generalLedgerAccount' => $account,
					'costCentre' => $place,
					'amount' => $amount,
					'message' => sprintf(
						'EUR %s (account %s, kostenplaats %s) appears in both innovatiebox '
						. 'allocation AND GL regular deduction. Resolve conflict before year-end close.',
						number_format($amount, 0, ',', '.'),
						$account,
						$placeText
					),
				];
			}
		}//end foreach

		return $findings;
	}//end detectDuplicates()

	/**
	 * Fetch the exclusive IBExpenseAllocation rows for an administration + year.
	 *
	 * @param string $administrationId Administration scope.
	 * @param int $financialYear Fiscal year.
	 *
	 * @return array<int,array<string,mixed>> Exclusive allocation rows.
	 */
	private function fetchExclusiveAllocations(string $administrationId, int $financialYear): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('IBExpenseAllocation')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'financialYear' => $financialYear,
						'excluding_in_profitdetermination' => true,
					],
				]
			);

		return $rows;
	}//end fetchExclusiveAllocations()

	/**
	 * Fetch the GL deduction lines for an administration + year.
	 *
	 * @param string $administrationId Administration scope.
	 * @param int $financialYear Fiscal year.
	 *
	 * @return array<int,array<string,mixed>> GL lines carrying accountNumber + kostenplaats.
	 */
	private function fetchGlDeductions(string $administrationId, int $financialYear): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('GLLine')
			->findAll(['filters' => ['administrationId' => $administrationId, 'financialYear' => $financialYear]]);

		return $rows;
	}//end fetchGlDeductions()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
