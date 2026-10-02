<?php

/**
 * Billed Readings
 *
 * Keeps a meter reading on one invoice only: refuses a reading that is not
 * rated or already invoiced, and moves every reading an invoice billed to
 * invoiced with the invoice id (sales-usage-billing, REQ-USB-002).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Usage
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Usage;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use RuntimeException;

/**
 * The billed-once rule for meter readings.
 *
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 */
class BilledReadings {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param ObjectTransitionRunner $transitions   Runs MeterReading's `invoice` transition.
	 * @param SettingsService        $settings      Supplies the register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectTransitionRunner $transitions,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * Refuse a meter reading that is not rated, or already on an invoice.
	 *
	 * @param array<string,mixed> $reading The reading.
	 * @param string              $id      The reading id the request named.
	 *
	 * @return void
	 *
	 * @throws RuntimeException `Conflict:` for an invoiced reading, otherwise not rated.
	 *
	 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
	 */
	public function assertBillable(array $reading, string $id): void {
		$status = (string)($reading['status'] ?? 'unrated');
		if ($status === 'invoiced') {
			throw new RuntimeException(sprintf('Conflict: meter reading %s is already invoiced', $id));
		}

		if ($status !== 'rated') {
			throw new RuntimeException(sprintf('Meter reading %s is not rated yet', $id));
		}

	}//end assertBillable()

	/**
	 * Move every reading billed on the invoice to invoiced, with the invoice id.
	 *
	 * @param array<int,array<string,mixed>> $lineDrafts The invoice's line drafts.
	 * @param string                         $invoiceId  The saved invoice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
	 */
	public function markInvoiced(array $lineDrafts, string $invoiceId): void {
		$register = $this->settings->getRegisterSlug();
		foreach ($lineDrafts as $line) {
			if ((string)($line['sourceType'] ?? '') !== 'usage' || (string)($line['sourceId'] ?? '') === '') {
				continue;
			}

			$readingId = (string)$line['sourceId'];
			$this->objectService->setRegister($register)->setSchema('MeterReading')
				->patchObject($readingId, ['invoiceId' => $invoiceId], $register, 'MeterReading');
			$this->transitions->run(objectId: $readingId, action: 'invoice');
		}

	}//end markInvoiced()
}//end class
