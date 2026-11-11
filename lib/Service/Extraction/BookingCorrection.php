<?php

/**
 * Booking Correction
 *
 * The operator's committed GL-account booking for one docudesk extraction,
 * as posted back to docudesk to feed its booking history (REQ-GAC-005).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Extraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Extraction;

/**
 * One committed GL-account booking on an extraction.
 *
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 */
final class BookingCorrection {
	/**
	 * Hold the booking.
	 *
	 * @param string      $extractionId The docudesk `financialExtraction` object id.
	 * @param string      $accountCode  The committed GL-account code.
	 * @param string|null $accountLabel The account label, when known.
	 */
	public function __construct(
		public readonly string $extractionId,
		public readonly string $accountCode,
		public readonly ?string $accountLabel,
	) {
	}//end __construct()

	/**
	 * The correction body docudesk reads: the fields that carry a value.
	 *
	 * @return array{fields: array<string,string>} The body.
	 */
	public function body(): array {
		return [
			'fields' => array_filter(
				['glAccountCode' => $this->accountCode, 'glAccountLabel' => $this->accountLabel],
				static fn ($value): bool => ($value !== null)
			),
		];
	}//end body()
}//end class
