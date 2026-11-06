<?php

/**
 * VatNumberCheck tests (tax-vat-number-check)
 *
 * The real ViesService over a stubbed HTTP client and the in-memory object
 * store; every record written is validated with Opis against the merged
 * register schema.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Tax;

use DomainException;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Tax\VatNumberCheck;
use OCA\Shillinq\Service\ViesService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-TVNC-001 and REQ-TVNC-003.
 */
class VatNumberCheckTest extends TestCase {

	private const ADMIN = 'adm-consultancy-nl';

	private const MULLER = 'c0570000-0000-4000-8000-000000000001';

	private const SOFTWAREHUIS = 'b0570000-0000-4000-8000-000000000002';

	private const BILL = '5b570000-0000-4000-8000-000000000003';

	private InMemoryObjectServiceStub $store;

	/**
	 * VIES calls made, as URLs.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Seed a German customer, a Belgian supplier and a Belgian bill.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectServiceStub(
			[
				'CustomerMaster'  => [
					['id' => self::MULLER, 'customerId' => 'DEB-0107', 'legalName' => 'Kunstverlag Müller GmbH', 'email' => 'buchhaltung@kunstverlag-mueller.de', 'vatId' => 'DE000000000', 'administrationId' => self::ADMIN, 'lifecycleState' => 'active'],
				],
				'Payee'           => [
					['id' => self::SOFTWAREHUIS, 'vendorNumber' => 'CRED-0042', 'name' => 'Softwarehuis BVBA', 'paymentTermDays' => 30, 'vatNumber' => 'BE0000000000', 'administrationId' => self::ADMIN, 'lifecycleState' => 'active'],
				],
				'SupplierInvoice' => [
					['id' => self::BILL, 'invoiceNumber' => 'SH-2026-118', 'currency' => 'EUR', 'invoiceDate' => '2026-09-28', 'statusCode' => 'received', 'sellerVatId' => 'BE0000000000', 'administrationId' => self::ADMIN],
				],
				'ViesValidation'  => [],
			]
		);

	}//end setUp()

	/**
	 * Scenario "A bookkeeper checks a German customer".
	 *
	 * @return void
	 */
	public function testABookkeeperChecksAGermanCustomer(): void {
		$result = $this->check(answer: '{"valid": true, "name": "Kunstverlag Müller GmbH", "address": "Köln", "requestIdentifier": "WAPIAAAAZ"}')
			->checkRecord('customer', $this->record('CustomerMaster', self::MULLER));

		$this->assertSame('valid', $result['status']);
		$this->assertSame($result['checkedAt'], $result['lastValidAt']);
		$this->assertStringContainsString('/DE/vat/000000000', $this->calls[0]);

		$customer = $this->record('CustomerMaster', self::MULLER);
		$this->assertSame('valid', $customer['vatIdValidationStatus']);
		$this->assertSame($result['checkedAt'], $customer['vatIdValidatedAt']);
		$this->assertNotEmpty($customer['vatIdValidUntil']);
		$this->assertSame([], RegisterSchema::errors('CustomerMaster', $this->withoutId($customer)));

	}//end testABookkeeperChecksAGermanCustomer()

	/**
	 * An invalid number is recorded as invalid; an earlier valid date stays.
	 *
	 * @return void
	 */
	public function testAnInvalidNumberIsRecordedAsInvalid(): void {
		$this->store->patchObject(objectId: self::MULLER, data: ['vatIdValidatedAt' => '2026-08-01T10:00:00+00:00'], register: 'shillinq', schema: 'CustomerMaster');

		$result = $this->check(answer: '{"valid": false}')->checkRecord('customer', $this->record('CustomerMaster', self::MULLER));

		$this->assertSame('invalid', $result['status']);
		$this->assertSame('2026-08-01T10:00:00+00:00', $result['lastValidAt']);
		$customer = $this->record('CustomerMaster', self::MULLER);
		$this->assertSame('invalid', $customer['vatIdValidationStatus']);
		$this->assertSame([], RegisterSchema::errors('CustomerMaster', $this->withoutId($customer)));

	}//end testAnInvalidNumberIsRecordedAsInvalid()

