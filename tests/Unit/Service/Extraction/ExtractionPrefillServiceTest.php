<?php

/**
 * Unit tests for ExtractionPrefillService (REQ-RXC-001 / REQ-RXC-004).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Extraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-001
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Extraction;

use OCA\Shillinq\Service\Extraction\ExtractionPrefillService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ExtractionPrefillService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ExtractionPrefillServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var ExtractionPrefillService
	 */
	private ExtractionPrefillService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new ExtractionPrefillService();

	}//end setUp()

	/**
	 * SchemaForDocType maps the two known docTypes and rejects unknown ones.
	 *
	 * @return void
	 */
	public function testSchemaForDocType(): void {
		self::assertSame('SupplierInvoice', $this->service->schemaForDocType('supplier-invoice'));
		self::assertSame('Receipt', $this->service->schemaForDocType('receipt'));
		self::assertNull($this->service->schemaForDocType('unknown'));

	}//end testSchemaForDocType()

	/**
	 * REQ-RXC-001: creating a SupplierInvoice draft maps fields, converts
	 * money to integer cents, and sets create-only fields.
	 *
	 * @return void
	 */
	public function testBuildDraftCreatesSupplierInvoice(): void {
		$draft = $this->service->buildDraft(
			docType: 'supplier-invoice',
			documentUri: 'docudesk://attachments/x/invoice.pdf',
			fields: [
				'invoiceNumber' => 'F-2026-88',
				'supplierName' => 'ACME B.V.',
				'issueDate' => '2026-02-05',
				'currency' => 'EUR',
				'totalExcl' => 100.0,
				'totalVat' => 21.0,
				'totalIncl' => 121.0,
			],
			fieldConfidence: ['invoiceNumber' => 0.97, 'totalIncl' => 0.9],
			overallConfidence: 0.93,
			existingDraft: null,
			administrationId: 'adm-1'
		);

		self::assertSame('F-2026-88', $draft['invoiceNumber']);
		self::assertSame('ACME B.V.', $draft['supplierId']);
		self::assertSame('2026-02-05', $draft['invoiceDate']);
		self::assertSame(10000, $draft['totalExclVat']);
		self::assertSame(2100, $draft['totalVat']);
		self::assertSame(12100, $draft['totalInclVat']);
		self::assertSame('received', $draft['statusCode']);
		self::assertSame('adm-1', $draft['administrationId']);
		self::assertSame('docudesk://attachments/x/invoice.pdf', $draft['sourceDocumentUri']);
		self::assertSame(0.93, $draft['overallConfidence']);
		self::assertSame('pending-review', $draft['extractionStatus']);
		self::assertSame([], $draft['humanCorrected']);
		self::assertSame('F-2026-88', $draft['extractedFieldsOriginal']['fields']['invoiceNumber']);
		self::assertSame(121.0, $draft['extractedFieldsOriginal']['fields']['totalIncl']);
		self::assertSame(['invoiceNumber' => 0.97, 'totalIncl' => 0.9], $draft['extractedFieldsOriginal']['fieldConfidence']);

	}//end testBuildDraftCreatesSupplierInvoice()

	/**
	 * REQ-RXC-003: creating a Receipt draft maps fields, composes
	 * extractedText, and mirrors documentUri onto photoUri.
	 *
	 * @return void
	 */
	public function testBuildDraftCreatesReceipt(): void {
		$draft = $this->service->buildDraft(
			docType: 'receipt',
			documentUri: 'docudesk://attachments/y/receipt.jpg',
			fields: [
				'supplierName' => 'Cafe de Jong',
				'totalIncl' => 45.0,
				'currency' => 'EUR',
				'issueDate' => '2026-02-10',
			],
			fieldConfidence: ['totalIncl' => 0.9, 'receiptDate' => 0.85],
			overallConfidence: 0.88,
			existingDraft: null,
			administrationId: 'adm-1'
		);

		self::assertSame('docudesk://attachments/y/receipt.jpg', $draft['photoUri']);
		self::assertSame(45.0, $draft['amount']);
		self::assertSame(45.0, $draft['amountInBaseCurrency']);
		self::assertSame('EUR', $draft['currency']);
		self::assertSame('2026-02-10', $draft['receiptDate']);
		self::assertSame('uncategorised', $draft['category']);
		self::assertSame('adm-1', $draft['administrationId']);
		self::assertStringContainsString('Cafe de Jong', $draft['extractedText']);
		self::assertStringContainsString('45.00', $draft['extractedText']);
		self::assertStringStartsWith('REC-EXTRACT-', $draft['receiptNumber']);

	}//end testBuildDraftCreatesReceipt()

	/**
	 * REQ-RXC-005: refreshing an existing draft updates values but does NOT
	 * touch create-only fields (statusCode, administrationId, category,
	 * receiptNumber) and resets humanCorrected/extractionStatus.
	 *
	 * @return void
	 */
	public function testBuildDraftRefreshDoesNotResetCreateOnlyFields(): void {
		$existing = [
			'id' => 'draft-1',
			'invoiceNumber' => 'F-OLD',
			'statusCode' => 'matched',
			'administrationId' => 'adm-1',
			'humanCorrected' => ['invoiceNumber'],
			'extractionStatus' => 'confirmed',
		];

		$draft = $this->service->buildDraft(
			docType: 'supplier-invoice',
			documentUri: 'docudesk://attachments/x/invoice.pdf',
			fields: ['invoiceNumber' => 'F-NEW'],
			fieldConfidence: ['invoiceNumber' => 0.95],
			overallConfidence: 0.95,
			existingDraft: $existing,
			administrationId: 'adm-1'
		);

		self::assertSame('draft-1', $draft['id']);
		self::assertSame('F-NEW', $draft['invoiceNumber']);
		// StatusCode is a create-only field for the mapper — an in-flight
		// matching lifecycle state is never clobbered by a re-extraction.
		self::assertSame('matched', $draft['statusCode']);
		self::assertSame([], $draft['humanCorrected'], 'a fresh extraction supersedes a prior correction');
		self::assertSame('pending-review', $draft['extractionStatus']);

	}//end testBuildDraftRefreshDoesNotResetCreateOnlyFields()

	/**
	 * REQ-RXC-004: an operator correction overrides the value, records
	 * provenance (union, never duplicate), and leaves the original
	 * extraction snapshot untouched.
	 *
	 * @return void
	 */
	public function testRecordCorrectionTracksChangedFieldsAndPreservesSnapshot(): void {
		$existing = [
			'id' => 'draft-1',
			'glAccount' => '',
			'invoiceNumber' => 'F-2026-88',
			'fieldConfidence' => ['invoiceNumber' => 0.97, 'glAccount' => 0.55],
			'extractedFieldsOriginal' => ['fields' => ['invoiceNumber' => 'F-2026-88'], 'fieldConfidence' => ['invoiceNumber' => 0.97]],
			'humanCorrected' => [],
			'extractionStatus' => 'pending-review',
		];

		$updated = $this->service->recordCorrection(
			existingDraft: $existing,
			incomingFields: ['glAccount' => '4500', 'invoiceNumber' => 'F-2026-88']
		);

		self::assertSame('4500', $updated['glAccount']);
		self::assertSame(['glAccount'], $updated['humanCorrected'], 'only the actually-changed field is recorded');
		self::assertSame('confirmed', $updated['extractionStatus']);
		// The original snapshot is untouched.
		self::assertSame('F-2026-88', $updated['extractedFieldsOriginal']['fields']['invoiceNumber']);
		self::assertSame(0.97, $updated['extractedFieldsOriginal']['fieldConfidence']['invoiceNumber']);

	}//end testRecordCorrectionTracksChangedFieldsAndPreservesSnapshot()

	/**
	 * A second, different correction is unioned with a prior one — never
	 * replacing the existing humanCorrected provenance.
	 *
	 * @return void
	 */
	public function testRecordCorrectionUnionsWithPriorCorrections(): void {
		$existing = [
			'id' => 'draft-1',
			'fieldConfidence' => ['invoiceNumber' => 0.4, 'glAccount' => 0.55],
			'invoiceNumber' => 'F-2026-CORRECTED',
			'glAccount' => '',
			'humanCorrected' => ['invoiceNumber'],
			'extractionStatus' => 'confirmed',
		];

		$updated = $this->service->recordCorrection(
			existingDraft: $existing,
			incomingFields: ['glAccount' => '4500']
		);

		self::assertEqualsCanonicalizing(['invoiceNumber', 'glAccount'], $updated['humanCorrected']);

	}//end testRecordCorrectionUnionsWithPriorCorrections()
}//end class
