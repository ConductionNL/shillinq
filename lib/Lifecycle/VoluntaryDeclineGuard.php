<?php

/**
 * Voluntary Decline Guard
 *
 * Guards the ARInvoice `decline` and `decline-overdue` transitions: only a
 * voluntary school contribution may be declined. A parent may refuse a
 * contribution under the Wet vrijwillige ouderbijdrage; a compulsory invoice
 * stays owed, so a bookkeeper recording a refusal by mail or phone cannot close
 * one by accident.
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
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-014)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\Service\Dunning\VoluntaryContributionPolicy;

/**
 * Admits the decline transitions for a voluntary contribution only.
 *
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-014)
 */
class VoluntaryDeclineGuard {
	/**
	 * Precondition for `decline` and `decline-overdue`.
	 *
	 * @param array<string, mixed> $invoice The ARInvoice being transitioned.
	 *
	 * @return bool True when the invoice is a voluntary contribution.
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-014)
	 */
	public function requireVoluntary(array $invoice): bool {
		return (new VoluntaryContributionPolicy())->isVoluntary(invoice: $invoice);
	}//end requireVoluntary()
}//end class