	/**
	 * Scenario "VIES is down": not reachable, with the last valid date ten days ago, also on a second try.
	 *
	 * @return void
	 */
	public function testViesIsDown(): void {
		$tenDaysAgo = gmdate('c', (time() - (10 * 86400)));
		$this->store->saveObject(['vatId' => 'BE0000000000', 'valid' => true, 'outage' => false, 'validationTimestamp' => $tenDaysAgo, 'validUntil' => $tenDaysAgo, 'name' => 'Softwarehuis BVBA', 'address' => 'Gent', 'requestId' => 'R1', 'administrationId' => self::ADMIN], register: 'shillinq', schema: 'ViesValidation');
		$check = $this->check(answer: null);

		foreach ([1, 2] as $attempt) {
			$result = $check->checkRecord('supplier', $this->record('Payee', self::SOFTWAREHUIS));
			$this->assertSame('vies_outage', $result['status'], 'attempt ' . $attempt);
			$this->assertSame($tenDaysAgo, $result['lastValidAt'], 'attempt ' . $attempt);
		}

		$payee = $this->record('Payee', self::SOFTWAREHUIS);
		$this->assertSame('vies_outage', $payee['vatIdValidationStatus']);
		$this->assertSame($tenDaysAgo, $payee['vatIdValidatedAt']);
		$this->assertArrayNotHasKey('vatIdValidUntil', $payee);
		$this->assertSame([], RegisterSchema::errors('Payee', $this->withoutId($payee)));

	}//end testViesIsDown()

	/**
	 * A record without a number is refused before VIES is asked.
	 *
	 * @return void
	 */
	public function testARecordWithoutANumberIsRefused(): void {
		$this->expectException(DomainException::class);
		try {
			$this->check(answer: '{"valid": true}')->checkRecord('supplier', ['id' => 'x', 'administrationId' => self::ADMIN, 'vatNumber' => ' ']);
		} finally {
			$this->assertSame([], $this->calls);
		}

	}//end testARecordWithoutANumberIsRefused()

	/**
	 * Scenario "A Belgian bill arrives": the seller's number is checked and the result kept on the invoice.
	 *
	 * @return void
	 */
	public function testABelgianBillArrives(): void {
		$fields = $this->check(answer: '{"valid": true, "name": "Softwarehuis BVBA"}')->checkSellerOnArrival($this->record('SupplierInvoice', self::BILL));

		$this->assertSame('valid', $fields['sellerVatIdValidationStatus']);
		$bill = $this->record('SupplierInvoice', self::BILL);
		$this->assertSame('valid', $bill['sellerVatIdValidationStatus']);
		$this->assertSame(substr(gmdate('c'), 0, 10), substr((string)$bill['sellerVatIdValidatedAt'], 0, 10));
		$this->assertSame([], RegisterSchema::errors('SupplierInvoice', $this->withoutId($bill)));

	}//end testABelgianBillArrives()

	/**
	 * Dutch, non-EU and empty seller numbers are not sent to VIES.
	 *
	 * @return void
	 */
	public function testDutchAndNonEuSellersAreNotChecked(): void {
		$check = $this->check(answer: '{"valid": true}');
		foreach (['NL812345678B01', 'GB123456789', 'CHE-123.456.789', ''] as $number) {
			$this->assertNull($check->checkSellerOnArrival(['id' => self::BILL, 'administrationId' => self::ADMIN, 'sellerVatId' => $number]), $number);
		}

		$this->assertSame([], $this->calls);

	}//end testDutchAndNonEuSellersAreNotChecked()

	/**
	 * Build the check over the real ViesService; a null answer means VIES cannot be reached.
	 *
	 * @param string|null $answer The VIES response body.
	 *
	 * @return VatNumberCheck
	 */
	private function check(?string $answer): VatNumberCheck {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($answer): IResponse {
				$this->calls[] = $url;
				if ($answer === null) {
					throw new RuntimeException('VIES unreachable');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($answer);

				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);

		return new VatNumberCheck(new ViesService($config, $clients, $logger, $this->store), $this->store, $settings, $logger);

	}//end check()

	/**
	 * Read a record back from the store.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>
	 */
	private function record(string $schema, string $id): array {
		foreach ($this->store->setSchema($schema)->findAll() as $row) {
			$row = is_array($row) ? $row : $row->jsonSerialize();
			if (($row['id'] ?? '') === $id) {
				return $row;
			}
		}

		$this->fail($schema . ' ' . $id . ' not found');

	}//end record()

	/**
	 * The record without its store id (the register validates the payload).
	 *
	 * @param array<string,mixed> $row The record.
	 *
	 * @return array<string,mixed>
	 */
	private function withoutId(array $row): array {
		unset($row['id'], $row['@self']);

		return $row;

	}//end withoutId()
}//end class
