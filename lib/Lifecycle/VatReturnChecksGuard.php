<?php

/**
 * Shillinq VatReturnChecksGuard
 *
 * The `requires` of BtwAangifte.submit (REQ-TVRB-001, design D3): a return
 * may be submitted only while none of its blocking checks fails. The refusal
 * names every failing blocking check and what makes it fail, through a
 * PostingRefusedException, which RegisterRequiresGuardAdapter shows as the
 * transition's refusal and VATReturnController answers as a 409.
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
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\Service\Vat\VatReturnCheckService;

/**
 * Submit precondition of a BTW return: no failing blocking check.
 *
 * @spec openspec/changes/tax-vat-return-from-books/specs/bookkeeping-vat-btw-filing/spec.md#requirement-checks-run-on-the-vat-return-and-block-filing-when-they-fail-req-tvrb-001
 */
class VatReturnChecksGuard {

	/**
	 * Constructor.
	 *
	 * @param VatReturnCheckService $checks The VAT return checks.
	 */
	public function __construct(
		private readonly VatReturnCheckService $checks,
	) {
	}//end __construct()

	/**
	 * Allow submitting a return only while none of its blocking checks fails.
	 *
	 * @param string|array<string,mixed> $returnOrId The BtwAangifte, or its id.
	 *
	 * @return bool True when the return may be submitted.
	 *
	 * @throws PostingRefusedException When a blocking check fails, naming it.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
	 */
	public function canSubmit(string|array $returnOrId): bool {
		$returnId = $returnOrId;
		if (is_array($returnOrId) === true) {
			$returnId = (string)($returnOrId['id'] ?? ($returnOrId['@self']['id'] ?? ''));
		}

		if ($returnId === '') {
			return false;
		}

		$failures = $this->checks->blockingFailures(returnId: $returnId);
		if ($failures === []) {
			return true;
		}

		$named = [];
		foreach ($failures as $failure) {
			$named[] = rtrim($failure['statement'], '.') . ': ' . implode(', ', $failure['offenders']) . '.';
		}

		throw new PostingRefusedException('The return cannot be submitted until these checks pass. ' . implode(' ', $named));
	}//end canSubmit()
}//end class
