<?php

/**
 * Voluntary Contribution Policy
 *
 * The Wet vrijwillige ouderbijdrage (in force since 2021-08-01) makes a school's
 * parental contribution voluntary: a child takes part whether or not it is
 * paid, and a school may not pressure a parent into paying. For dunning that
 * means one reminder at most, with no collection costs, no statutory interest
 * and never a hand-over to a collection agency.
 *
 * This class decides that from the invoice alone and does no I/O of its own,
 * so every place a reminder can start asks the same question and gets the same
 * answer. The one reminder it allows carries the voluntary letter
 * (VoluntaryReminderTemplate, decision D28), and a declined contribution gets
 * none.
 * DunningRunService consults it in tickInvoice(), executeStage() and
 * transferToIncasso().
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use RuntimeException;

/**
 * Caps the dunning of a voluntary school contribution at one plain reminder.
 *
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-008)
 */
final class VoluntaryContributionPolicy {
	/**
	 * The only stage a voluntary contribution may reach.
	 *
	 * @var integer
	 */
	public const ONLY_STAGE = 1;

	/**
	 * Why a stage or a hand-over was refused.
	 *
	 * @var string
	 */
	public const REFUSAL = 'A voluntary contribution gets one reminder at most, without costs, and is never handed to a collection agency.';

	/**
	 * The lifecycle state of a voluntary contribution the parent refused.
	 *
	 * @var string
	 */
	public const DECLINED_STATE = 'declined';

	/**
	 * Why a run on a declined contribution was refused.
	 *
	 * @var string
	 */
	public const DECLINED_REFUSAL = 'The parent said they will not pay this voluntary contribution, so it is not reminded.';

	/**
	 * True when the invoice was declined: closed, and never reminded.
	 *
	 * @param array<string, mixed>|null $invoice The invoice, or null when it could not be read.
	 *
	 * @return bool True for a declined invoice.
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-014)
	 */
	public function isDeclined(?array $invoice): bool {
		return ($invoice['lifecycleState'] ?? null) === self::DECLINED_STATE;
	}//end isDeclined()

	/**
	 * True when the invoice is a voluntary school contribution.
	 *
	 * An invoice without a contribution group was never raised as one, so a
	 * missing or unreadable invoice is dunned as before.
	 *
	 * @param array<string, mixed>|null $invoice The invoice, or null when it could not be read.
	 *
	 * @return bool True for a voluntary contribution.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-008)
	 */
	public function isVoluntary(?array $invoice): bool {
		if ($invoice === null) {
			return false;
		}

		$contribution = ($invoice['contribution'] ?? null);

		return is_array($contribution) === true && ($contribution['voluntary'] ?? false) === true;
	}//end isVoluntary()

	/**
	 * True when this stage may run for this invoice.
	 *
	 * @param array<string, mixed>|null $invoice The invoice.
	 * @param int $stageNr The stage about to run.
	 * @param int $runsSoFar How many runs this invoice already had.
	 *
	 * @return bool True when the stage may run.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-008)
	 */
	public function allowsStage(?array $invoice, int $stageNr, int $runsSoFar): bool {
		if ($this->isVoluntary(invoice: $invoice) === false) {
			return true;
		}

		return $stageNr === self::ONLY_STAGE && $runsSoFar === 0;
	}//end allowsStage()

	/**
	 * The dispatch parameters with collection costs and interest removed.
	 *
	 * @param array<string, mixed> $params The run parameters.
	 *
	 * @return array<string, mixed> The parameters without costs.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-008)
	 */
	public function stripCosts(array $params): array {
		$params['collectionCostAmount'] = null;
		$params['interestAmount'] = null;

		return $params;
	}//end stripCosts()

	/**
	 * The run parameters a voluntary contribution's one reminder goes out with.
	 *
	 * Refuses a declined invoice, a stage above the first and a second run.
	 * The run that is allowed loses its costs and carries the voluntary letter
	 * in place of whatever template or text the stage or the caller gave: the
	 * generic letter names a term and an IBAN.
	 *
	 * @param array<string, mixed>|null $invoice The invoice.
	 * @param array<string, mixed> $params The run parameters.
	 * @param int $runsSoFar How many runs this invoice already had.
	 * @param VoluntaryReminderTemplate $reminder The voluntary letter.
	 *
	 * @return array<string, mixed> The parameters for the one allowed run.
	 *
	 * @throws RuntimeException When no run is allowed.
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-012)
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-014)
	 */
	public function prepareRun(?array $invoice, array $params, int $runsSoFar, VoluntaryReminderTemplate $reminder): array {
		if ($this->isDeclined(invoice: $invoice) === true) {
			throw new RuntimeException(self::DECLINED_REFUSAL);
		}

		if ($this->allowsStage(invoice: $invoice, stageNr: (int)($params['stageNr'] ?? 1), runsSoFar: $runsSoFar) === false) {
			throw new RuntimeException(self::REFUSAL);
		}

		$letter = $reminder->render(invoice: ($invoice ?? []));
		$params = $this->stripCosts(params: $params);
		$params['templateId'] = $letter['templateId'];
		$params['renderedSubject'] = $letter['subject'];
		$params['renderedBody'] = $letter['body'];

		return $params;
	}//end prepareRun()
}//end class
