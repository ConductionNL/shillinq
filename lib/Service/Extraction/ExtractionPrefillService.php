<?php

/**
 * Extraction Prefill Service
 *
 * Change receipt-extraction-consume (REQ-RXC-001 / REQ-RXC-004) — maps a
 * docudesk `nl.conduction.docudesk.extraction.completed` payload onto a
 * confidence-scored `SupplierInvoice` (docType supplier-invoice) or `Receipt`
 * (docType receipt) draft, and records an operator correction on commit.
 *
 * Field mapping is intentionally imperative (ADR-031 exception — the mapping
 * cross-walks docudesk's field vocabulary onto two different shillinq
 * schemas and cannot be expressed declaratively). Money fields on
 * SupplierInvoice are integer euro cents per ADR-022; Receipt keeps decimal
 * euros per its existing REQ-EC-002 shape.
 *
 * Fields that establish a NEW record (statusCode, administrationId,
 * receiptNumber, category) are only set when there is no existing draft to
 * update — a re-extraction (re-request, REQ-RXC-005) refreshes the extracted
 * values without disturbing the record's identity or lifecycle state. A
 * refresh also resets `humanCorrected`/`extractionStatus` back to
 * pending-review: a fresh machine extraction supersedes a prior manual
 * correction because the operator explicitly asked for it (re-request is
 * only offered on low-confidence drafts).
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
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Extraction;

/**
 * Maps docudesk extraction payloads onto shillinq drafts and records
 * operator corrections with provenance.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class ExtractionPrefillService {
	/**
	 * The SupplierInvoice schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_SUPPLIER_INVOICE = 'SupplierInvoice';

	/**
	 * The Receipt schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_RECEIPT = 'Receipt';

	/**
	 * Extraction status recorded on an uncommitted draft (REQ-RXC-001).
	 *
	 * @var string
	 */
	public const STATUS_PENDING_REVIEW = 'pending-review';

	/**
	 * Extraction status recorded once the operator commits (REQ-RXC-004).
	 *
	 * @var string
	 */
	public const STATUS_CONFIRMED = 'confirmed';

	/**
	 * Resolve the shillinq schema slug for a docudesk `docType`.
	 *
	 * @param string $docType `receipt` or `supplier-invoice`.
	 *
	 * @return string|null The schema slug, or NULL for an unknown docType.
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	public function schemaForDocType(string $docType): ?string {
		return match ($docType) {
			'supplier-invoice' => self::SCHEMA_SUPPLIER_INVOICE,
			'receipt' => self::SCHEMA_RECEIPT,
			default => null,
		};

	}//end schemaForDocType()

	/**
	 * Build (or refresh) a draft object payload from an extraction event.
	 *
	 * @param string $docType `receipt` or `supplier-invoice`.
	 * @param string $documentUri The docudesk source document URI.
	 * @param array<string,mixed> $fields The REQ-FIN-03 extracted field set.
	 * @param array<string,float> $fieldConfidence Per-field confidence (0..1).
	 * @param float $overallConfidence Aggregate confidence (0..1).
	 * @param array<string,mixed>|null $existingDraft The currently-stored draft, when one
	 *                                                was matched by sourceDocumentUri;
	 *                                                NULL when this is the first
	 *                                                extraction for this document.
	 * @param string $administrationId Server-resolved administration scope,
	 *                                 used only when creating.
	 *
	 * @return array<string,mixed> The draft payload to persist via OR ObjectService.
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	public function buildDraft(
		string $docType,
		string $documentUri,
		array $fields,
		array $fieldConfidence,
		float $overallConfidence,
		?array $existingDraft,
		string $administrationId,
	): array {
		$isCreate = ($existingDraft === null);
		$draft = ($existingDraft ?? []);

		if ($docType === 'supplier-invoice') {
			$draft = $this->mapSupplierInvoiceFields(fields: $fields, draft: $draft, isCreate: $isCreate, administrationId: $administrationId);
		} elseif ($docType === 'receipt') {
			$draft = $this->mapReceiptFields(
				fields: $fields,
				documentUri: $documentUri,
				draft: $draft,
				isCreate: $isCreate,
				administrationId: $administrationId
			);
		}

		$draft['sourceDocumentUri'] = $documentUri;
		$draft['fieldConfidence'] = $fieldConfidence;
		$draft['overallConfidence'] = $overallConfidence;
		$draft['extractedFieldsOriginal'] = [
			'fields' => $fields,
			'fieldConfidence' => $fieldConfidence,
		];
		// A fresh extraction (create OR refresh-by-re-request) always
		// supersedes any prior correction — the operator explicitly asked
		// for a new extraction attempt.
		$draft['humanCorrected'] = [];
		$draft['extractionStatus'] = self::STATUS_PENDING_REVIEW;

		return $draft;
	}//end buildDraft()

	/**
	 * Map the docudesk REQ-FIN-03 field set onto SupplierInvoice fields.
	 *
	 * @param array<string,mixed> $fields Extracted fields.
	 * @param array<string,mixed> $draft The draft accumulated so far.
	 * @param bool $isCreate Whether this is a brand-new draft.
	 * @param string $administrationId Administration scope (create only).
	 *
	 * @return array<string,mixed>
	 */
	private function mapSupplierInvoiceFields(array $fields, array $draft, bool $isCreate, string $administrationId): array {
		$draft['invoiceNumber'] = (string)($fields['invoiceNumber'] ?? ($draft['invoiceNumber'] ?? ''));
		$draft['supplierId'] = (string)($fields['supplierName'] ?? ($draft['supplierId'] ?? ''));
		$draft['invoiceDate'] = (string)($fields['issueDate'] ?? ($draft['invoiceDate'] ?? ''));
		if (isset($fields['dueDate']) === true) {
			$draft['dueDate'] = (string)$fields['dueDate'];
		} else {
			$draft['dueDate'] = ($draft['dueDate'] ?? null);
		}

		$draft['currency'] = (string)($fields['currency'] ?? ($draft['currency'] ?? 'EUR'));

		$draft['totalExclVat'] = $this->toCents(value: ($fields['totalExcl'] ?? null)) ?? ($draft['totalExclVat'] ?? null);
		$draft['totalVat'] = $this->toCents(value: ($fields['totalVat'] ?? null)) ?? ($draft['totalVat'] ?? null);
		$draft['totalInclVat'] = $this->toCents(value: ($fields['totalIncl'] ?? null)) ?? ($draft['totalInclVat'] ?? null);

		if ($isCreate === true) {
			$draft['statusCode'] = 'received';
			$draft['administrationId'] = $administrationId;
		}

		return $draft;
	}//end mapSupplierInvoiceFields()

	/**
	 * Map the docudesk REQ-FIN-03 field set onto Receipt fields (REQ-RXC-003).
	 *
	 * @param array<string,mixed> $fields Extracted fields.
	 * @param string $documentUri The docudesk document URI (also stored as photoUri).
	 * @param array<string,mixed> $draft The draft accumulated so far.
	 * @param bool $isCreate Whether this is a brand-new draft.
	 * @param string $administrationId Administration scope (create only).
	 *
	 * @return array<string,mixed>
	 */
	private function mapReceiptFields(array $fields, string $documentUri, array $draft, bool $isCreate, string $administrationId): array {
		$currency = (string)($fields['currency'] ?? ($draft['currency'] ?? 'EUR'));
		$rawAmount = $fields['totalIncl'] ?? ($draft['amount'] ?? null);
		if ($rawAmount !== null) {
			$amount = (float)$rawAmount;
		} else {
			$amount = 0.0;
		}

		$draft['photoUri'] = $documentUri;
		$draft['amount'] = $amount;
		$draft['currency'] = $currency;
		$draft['receiptDate'] = (string)($fields['issueDate'] ?? ($draft['receiptDate'] ?? ''));
		$draft['extractedText'] = $this->composeReceiptSummary(fields: $fields);

		// FX conversion is out of scope of this integration (REQ-EC-009 owns
		// multi-currency lookups); a EUR receipt converts 1:1, a
		// foreign-currency receipt keeps the extracted amount as a
		// best-effort placeholder the operator MUST review, never a
		// fabricated conversion.
		$draft['amountInBaseCurrency'] = $amount;

		if ($isCreate === true) {
			$draft['receiptNumber'] = 'REC-EXTRACT-' . substr(md5($documentUri), 0, 8);
			$draft['category'] = 'uncategorised';
			$draft['administrationId'] = $administrationId;
		}

		return $draft;
	}//end mapReceiptFields()

	/**
	 * Compose a readable extractedText summary from the REQ-FIN-03 field set
	 * (REQ-EC-002's T3 OCR placeholder population).
	 *
	 * @param array<string,mixed> $fields Extracted fields.
	 *
	 * @return string
	 */
	private function composeReceiptSummary(array $fields): string {
		$parts = [];
		if (empty($fields['supplierName']) === false) {
			$parts[] = (string)$fields['supplierName'];
		}

		if (isset($fields['totalIncl']) === true) {
			$currency = (string)($fields['currency'] ?? 'EUR');
			$parts[] = sprintf('%s %.2f', $currency, (float)$fields['totalIncl']);
		}

		if (empty($fields['issueDate']) === false) {
			$parts[] = (string)$fields['issueDate'];
		}

		return implode(' — ', $parts);
	}//end composeReceiptSummary()

	/**
	 * Record an operator correction on an existing draft (REQ-RXC-004).
	 *
	 * Compares every key of `$incomingFields` that is one of the draft's
	 * known extracted fields (i.e. present in the draft's fieldConfidence
	 * map) against the draft's CURRENT value; a changed value is merged in
	 * and its field name added to `humanCorrected` (union, never replacing
	 * prior entries). The immutable `extractedFieldsOriginal` snapshot is
	 * left untouched. `extractionStatus` becomes `confirmed`.
	 *
	 * The comparison — and therefore the provenance record — is computed
	 * server-side against the persisted draft, not trusted from the client,
	 * so a correction can never be silently spoofed or dropped.
	 *
	 * @param array<string,mixed> $existingDraft The persisted draft.
	 * @param array<string,mixed> $incomingFields The operator-submitted field values.
	 *
	 * @return array<string,mixed> The updated draft payload to persist.
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	public function recordCorrection(array $existingDraft, array $incomingFields): array {
		$confidenceKeys = array_keys((array)($existingDraft['fieldConfidence'] ?? []));
		$humanCorrected = (array)($existingDraft['humanCorrected'] ?? []);

		// The extractable field set spans both schemas' editable surface;
		// corrections outside fieldConfidence's keys (e.g. glAccount, which
		// docudesk never extracts) are still accepted as edits but are not
		// themselves confidence-scored, so they are recorded whenever the
		// caller explicitly lists them via $incomingFields — never inferred.
		$trackable = array_unique(array_merge($confidenceKeys, array_keys($incomingFields)));

		foreach ($trackable as $key) {
			if (array_key_exists($key, $incomingFields) === false) {
				continue;
			}

			$incomingValue = $incomingFields[$key];
			$currentValue = ($existingDraft[$key] ?? null);
			if ($incomingValue === $currentValue) {
				continue;
			}

			$existingDraft[$key] = $incomingValue;
			if (in_array($key, $humanCorrected, true) === false) {
				$humanCorrected[] = $key;
			}
		}//end foreach

		$existingDraft['humanCorrected'] = $humanCorrected;
		$existingDraft['extractionStatus'] = self::STATUS_CONFIRMED;

		return $existingDraft;
	}//end recordCorrection()

	/**
	 * Convert a decimal amount to integer euro cents (half-up), null-safe.
	 *
	 * @param mixed $value Decimal amount, or NULL.
	 *
	 * @return int|null
	 */
	private function toCents(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		return (int)round(((float)$value * 100), 0, PHP_ROUND_HALF_UP);
	}//end toCents()
}//end class
