<?php

/**
 * EMU-aangifte submission approval gate — ADR-031 / ADR-005 exception-path guard.
 *
 * Invoked by the x-openregister-lifecycle engine via the `requires` clause on the
 * EMUReport `indienen` transition (concept → ingediend). The transition to a CBS
 * submission is privileged: it MUST be gated on an explicit sign-off so an
 * unreviewed concept cannot be filed at the Belastingdienst/CBS. Single public
 * method, no persistence, per ADR-031 §"PHP guards remain a legitimate seam".
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
 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use Psr\Log\LoggerInterface;

/**
 * ADR-031 exception guard enforcing the EMUReport submission approval gate.
 *
 * The CBS XBRL indiening (REQ-EMU-006) is a privileged, irreversible external
 * action. requireApproval() is the lifecycle precondition: it permits the
 * `indienen` transition only when the concept carries a computed emuSaldo, the
 * BBV-reconciliation control has passed (REQ-EMU-009), and the report has not
 * already been submitted. Returns true to permit, false to block.
 *
 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
 */
class EmuSubmissionGuard {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Nextcloud logger for diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Lifecycle precondition for the EMUReport `indienen` transition.
	 *
	 * Permits submission only when (REQ-EMU-006 / REQ-EMU-009):
	 *  - the report is still a concept (idempotent — never re-submit);
	 *  - emuSaldoBerekend is present (the conversion has run);
	 *  - the BBV-aansluitingscontrole has not failed (mislukt blocks).
	 *
	 * @param array<string,mixed> $emuReport EMUReport object array from OpenRegister.
	 *
	 * @return bool True when submission to CBS is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function requireApproval(array $emuReport): bool {
		$status = (string)($emuReport['status'] ?? '');
		$balanceComputed = array_key_exists('emuBalanceCalculated', $emuReport)
			&& $emuReport['emuBalanceCalculated'] !== null;
		$reconciliation = (string)($emuReport['bbvReconciliationCheck'] ?? 'non-executed');

		$permitted = $status === 'draft'
			&& $balanceComputed === true
			&& $reconciliation !== 'failed';

		if ($permitted === false) {
			$this->logger->info(
				'EmuSubmissionGuard: indiening blocked',
				[
					'status' => $status,
					'saldoComputed' => $balanceComputed,
					'aansluitingscontrole' => $reconciliation,
				]
			);
		}

		return $permitted;
	}//end requireApproval()
}//end class
