<?php

/**
 * Unit tests for the pay link on the invoice document
 * (receivables-payment-links task 3.2, REQ-RPL-004 and D5).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
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
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.2
 *
 * @covers \OCA\Shillinq\Service\InvoicePdfGenerator
 * @covers \OCA\Shillinq\Service\Payment\PayLinkService
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Service\Payment\PayLinkService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The pay link printed under the totals of an open invoice, and nowhere else.
 */
final class InvoicePdfPayLinkTest extends TestCase {

	private const PAGE = 'https://portal.example/betalen?lang=nl';

	/**
	 * A pay link service over a test secret and a pay page.
	 *
	 * @param bool|null $dormant Whether the bound adapter is dormant; null binds none.
	 * @param string    $page    The configured pay page.
	 *
	 * @return PayLinkService
	 */
	private function payLinks(?bool $dormant, string $page = self::PAGE): PayLinkService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => $key === 'secret' ? 'instance-secret-of-test' : $default);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $key === PayLinkService::PAGE_KEY ? $page : $default);

		$adapter = null;
		if ($dormant !== null) {
			$adapter = $this->createMock(MolliePaymentAdapterInterface::class);
			$adapter->method('isDormant')->willReturn($dormant);
		}

		return new PayLinkService(config: $config, appConfig: $appConfig, adapter: $adapter);
	}//end payLinks()

	/**
	 * An open invoice of an administration.
	 *
	 * @param string $state The lifecycle state.
	 *
	 * @return array<string, mixed>
	 */
	private static function invoice(string $state = 'issued'): array {
		return [
			'id' => 'inv-2026-0412',
			'administrationId' => 'adm-kade',
			'invoiceNumber' => '2026-0412',
			'lifecycleState' => $state,
			'grossAmount' => 302.5,
		];
	}//end invoice()

	/**
	 * The rendered HTML of an invoice.
	 *
	 * @param InvoicePdfGenerator  $generator The generator.
	 * @param array<string, mixed> $invoice   The invoice.
	 *
	 * @return string
	 */
	private static function html(InvoicePdfGenerator $generator, array $invoice): string {
		return $generator->generatePdf(invoice: $invoice, lines: [], creditor: ['legalName' => 'Adviesbureau Kade B.V.'], recipient: [])['html'];
	}//end html()

	/**
	 * An open invoice with a live adapter prints the signed pay URL, escaped, and it verifies.
	 *
	 * @return void
	 */
	public function testAnOpenInvoicePrintsTheSignedPayLink(): void {
		$payLinks = $this->payLinks(dormant: false);
		$html     = self::html(generator: new InvoicePdfGenerator(payLinks: $payLinks), invoice: self::invoice());

		self::assertSame(1, preg_match('#<p class="pay-link"><strong>Betaal online:</strong> <a href="([^"]+)">\1</a></p>#', $html, $match));
		$url = html_entity_decode($match[1], ENT_QUOTES);
		self::assertStringStartsWith(self::PAGE . '&pay=', $url);
		self::assertStringContainsString('lang=nl&amp;pay=', $match[1]);
		$token = rawurldecode(substr($url, strlen(self::PAGE . '&pay=')));
		self::assertSame(['administrationId' => 'adm-kade', 'invoiceId' => 'inv-2026-0412'], $payLinks->verify(token: $token));
	}//end testAnOpenInvoicePrintsTheSignedPayLink()

	/**
	 * An overdue and a partially paid invoice are open too.
	 *
	 * @return void
	 */
	public function testOverdueAndPartiallyPaidInvoicesPrintTheLink(): void {
		$generator = new InvoicePdfGenerator(payLinks: $this->payLinks(dormant: false));

		self::assertStringContainsString('Betaal online', self::html(generator: $generator, invoice: self::invoice(state: 'overdue')));
		self::assertStringContainsString('Betaal online', self::html(generator: $generator, invoice: self::invoice(state: 'partially-paid')));
	}//end testOverdueAndPartiallyPaidInvoicesPrintTheLink()

	/**
	 * A dormant adapter prints no link.
	 *
	 * @return void
	 */
	public function testADormantAdapterPrintsNoLink(): void {
		$html = self::html(generator: new InvoicePdfGenerator(payLinks: $this->payLinks(dormant: true)), invoice: self::invoice());

		self::assertStringNotContainsString('Betaal online', $html);
		self::assertStringNotContainsString('pay=', $html);
	}//end testADormantAdapterPrintsNoLink()

	/**
	 * Without a bound adapter there is no link either.
	 *
	 * @return void
	 */
	public function testNoAdapterPrintsNoLink(): void {
		self::assertNull($this->payLinks(dormant: null)->forOpenInvoice(invoice: self::invoice()));
	}//end testNoAdapterPrintsNoLink()

	/**
	 * A paid, a draft and a written-off invoice print no link.
	 *
	 * @return void
	 */
	public function testAClosedOrDraftInvoicePrintsNoLink(): void {
		$generator = new InvoicePdfGenerator(payLinks: $this->payLinks(dormant: false));
		foreach (['paid', 'draft', 'written-off', ''] as $state) {
			self::assertStringNotContainsString('Betaal online', self::html(generator: $generator, invoice: self::invoice(state: $state)), $state);
		}
	}//end testAClosedOrDraftInvoicePrintsNoLink()

	/**
	 * Without an https pay page configured no link is printed.
	 *
	 * @return void
	 */
	public function testNoPayPagePrintsNoLink(): void {
		$generator = new InvoicePdfGenerator(payLinks: $this->payLinks(dormant: false, page: 'http://portal.example/betalen'));

		self::assertStringNotContainsString('Betaal online', self::html(generator: $generator, invoice: self::invoice()));
	}//end testNoPayPagePrintsNoLink()

	/**
	 * A generator without the pay link service renders as before.
	 *
	 * @return void
	 */
	public function testAGeneratorWithoutPayLinksPrintsNoLink(): void {
		self::assertStringNotContainsString('Betaal online', self::html(generator: new InvoicePdfGenerator(), invoice: self::invoice()));
	}//end testAGeneratorWithoutPayLinksPrintsNoLink()
}//end class
