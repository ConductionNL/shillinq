<?php

/**
 * E-Invoice Pre-Send Validation Service
 *
 * Runs the KvK / BTW-nummer (VIES) / Peppol participant checks that MUST pass
 * before an ARInvoice is transmitted over Peppol (REQ-EINV-003). Reuses the
 * existing {@see \OCA\Shillinq\Service\ViesService} VIES integration and the
 * generalised {@see \OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface}
 * so the "does this debtor have a Peppol identity" question is answered the
 * same way for AR (this service) and PO ({@see \OCA\Shillinq\Service\PurchaseOrderService}).
 *
 * KvK / BTW-nummer failures are hard-blocking errors (no event is ever
 * emitted). A VIES outage degrades to a non-blocking warning — the operator
 * may still proceed (mirrors {@see ViesService}'s own outage-tolerant
 * design). A Peppol participant lookup miss is NOT a validation error: it is
 * a graceful fallback signal (mirrors the PO `null`-participant contract) —
 * the caller (EInvoiceService) offers PDF + email instead of hard-failing.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\EInvoice;

use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\ViesService;

/**
 * KvK / BTW-nummer / Peppol participant pre-send validation (REQ-EINV-003).
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class EInvoiceValidationService {
	/**
	 * KvK number pattern: exactly 8 digits.
	 *
	 * @var string
	 */
	private const KVK_PATTERN = '/^\d{8}$/';

	/**
	 * NL BTW-nummer pattern: NL + 9 digits + B + 2 digits.
	 *
	 * @var string
	 */
	private const VAT_PATTERN = '/^NL\d{9}B\d{2}$/';

	/**
	 * Construct the validation service.
	 *
	 * @param ViesService $vies VIES VAT-ID validator (reused, REQ-EINV-003).
	 * @param PeppolTransmissionPortInterface $peppolPort Generalised transmission port —
	 *                                                    its lookupParticipant() resolves
	 *                                                    (and implicitly confirms) the
	 *                                                    debtor's Peppol identity.
	 */
	public function __construct(
		private readonly ViesService $vies,
		private readonly PeppolTransmissionPortInterface $peppolPort,
	) {
	}//end __construct()

	/**
	 * Validate an ARInvoice's debtor identifiers ahead of a Send e-invoice attempt.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param array<string,mixed> $arInvoice The ARInvoice record (buyerLegalRegId,
	 *                                       buyerVatId, customerId).
	 *
	 * @return array{valid:bool,errors:array<int,array{field:string,code:string,message:string}>,warnings:array<int,array{field:string,code:string,message:string}>,peppolParticipantId:?string}
	 *
	 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 */
	public function validate(string $administrationId, array $arInvoice): array {
		$errors = [];
		$warnings = [];

		$kvk = trim((string)($arInvoice['buyerLegalRegId'] ?? ''));
		if (preg_match(self::KVK_PATTERN, $kvk) !== 1) {
			$errors[] = [
				'field' => 'buyerLegalRegId',
				'code' => 'kvk_invalid',
				'message' => 'KvK number must be exactly 8 digits',
			];
		}

		$vatId = trim((string)($arInvoice['buyerVatId'] ?? ''));
		if (preg_match(self::VAT_PATTERN, $vatId) !== 1) {
			$errors[] = [
				'field' => 'buyerVatId',
				'code' => 'vat_format_invalid',
				'message' => 'BTW-nummer must match the NL pattern (NL999999999B99)',
			];
		} else {
			$viesResult = $this->vies->validate(administrationId: $administrationId, vatId: $vatId);
			if ($viesResult['outage'] === true && $viesResult['valid'] !== true) {
				$warnings[] = [
					'field' => 'buyerVatId',
					'code' => 'vies_outage',
					'message' => 'VIES is temporarily unavailable — BTW-nummer format is valid; proceeding without live confirmation',
				];
			} elseif ($viesResult['valid'] !== true) {
				$errors[] = [
					'field' => 'buyerVatId',
					'code' => 'vat_vies_invalid',
					'message' => 'BTW-nummer failed VIES verification',
				];
			}
		}//end if

		$participantId = null;
		if ($errors === []) {
			$customerId = trim((string)($arInvoice['customerId'] ?? ''));
			$participantId = $this->peppolPort->lookupParticipant(
				administrationId: $administrationId,
				partyId: $customerId
			);
			if ($participantId === null) {
				$warnings[] = [
					'field' => 'buyerPeppolParticipantId',
					'code' => 'peppol_participant_not_found',
					'message' => 'No Peppol participant found for this debtor — falling back to PDF + email',
				];
			}
		}

		return [
			'valid' => ($errors === []),
			'errors' => $errors,
			'warnings' => $warnings,
			'peppolParticipantId' => $participantId,
		];

	}//end validate()
}//end class
