<?php

/**
 * Unit tests for DirectDebitFileService and the generate action.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Receivables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Receivables;

use DOMDocument;
use DOMXPath;
use OCA\Shillinq\Lifecycle\Action\GenerateDirectDebitFileAction;
use OCA\Shillinq\Receivables\SepaPain008Generator;
use OCA\Shillinq\Receivables\SepaPain008Validator;
use OCA\Shillinq\Service\Receivables\DirectDebitFileService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * REQ-SDD-012: generate writes the validated file onto the batch, or keeps it draft.
 *
 * @covers \OCA\Shillinq\Service\Receivables\DirectDebitFileService
 * @covers \OCA\Shillinq\Lifecycle\Action\GenerateDirectDebitFileAction
 * @uses   \OCA\Shillinq\Receivables\SepaPain008Generator
 * @uses   \OCA\Shillinq\Receivables\SepaPain008Validator
 * @uses   \OCA\Shillinq\PaymentRun\RenderedFile
 * @uses   \OCA\Shillinq\Validation\IbanValidator
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class DirectDebitFileServiceTest extends TestCase {

	private const ADMIN = 'adm-1';

	private const BATCH = [
		'id' => 'b-1',
		'messageId' => 'DD-20261001093000-CORE-RCUR',
		'creationDateTime' => '2026-10-01T09:30:00+00:00',
		'requestedCollectionDate' => '2026-10-15',
		'scheme' => 'CORE',
		'sequenceType' => 'RCUR',
		'collectionCount' => 0,
		'controlSum' => 0,
		'status' => 'generated',
		'administrationId' => self::ADMIN,
	];

	/**
	 * A mandate.
	 *
	 * @param string               $id     The id.
	 * @param array<string, mixed> $fields Fields over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function mandate(string $id, array $fields=[]): array {
		return $fields + [
			'id' => $id,
			'mandateReference' => 'MR-' . $id,
			'creditorIdentifier' => 'NL98ZZZ111111111111',
			'signedAt' => '2026-01-05',
			'debtorIban' => 'NL91ABNA0417164300',
			'debtorName' => 'Klant ' . $id,
			'administrationId' => self::ADMIN,
		];

	}//end mandate()

	/**
	 * A collection.
	 *
	 * @param string $id      The id.
	 * @param string $mandate The mandate id.
	 * @param string $invoice The invoice id.
	 * @param float  $amount  The amount.
	 * @param string $batch   The batch id.
	 *
	 * @return array<string, mixed>
	 */
	private static function collection(string $id, string $mandate, string $invoice, float $amount, string $batch='b-1'): array {
		return [
			'id' => $id,
			'mandateId' => $mandate,
			'invoiceId' => $invoice,
			'amount' => $amount,
			'currency' => 'EUR',
			'sequenceType' => 'RCUR',
			'requestedCollectionDate' => '2026-10-15',
			'endToEndId' => 'DD-' . $invoice,
			'status' => 'scheduled',
			'submittedInBatchId' => $batch,
			'administrationId' => self::ADMIN,
		];

	}//end collection()

	/**
	 * The service over the records, the given administration and these mandates.
	 *
	 * @param array<string, mixed>       $administration The administration record.
	 * @param list<array<string, mixed>> $mandates       The mandates.
	 *
	 * @return DirectDebitFileService
	 */
	private function service(array $administration, array $mandates): DirectDebitFileService {
		$store = new InMemoryObjectServiceStub(
			data: [
				'Administration' => [$administration],
				'SepaMandate' => $mandates,
				'ARInvoice' => [
					['id' => 'inv-118', 'invoiceNumber' => 'F-2026-118', 'administrationId' => self::ADMIN],
					['id' => 'inv-119', 'invoiceNumber' => 'F-2026-119', 'administrationId' => self::ADMIN],
				],
				'DirectDebitCollection' => [
					self::collection(id: 'dc-2', mandate: 'm-2', invoice: 'inv-119', amount: 500.0),
					self::collection(id: 'dc-1', mandate: 'm-1', invoice: 'inv-118', amount: 750.0),
					self::collection(id: 'dc-other', mandate: 'm-1', invoice: 'inv-118', amount: 99.0, batch: 'b-2'),
				],
			],
			findAllRendersEntities: true
		);
		$location = $this->createConfiguredMock(originalClassName: RegisterLocation::class, configuration: ['getRegisterSlug' => 'shillinq']);

		return new DirectDebitFileService(
			objectService: $store,
			registerLocation: $location,
			generator: new SepaPain008Generator(),
			validator: new SepaPain008Validator()
		);

	}//end service()

	/**
	 * An administration with its creditor details.
	 *
	 * @param array<string, mixed> $fields Fields over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function administration(array $fields=[]): array {
		return $fields + [
			'id' => '4b7a2f1e-0000-4000-8000-000000000001',
			'administrationCode' => self::ADMIN,
			'name' => 'Voorbeeld Consultancy B.V.',
			'primaryIban' => 'NL20INGB0001234567',
			'sepaCreditorIdentifier' => 'NL98ZZZ999999999999',
		];

	}//end administration()

	/**
	 * The batch's two collections totalling 1,250.00 are written, valid, onto the batch.
	 *
	 * @return void
	 */
	public function testGenerateStoresTheValidatedFileOnTheBatch(): void {
		$service = $this->service(administration: self::administration(), mandates: [self::mandate(id: 'm-1'), self::mandate(id: 'm-2')]);

		$batch = (new GenerateDirectDebitFileAction(files: $service))->execute(self::BATCH, ['status' => 'draft'] + self::BATCH, [], 'generate');

		$this->assertSame(2, $batch['collectionCount']);
		$this->assertSame(1250.0, $batch['controlSum']);
		$this->assertSame('generated', $batch['status']);
		$this->assertSame([], RegisterSchema::errors('DirectDebitBatch', array_diff_key($batch, ['id' => true])));

		$document = new DOMDocument();
		$document->loadXML($batch['pain008Xml']);
		$this->assertTrue($document->schemaValidate(SepaPain008Validator::schemaPath()));
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('p', SepaPain008Generator::PAIN_NS);
		$this->assertSame('1250.00', $xpath->evaluate('string(//p:GrpHdr/p:CtrlSum)'));
		$this->assertSame('NL98ZZZ999999999999', $xpath->evaluate('string(//p:CdtrSchmeId//p:Othr/p:Id)'));
		$this->assertSame('Voorbeeld Consultancy B.V.', $xpath->evaluate('string(//p:Cdtr/p:Nm)'));
		$this->assertSame(['DD-inv-118', 'DD-inv-119'], [
			$xpath->evaluate('string(//p:DrctDbtTxInf[1]//p:EndToEndId)'),
			$xpath->evaluate('string(//p:DrctDbtTxInf[2]//p:EndToEndId)'),
		]);
		$this->assertSame('F-2026-119', $xpath->evaluate('string(//p:DrctDbtTxInf[2]//p:Ustrd)'));
		$this->assertSame('MR-m-2', $xpath->evaluate('string(//p:DrctDbtTxInf[2]//p:MndtId)'));

	}//end testGenerateStoresTheValidatedFileOnTheBatch()

	/**
	 * Without its own identifier, the administration uses the one its mandates agree on.
	 *
	 * @return void
	 */
	public function testTheMandatesCreditorIdentifierIsTheFallback(): void {
		$service = $this->service(administration: self::administration(fields: ['sepaCreditorIdentifier' => '']), mandates: [self::mandate(id: 'm-1'), self::mandate(id: 'm-2')]);

		$xml = $service->generate(batch: self::BATCH)['pain008Xml'];

		$this->assertStringContainsString('<Id>NL98ZZZ111111111111</Id>', $xml);

	}//end testTheMandatesCreditorIdentifierIsTheFallback()

	/**
	 * Mandates that disagree give no identifier, so the file is refused.
	 *
	 * @return void
	 */
	public function testMandatesThatDisagreeGiveNoIdentifier(): void {
		$service = $this->service(
			administration: self::administration(fields: ['sepaCreditorIdentifier' => '']),
			mandates: [self::mandate(id: 'm-1'), self::mandate(id: 'm-2', fields: ['creditorIdentifier' => 'NL98ZZZ222222222222'])]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The creditor identifier "" is not a SEPA creditor identifier.');
		$service->generate(batch: self::BATCH);

	}//end testMandatesThatDisagreeGiveNoIdentifier()

	/**
	 * A wrong check digit on a mandate's IBAN keeps the batch draft, naming the mandate.
	 *
	 * @return void
	 */
	public function testAnInvalidIbanRefusesTheTransitionNamingTheMandate(): void {
		$service = $this->service(
			administration: self::administration(),
			mandates: [self::mandate(id: 'm-1'), self::mandate(id: 'm-2', fields: ['debtorIban' => 'NL92ABNA0417164300'])]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The pain.008 file was not stored: Mandate MR-m-2: the debtor IBAN NL92ABNA0417164300 is not valid.');
		(new GenerateDirectDebitFileAction(files: $service))->execute(self::BATCH, self::BATCH, [], 'generate');

	}//end testAnInvalidIbanRefusesTheTransitionNamingTheMandate()

	/**
	 * A batch without an id has no collections and is refused.
	 *
	 * @return void
	 */
	public function testABatchWithoutCollectionsIsRefused(): void {
		$service = $this->service(administration: self::administration(), mandates: [self::mandate(id: 'm-1')]);
		$batch = self::BATCH;
		unset($batch['id']);

		$this->expectExceptionMessage('The batch has no collections.');
		$service->generate(batch: $batch);

	}//end testABatchWithoutCollectionsIsRefused()

	/**
	 * The stored file is offered under the message id; a batch without one has none.
	 *
	 * @return void
	 */
	public function testTheStoredFileIsNamedAfterTheMessage(): void {
		$service = $this->service(administration: self::administration(), mandates: []);
		$file = $service->storedFile(batch: ['pain008Xml' => '<Document/>', 'messageId' => 'DD 2026/10']);

		$this->assertNotNull($file);
		$this->assertSame('DD-2026-10.pain008.xml', $file->fileName);
		$this->assertSame('<Document/>', $file->content);
		$this->assertSame('text/xml', $file->mimeType);
		$this->assertSame('direct-debit-batch.pain008.xml', $service->storedFile(batch: ['pain008Xml' => '<x/>'])?->fileName);
		$this->assertNull($service->storedFile(batch: ['pain008Xml' => ' ']));
		$this->assertNull($service->storedFile(batch: []));

	}//end testTheStoredFileIsNamedAfterTheMessage()
}//end class
