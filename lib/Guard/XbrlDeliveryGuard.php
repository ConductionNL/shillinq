<?php

/**
 * Shillinq XBRL delivery guard
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\Lifecycle\PostingRefusedException;

/**
 * The `requires` of XbrlInstance `deliver` (handed-off to submitted), and of BtwAangifte `deliver`:
 * refused until the filing carries the Digipoort delivery reference, which
 * only integriq's delivery report writes, so no one marks a filing submitted
 * that Digipoort never received.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md#requirement-submission-hands-the-instance-to-integriq-and-follows-its-reports-req-tdf-004
 */
class XbrlDeliveryGuard {

	/**
	 * Whether the instance may count as submitted.
	 *
	 * @param array<string, mixed> $instance The XbrlInstance under transition.
	 *
	 * @return bool True when the delivery reference is recorded.
	 *
	 * @throws PostingRefusedException When it is not.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.3
	 */
	public function requireDeliveryReference(array $instance): bool {
		if (trim((string) ($instance['digipoortReceiptId'] ?? '')) === '') {
			throw new PostingRefusedException(
				message: 'The filing has no Digipoort delivery reference yet. It counts as submitted once integriq reports the delivery.'
			);
		}

		return true;
	}//end requireDeliveryReference()

	/**
	 * Whether a VAT return may count as submitted: its filing reference is recorded.
	 *
	 * The `requires` of BtwAangifte `deliver` (handed-off to submitted), run
	 * by SbrFilingStatusListener once integriq reports the delivery of the
	 * return's XBRL instance.
	 *
	 * @param array<string, mixed> $vatReturn The BtwAangifte under transition.
	 *
	 * @return bool True when the Digipoort reference is recorded.
	 *
	 * @throws PostingRefusedException When it is not.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.4
	 */
	public function requireFilingReference(array $vatReturn): bool {
		if (trim((string) ($vatReturn['filingReference'] ?? '')) === '') {
			throw new PostingRefusedException(
				message: 'The VAT return has no Digipoort delivery reference yet. It counts as submitted once integriq reports the delivery.'
			);
		}

		return true;
	}//end requireFilingReference()
}//end class
