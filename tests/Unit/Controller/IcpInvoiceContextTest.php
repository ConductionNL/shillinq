<?php

/**
 * Characterisation tests for the invoice context the ICP invoice PDF is
 * rendered from.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Controller\IcpController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ArInvoiceIcpPdfRenderer;
use OCA\Shillinq\Service\IcpFilingService;
use OCA\Shillinq\Service\IcpService;
use OCA\Shillinq\Service\ViesService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins how the invoice, customer and seller are found, and the queries.
 */
final class IcpInvoiceContextTest extends TestCase {

	/**
	 * Queries in order, as schema|filters.
	 *
	 * @var array<int,string>
	 */
	private array $queries = [];

	/**
	 * Render the PDF of an invoice over seeded objects.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows Rows per schema.
	 * @param string $invoiceId The invoice asked for.
	 *
	 * @return array<string,mixed>|null What the renderer received, null when it was not called.
	 */
	private function render(array $rows, string $invoiceId): ?array {
		$request = $this->createMock(IRequest::class);
		$params = ['invoice_id' => $invoiceId, 'administration_id' => 'adm-1'];
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn(true);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$schema = '';
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string $name) use (&$schema, $objects): ObjectServiceInterface {
				$schema = $name;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $query) use (&$schema, $rows): array {
				$this->queries[] = $schema . '|' . json_encode($query);
				return ($rows[$schema] ?? []);
			}
		);
		$seen = null;
		$renderer = $this->createMock(ArInvoiceIcpPdfRenderer::class);
		$renderer->method('render')->willReturnCallback(
			static function (array $invoice, array $customer, array $seller) use (&$seen): array {
				$seen = ['invoice' => $invoice, 'customer' => $customer, 'seller' => $seller];
				return ['filename' => 'f.pdf', 'html' => '<p/>'];
			}
		);

		$controller = new IcpController(
			$request,
			$this->createMock(IcpService::class),
			$this->createMock(IcpFilingService::class),
			$this->createMock(ViesService::class),
			$renderer,
			$session,
			$this->createMock(LoggerInterface::class),
			$objects,
			$context,
		);
		$controller->renderInvoicePdf();
		return $seen;

	}//end render()

	/**
	 * Invoices, customers and sellers are matched on slug, then @self id, then id.
	 *
	 * @return void
	 */
	public function testMatching(): void {
		$rows = [
			'ARInvoice' => [
				['@self' => ['slug' => 'inv-slug', 'id' => 'INV-1'], 'id' => 'INV-1', 'customerId' => 'x'],
				['@self' => ['id' => 'INV-1'], 'id' => 'other', 'customerId' => ' 42 '],
				['id' => 'INV-1', 'customerId' => 'never'],
			],
			'CustomerMaster' => [
				['id' => 'c-9', 'customerNumber' => '7'],
				['@self' => ['slug' => 'c-slug'], 'customerNumber' => '42', 'name' => 'by number'],
				['id' => '42', 'name' => 'by id'],
			],
			'Administration' => [
				['@self' => ['slug' => 'adm-1-slug'], 'id' => 'adm-1'],
				['@self' => ['id' => 'adm-1'], 'name' => 'seller'],
			],
		];

		$seen = $this->render($rows, 'INV-1');

		self::assertSame($rows['ARInvoice'][1], $seen['invoice']);
		self::assertSame($rows['CustomerMaster'][1], $seen['customer']);
		self::assertSame($rows['Administration'][1], $seen['seller']);
		self::assertSame(
			[
				'ARInvoice|{"filters":{"administrationId":"adm-1"}}',
				'CustomerMaster|{"filters":{"administrationId":"adm-1"}}',
				'Administration|{"filters":[]}',
			],
			$this->queries
		);

	}//end testMatching()

	/**
	 * Without a customer key the customers are not queried; missing customer
	 * and seller are empty.
	 *
	 * @return void
	 */
	public function testNoCustomerAndNoSeller(): void {
		$seen = $this->render(['ARInvoice' => [['id' => 'INV-1', 'customerId' => '  ']]], 'INV-1');

		self::assertSame(['invoice' => ['id' => 'INV-1', 'customerId' => '  '], 'customer' => [], 'seller' => []], $seen);
		self::assertSame(['ARInvoice|{"filters":{"administrationId":"adm-1"}}', 'Administration|{"filters":[]}'], $this->queries);

	}//end testNoCustomerAndNoSeller()

	/**
	 * An unknown invoice stops after the invoice query.
	 *
	 * @return void
	 */
	public function testUnknownInvoice(): void {
		self::assertNull($this->render(['ARInvoice' => [['id' => 'INV-2']]], 'INV-1'));
		self::assertSame(['ARInvoice|{"filters":{"administrationId":"adm-1"}}'], $this->queries);

	}//end testUnknownInvoice()
}//end class
