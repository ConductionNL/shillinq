<?php

/**
 * Contract of ExtractionPrefillService
 *
 * The public surface of ExtractionPrefillService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of ExtractionPrefillService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExtractionPrefillServiceInterface {

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
	): array;

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
	public function recordCorrection(array $existingDraft, array $incomingFields): array;

	/**
	 * Resolve the shillinq schema slug for a docudesk `docType`.
	 *
	 * @param string $docType `receipt` or `supplier-invoice`.
	 *
	 * @return string|null The schema slug, or NULL for an unknown docType.
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	public function schemaForDocType(string $docType): ?string;
}//end interface
