<?php

/**
 * InvoiceDocumentParties test
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Sales\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sales\InvoiceDocument;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Sales\InvoiceDocument\EpcQrPayload;
use OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentParties;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The parties an invoice document is rendered with.
 *
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.2
 */
class InvoiceDocumentPartiesTest extends TestCase {

	/**
	 * Rows per schema the store answers with.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * A resolver over an in-memory store keyed by schema. find() misses, so
	 * every lookup goes through the business-key filter, as for a code.
	 *
	 * @param bool $failing Whether every read throws.
	 *
	 * @return InvoiceDocumentParties
	 */
	private function parties(bool $failing = false): InvoiceDocumentParties {
		$schema = '';
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnSelf();
		$store->method('setSchema')->willReturnCallback(
			static function (string $name) use (&$schema, $store): ObjectServiceInterface {
				$schema = $name;
				return $store;
			}
		);
		$store->method('find')->willThrowException(new RuntimeException('not a uuid'));
		$store->method('findAll')->willReturnCallback(
			function (array $query) use (&$schema, $failing): array {
				if ($failing === true) {
					throw new RuntimeException('register down');
				}

				$filters = (array)($query['filters'] ?? []);
				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static fn (array $row): bool => array_intersect_assoc($filters, $row) === $filters
					)
				);
			}
		);
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new InvoiceDocumentParties($store, $location, $this->createMock(LoggerInterface::class));
	}//end parties()

	/**
	 * The seeded administration, customer and layout.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows = [
			'Administration' => [
				['id' => 'a-1', 'administrationCode' => 'ADM-001', 'name' => 'Gemeente Zuidoost', 'primaryIban' => 'NL91ABNA0417164300', 'primaryBic' => 'ABNANL2A', 'defaultLanguage' => 'nl'],
			],
			'CustomerMaster' => [
				['id' => 'c-1', 'customerId' => 'C-1', 'administrationId' => 'ADM-001', 'legalName' => 'Advies B.V.', 'vatId' => 'NL003726244B01', 'language' => 'en'],
				['id' => 'c-2', 'customerId' => 'C-2', 'administrationId' => 'ADM-OTHER', 'legalName' => 'Elders B.V.'],
			],
			'InvoiceLayout' => [
				['id' => 'l-1', 'administrationId' => 'ADM-001', 'variant' => 'modern', 'primaryColor' => '#1B4F72', 'showQr' => true],
			],
		];
	}//end setUp()

	/**
	 * The creditor carries the administration's IBAN, so the EPC QR is drawn
	 * for a EUR invoice; the customer and layout come with it.
	 *
	 * @return void
	 */
	public function testAnInvoiceGetsItsCreditorRecipientLayoutAndLanguage(): void {
		$parties = $this->parties()->forInvoice(['administrationId' => 'ADM-001', 'customerId' => 'C-1', 'invoiceNumber' => 'F-1']);

		self::assertSame(['legalName' => 'Gemeente Zuidoost', 'iban' => 'NL91ABNA0417164300', 'bic' => 'ABNANL2A'], $parties['creditor']);
		self::assertSame(['legalName' => 'Advies B.V.', 'vatNumber' => 'NL003726244B01', 'language' => 'en'], $parties['recipient']);
		self::assertSame('modern', $parties['layout']['variant']);
		self::assertSame('nl', $parties['language']);

		$epc = (new EpcQrPayload())->forTransfer(creditor: $parties['creditor'], currency: 'EUR', amount: 24200.0, reference: 'F-1');
		self::assertNotNull($epc);
		self::assertStringContainsString("NL91ABNA0417164300\nEUR24200.00", (string)$epc);
	}//end testAnInvoiceGetsItsCreditorRecipientLayoutAndLanguage()

	/**
	 * Another administration's customer never becomes the recipient.
	 *
	 * @return void
	 */
	public function testACustomerOfAnotherAdministrationIsLeftOut(): void {
		$parties = $this->parties()->forInvoice(['administrationId' => 'ADM-001', 'customerId' => 'C-2']);

		self::assertSame([], $parties['recipient']);
		self::assertSame('Gemeente Zuidoost', $parties['creditor']['legalName']);
	}//end testACustomerOfAnotherAdministrationIsLeftOut()

	/**
	 * An unreadable register leaves every part empty instead of failing the document.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterLeavesThePartsEmpty(): void {
		$parties = $this->parties(failing: true)->forInvoice(['administrationId' => 'ADM-001', 'customerId' => 'C-1']);

		self::assertSame(['creditor' => [], 'recipient' => [], 'layout' => [], 'language' => ''], $parties);
	}//end testAnUnreadableRegisterLeavesThePartsEmpty()
}//end class
