<?php

/**
 * Intercompany Transaction Guard
 *
 * ADR-031 exception-path lifecycle guards for the treasury / in-house-bank
 * registers (bookkeeping-treasury-ihb, T3). Two preconditions are referenced
 * from schema x-openregister-lifecycle transitions because they require
 * cross-administration / cross-schema lookups that OpenRegister's declarative
 * `requires:` clause cannot yet express:
 *
 *  - canPost():         an IntercompanyTransaction may only post when BOTH the
 *                       sending and receiving administration have an open fiscal
 *                       period for the posting date (REQ-IHB-005, REQ-PC-004).
 *  - canActivateLoan(): an IntercompanyLoan may be drawn down; the OECD
 *                       arm's-length warning (rate materially above market) is
 *                       a warning, not a hard block (REQ-IHB-004), so this
 *                       method permits the transition while logging the warning.
 *
 * ADR-031 exception reason: cross-administration period-status lookups and the
 * arm's-length comparison are not expressible in the declarative lifecycle DSL.
 * When the engine gains those capabilities, replace these references with
 * declarative conditions and delete this file.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-treasury-ihb/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guards for IntercompanyTransaction posting and
 * IntercompanyLoan activation.
 *
 * Referenced from the IntercompanyTransaction schema
 * x-openregister-lifecycle transitions.post.requires as
 * OCA\Shillinq\Lifecycle\IntercompanyTransactionGuard::canPost and from the
 * IntercompanyLoan schema transitions.activate.requires as
 * OCA\Shillinq\Lifecycle\IntercompanyTransactionGuard::canActivateLoan.
 *
 * @spec openspec/specs/bookkeeping-treasury-ihb/spec.md
 */
class IntercompanyTransactionGuard {
	/**
	 * OECD arm's-length warning threshold added to a comparable market rate.
	 *
	 * A fixed loan rate more than this fraction above EURIBOR-3M is flagged as
	 * potentially non-arm's-length (REQ-IHB-004). This is a warning threshold,
	 * not a hard limit.
	 *
	 * @var float
	 */
	private const ARMS_LENGTH_SPREAD_WARNING = 0.03;

	/**
	 * Indicative EURIBOR-3M reference used for the arm's-length warning when no
	 * live rate snapshot is available (v1 manual-entry mode). Replaced by the
	 * openconnector rate feed in T4.
	 *
	 * @var float
	 */
	private const INDICATIVE_EURIBOR_3M = 0.045;

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns true iff both administrations' fiscal periods are open for posting.
	 *
	 * REQ-IHB-005: an intercompany movement must materialise balanced entries
	 * on both ledgers simultaneously and MUST be rejected when either the
	 * sending or receiving administration has a closed period for the posting
	 * date (REQ-PC-004). Mirrors ExpenseClaimGuard's FiscalYear lookup: a
	 * missing FiscalYear register (T1 state) or no covering year permits the
	 * post; a covering year that is not 'open' denies it.
	 *
	 * Fail-closed: returns false on malformed input (missing posting date or
	 * administrations) per CWE-863.
	 *
	 * @param string $transactionId The IntercompanyTransaction.id
	 *                              (call-signature parity with the
	 *                              lifecycle engine).
	 * @param array<string,mixed>|null $object The IntercompanyTransaction being transitioned.
	 *
	 * @return bool True when both administrations' periods are open and the movement may post.
	 *
	 * @spec openspec/specs/bookkeeping-treasury-ihb/spec.md
	 */
	public function canPost(string $transactionId, ?array $object = null): bool {
		if ($object === null) {
			$object = $this->resolveTransaction(transactionId: $transactionId);
		}

		if ($object === null) {
			return false;
		}

		$postingDate = (string)($object['postingDate'] ?? '');
		$fromAdmin = (string)($object['fromAdministrationId'] ?? '');
		$toAdmin = (string)($object['toAdministrationId'] ?? '');

		if ($postingDate === '' || $fromAdmin === '' || $toAdmin === '') {
			$this->logger->warning(
				'IntercompanyTransactionGuard: missing posting date or administration — denying post (fail-closed)',
				['transactionId' => $transactionId]
			);
			return false;
		}

		return $this->isPeriodOpen(administrationId: $fromAdmin, postingDate: $postingDate)
			&& $this->isPeriodOpen(administrationId: $toAdmin, postingDate: $postingDate);

	}//end canPost()

