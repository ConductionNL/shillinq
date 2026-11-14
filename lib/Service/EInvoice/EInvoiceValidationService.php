<?php

/**
 * E-Invoice Pre-Send Validation Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
	 * Every finding: code => [field, message].
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const ISSUES = [
		'kvk_invalid' => ['buyerLegalRegId', 'KvK number must be exactly 8 digits'],
		'vat_format_invalid' => ['buyerVatId', 'BTW-nummer must match the NL pattern (NL999999999B99)'],
		'vies_outage' => [
			'buyerVatId',
			'VIES is temporarily unavailable — BTW-nummer format is valid; proceeding without live confirmation',
		],
		'vat_vies_invalid' => ['buyerVatId', 'BTW-nummer failed VIES verification'],
		'peppol_participant_not_found' => [
			'buyerPeppolParticipantId',
			'No Peppol participant found for this debtor — falling back to PDF + email',
		],
	];

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
		$check = new DebtorCheck();
		$check->administrationId = $administrationId;
		$this->checkKvk(check: $check, arInvoice: $arInvoice);
		$this->checkVatId(check: $check, arInvoice: $arInvoice);
		$participantId = null;
		if ($check->errors === []) {
			$participantId = $this->peppolParticipant(check: $check, arInvoice: $arInvoice);
		}

		return ['valid' => ($check->errors === []), 'errors' => $check->errors, 'warnings' => $check->warnings, 'peppolParticipantId' => $participantId];

	}//end validate()

	/**
	 * Refuse a KvK number that is not exactly 8 digits.
	 *
	 * @param DebtorCheck         $check     The findings so far.
	 * @param array<string,mixed> $arInvoice The ARInvoice record.
	 *
	 * @return void
	 */
	private function checkKvk(DebtorCheck $check, array $arInvoice): void {
		if (preg_match(self::KVK_PATTERN, trim((string)($arInvoice['buyerLegalRegId'] ?? ''))) !== 1) {
			$check->errors[] = $this->issue(code: 'kvk_invalid');
		}
	}//end checkKvk()

	/**
	 * Refuse a malformed BTW-nummer; confirm a well-formed one with VIES, which
	 * only warns while it is down.
	 *
	 * @param DebtorCheck         $check     The findings so far.
	 * @param array<string,mixed> $arInvoice The ARInvoice record.
	 *
	 * @return void
	 */
	private function checkVatId(DebtorCheck $check, array $arInvoice): void {
		$vatId = trim((string)($arInvoice['buyerVatId'] ?? ''));
		if (preg_match(self::VAT_PATTERN, $vatId) !== 1) {
			$check->errors[] = $this->issue(code: 'vat_format_invalid');
			return;
		}

		$this->confirmWithVies(check: $check, vatId: $vatId);
	}//end checkVatId()

	/**
	 * Confirm a well-formed BTW-nummer with VIES: an outage only warns.
	 *
	 * @param DebtorCheck $check The findings so far.
	 * @param string      $vatId The well-formed BTW-nummer.
	 *
	 * @return void
	 */
	private function confirmWithVies(DebtorCheck $check, string $vatId): void {
		$viesResult = $this->vies->validate(administrationId: $check->administrationId, vatId: $vatId);
		if ($viesResult['valid'] === true) {
			return;
		}

		if ($viesResult['outage'] === true) {
			$check->warnings[] = $this->issue(code: 'vies_outage');
			return;
		}

		$check->errors[] = $this->issue(code: 'vat_vies_invalid');
	}//end confirmWithVies()

	/**
	 * The debtor's Peppol participant id, warning when there is none.
	 *
	 * @param DebtorCheck         $check     The findings so far.
	 * @param array<string,mixed> $arInvoice The ARInvoice record.
	 *
	 * @return string|null
	 */
	private function peppolParticipant(DebtorCheck $check, array $arInvoice): string|null {
		$customerId = trim((string)($arInvoice['customerId'] ?? ''));
		$participantId = $this->peppolPort->lookupParticipant(administrationId: $check->administrationId, partyId: $customerId);
		if ($participantId === null) {
			$check->warnings[] = $this->issue(code: 'peppol_participant_not_found');
		}

		return $participantId;
	}//end peppolParticipant()

	/**
	 * One finding, by code.
	 *
	 * @param string $code The finding code (a key of ISSUES).
	 *
	 * @return array{field:string,code:string,message:string}
	 */
	private function issue(string $code): array {
		return ['field' => self::ISSUES[$code][0], 'code' => $code, 'message' => self::ISSUES[$code][1]];
	}//end issue()
}//end class
