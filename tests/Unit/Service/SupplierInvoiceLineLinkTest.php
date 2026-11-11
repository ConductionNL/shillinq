<?php

/**
 * Golden record of SupplierInvoiceService::linkInvoiceLineToPo(): the
 * refused requests (with their messages, before any read or write), the
 * administration access check, and the stamped line, matchedPoIds and save
 * of an allowed link.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins linkInvoiceLineToPo() before it is split into named steps.
 *
 * @covers \OCA\Shillinq\Service\SupplierInvoiceService
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SupplierInvoiceLineLinkTest extends TestCase {

	private const ADMIN = 'adm-1';

	/**
	 * Saves the service made.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the service over an in-memory store holding $invoices.
	 *
	 * @param array<int,array<string,mixed>> $invoices SupplierInvoice rows.
	 *
	 * @return SupplierInvoiceService
	 */
	private function service(array $invoices): SupplierInvoiceService {
		$this->saved = [];
		$stub = new InMemoryObjectServiceStub(data: ['SupplierInvoice' => $invoices], saveSink: $this->saved);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === self::ADMIN
		);
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new SupplierInvoiceService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $stub,
			checks: new SupplierInvoiceChecks($stub, $settings),
			vatNumberCheck: null,
		);

	}//end service()

	/**
	 * The stored invoice every case runs against.
	 *
	 * @return array<string,mixed>
	 */
	private static function invoice(): array {
		return [
			'id' => 'inv-1',
			'administrationId' => self::ADMIN,
			'matchedPoIds' => ['po-9', ' po-9 ', '', 'po-8'],
			'lines' => [
				['lineNumber' => 1, 'description' => 'Paper'],
				'not a line',
				['description' => 'Toner'],
				['lineNumber' => 7, 'description' => 'Ink', 'linkedPoId' => 'old'],
			],
		];

	}//end invoice()

	/**
	 * Refused requests and the message each is refused with.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int, 3: string}>
	 */
	public static function refusals(): array {
		return [
			'no administration'          => ['', 'inv-1', 1, 'administrationId is required'],
			'inaccessible administration' => ['adm-2', 'inv-1', 1, 'Supplier invoice not found'],
			'no invoice id'              => [self::ADMIN, '', 1, 'Supplier invoice not found'],
			'line number zero'           => [self::ADMIN, 'inv-1', 0, 'Invalid invoiceLineNumber'],
			'unknown invoice'            => [self::ADMIN, 'inv-404', 1, 'Supplier invoice not found'],
			'unknown line'               => [self::ADMIN, 'inv-1', 5, 'Invoice line not found'],
			'array slot that is no line' => [self::ADMIN, 'inv-1', 2, 'Invoice line not found'],
		];

	}//end refusals()

	/**
	 * Each refusal throws its message and saves nothing.
	 *
	 * @param string $administrationId The administration.
	 * @param string $invoiceId        The invoice.
	 * @param int    $lineNumber       The line number.
	 * @param string $message          The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testRefusedRequestsSaveNothing(string $administrationId, string $invoiceId, int $lineNumber, string $message): void {
		$service = $this->service([self::invoice()]);
		try {
			$service->linkInvoiceLineToPo($administrationId, $invoiceId, $lineNumber, 'pol-1', 'po-1', 'grnl-1', 'm-1');
			self::fail('expected a refusal');
		} catch (RuntimeException $e) {
			self::assertSame($message, $e->getMessage());
		}

		self::assertSame([], $this->saved);

	}//end testRefusedRequestsSaveNothing()

	/**
	 * The invoice of another administration is not found even by id.
	 *
	 * @return void
	 */
	public function testInvoiceOfAnotherAdministrationIsNotFound(): void {
		$service = $this->service([['administrationId' => 'adm-2'] + self::invoice()]);
		$this->expectExceptionMessage('Supplier invoice not found');
		$service->linkInvoiceLineToPo(self::ADMIN, 'inv-1', 1, 'pol-1', 'po-1', null, null);

	}//end testInvoiceOfAnotherAdministrationIsNotFound()

	/**
	 * An administration the caller cannot access is refused even when it
	 * holds the invoice.
	 *
	 * @return void
	 */
	public function testInaccessibleAdministrationIsRefusedEvenWithTheInvoice(): void {
		$service = $this->service([['administrationId' => 'adm-2'] + self::invoice()]);
		try {
			$service->linkInvoiceLineToPo('adm-2', 'inv-1', 1, 'pol-1', 'po-1', null, null);
			self::fail('expected a refusal');
		} catch (RuntimeException $e) {
			self::assertSame('Supplier invoice not found', $e->getMessage());
		}

		self::assertSame([], $this->saved);

	}//end testInaccessibleAdministrationIsRefusedEvenWithTheInvoice()

	/**
	 * An allowed link stamps the line, keeps the other lines, de-duplicates
	 * matchedPoIds with the new PO appended, and saves once.
	 *
	 * @return void
	 */
	public function testAllowedLinkStampsTheLineAndSaves(): void {
		$service = $this->service([self::invoice()]);

		$result = $service->linkInvoiceLineToPo(self::ADMIN, 'inv-1', 3, 'pol-3', 'po-3', '', 'm-3');

		self::assertCount(1, $this->saved);
		self::assertSame(
			[
				['lineNumber' => 1, 'description' => 'Paper'],
				'not a line',
				['description' => 'Toner', 'linkedPoLineId' => 'pol-3', 'linkedPoId' => 'po-3', 'linkedGrnLineId' => null, 'linkedMatchId' => 'm-3'],
				['lineNumber' => 7, 'description' => 'Ink', 'linkedPoId' => 'old'],
			],
			$result['lines']
		);
		self::assertSame(['po-9', 'po-8', 'po-3'], $result['matchedPoIds']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $result['consolidatedAt']);
		self::assertSame(['id', 'administrationId', 'matchedPoIds', 'lines', 'consolidatedAt'], array_keys($result));

	}//end testAllowedLinkStampsTheLineAndSaves()

	/**
	 * Relinking by an explicit line number overwrites the link fields; blank
	 * ids are stored as null and a null or known PO id adds nothing to
	 * matchedPoIds.
	 *
	 * @return void
	 */
	public function testRelinkWithBlankIdsStoresNulls(): void {
		$service = $this->service([self::invoice()]);

		$result = $service->linkInvoiceLineToPo(self::ADMIN, 'inv-1', 7, '', null, '', '');

		self::assertSame(
			['lineNumber' => 7, 'description' => 'Ink', 'linkedPoId' => null, 'linkedPoLineId' => null, 'linkedGrnLineId' => null, 'linkedMatchId' => null],
			$result['lines'][3]
		);
		self::assertSame(['po-9', 'po-8'], $result['matchedPoIds']);

		$again = $this->service([self::invoice()])->linkInvoiceLineToPo(self::ADMIN, 'inv-1', 1, 'pol-1', 'po-8', null, null);
		self::assertSame(['po-9', 'po-8'], $again['matchedPoIds']);

	}//end testRelinkWithBlankIdsStoresNulls()

	/**
	 * A stored invoice whose lines or matchedPoIds are not lists.
	 *
	 * @return void
	 */
	public function testMalformedListsAreTreatedAsEmpty(): void {
		$service = $this->service([['lines' => 'x', 'matchedPoIds' => 'y'] + self::invoice()]);
		$this->expectExceptionMessage('Invoice line not found');
		$service->linkInvoiceLineToPo(self::ADMIN, 'inv-1', 1, 'pol-1', 'po-1', null, null);

	}//end testMalformedListsAreTreatedAsEmpty()

	/**
	 * A stored invoice whose matchedPoIds is not a list starts a new list.
	 *
	 * @return void
	 */
	public function testMalformedMatchedPoIdsStartsAFreshList(): void {
		$service = $this->service([['matchedPoIds' => 'y'] + self::invoice()]);

		$result = $service->linkInvoiceLineToPo(self::ADMIN, 'inv-1', 1, 'pol-1', 'po-1', null, null);

		self::assertSame(['po-1'], $result['matchedPoIds']);

	}//end testMalformedMatchedPoIdsStartsAFreshList()
}//end class