	/**
	 * Returns true to permit loan drawdown, logging an OECD arm's-length warning.
	 *
	 * REQ-IHB-004: a fixed loan rate more than three percentage points above a
	 * comparable market rate (EURIBOR-3M) is flagged as potentially
	 * non-arm's-length. This is a warning, not a hard block — the transition is
	 * always permitted so the treasurer may proceed with documented justification
	 * stored in docudesk; the warning is surfaced in the audit log.
	 *
	 * @param string $loanId The IntercompanyLoan.id (call-signature parity).
	 * @param array<string,mixed>|null $object The IntercompanyLoan being activated.
	 *
	 * @return bool Always true (drawdown permitted); warnings are logged.
	 *
	 * @spec openspec/specs/bookkeeping-treasury-ihb/spec.md
	 */
	public function canActivateLoan(string $loanId, ?array $object = null): bool {
		if ($object === null) {
			return true;
		}

		if (($object['rateType'] ?? 'fixed') !== 'fixed') {
			return true;
		}

		$fixedRate = $object['fixedRate'] ?? null;
		if (is_numeric($fixedRate) === false) {
			return true;
		}

		if (((float)$fixedRate) > (self::INDICATIVE_EURIBOR_3M + self::ARMS_LENGTH_SPREAD_WARNING)) {
			$this->logger->warning(
				'IntercompanyTransactionGuard: loan rate exceeds EURIBOR-3M + 3% — review OECD arm\'s-length documentation',
				[
					'loanId' => $loanId,
					'fixedRate' => (float)$fixedRate,
				]
			);
		}

		return true;
	}//end canActivateLoan()

	/**
	 * Check whether the FiscalYear covering a posting date for an administration is open.
	 *
	 * Returns true (permit) when the FiscalYear register is unavailable (T1 state)
	 * or no FiscalYear covers the date; returns true only when a covering year is
	 * in state 'open', false otherwise.
	 *
	 * @param string $administrationId The administration whose period is checked.
	 * @param string $postingDate The posting date (Y-m-d).
	 *
	 * @return bool True when the period is open (or no covering year is seeded).
	 */
	private function isPeriodOpen(string $administrationId, string $postingDate): bool {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$register = $this->getRegisterSlug();

			$years = $objectService
				->setRegister(register: $register)
				->setSchema(schema: 'FiscalYear')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'startDate' => ['lte' => $postingDate],
							'endDate' => ['gte' => $postingDate],
						],
					]
				);
		} catch (\Throwable) {
			// FiscalYear register not yet available (T1 state) — permit posting.
			$this->logger->debug(
				'IntercompanyTransactionGuard: FiscalYear register not present (T1 state) — posting permitted',
				['administrationId' => $administrationId]
			);
			return true;
		}//end try

		if (count($years) === 0) {
			// No FiscalYear covers the posting date — permit with a warning.
			$this->logger->warning(
				'IntercompanyTransactionGuard: no FiscalYear covers posting date — permitting post without period check',
				['administrationId' => $administrationId, 'postingDate' => $postingDate]
			);
			return true;
		}

		$year = reset($years);
		return is_array($year) === true && ($year['state'] ?? '') === 'open';
	}//end isPeriodOpen()

	/**
	 * Resolve an IntercompanyTransaction object by id via the ObjectService.
	 *
	 * @param string $transactionId The IntercompanyTransaction.id to look up.
	 *
	 * @return array<string,mixed>|null The transaction object, or null when not found / unavailable.
	 */
	private function resolveTransaction(string $transactionId): ?array {
		if ($transactionId === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$register = $this->getRegisterSlug();

			$rows = $objectService
				->setRegister(register: $register)
				->setSchema(schema: 'IntercompanyTransaction')
				->findAll(['filters' => ['id' => $transactionId]]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'IntercompanyTransactionGuard: transaction lookup failed — denying post (fail-closed)',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end resolveTransaction()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()
}//end class
