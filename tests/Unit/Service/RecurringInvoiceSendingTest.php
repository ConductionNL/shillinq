<?php

/**
 * Tests for the recurring run's send (sales-invoice-sending task 4.1).
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
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\InvoiceSendingService;
use OCA\Shillinq\Service\RecurringInvoiceGenerator;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An auto-issued invoice of a profile with email delivery is sent; nothing else is.
 *
 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-a-recurring-profile-with-email-delivery-sends-its-auto-issued-invoices-req-sis-007
 */
class RecurringInvoiceSendingTest extends TestCase {

	private InvoiceSendingService&MockObject $sending;

	private InMemoryObjectService $store;

	protected function setUp(): void {
		$this->sending = $this->createMock(InvoiceSendingService::class);
		$this->store = new InMemoryObjectService();
	}//end setUp()

	public function testAnAutoIssuedInvoiceWithEmailDeliveryIsSentByEmail(): void {
		$this->sending->expects($this->once())->method('sendUnattended')
			->with('adm-1', $this->callback(static fn (string $id): bool => $id !== ''), 'email')
			->willReturn(['status' => 'sent', 'sending' => [], 'error' => '']);

		$result = $this->generator()->generateForProfile(self::profile(['issueMode' => 'auto-issue']));

		$this->assertTrue($result['created']);
		$this->assertSame('issued', $result['invoice']['lifecycleState']);
	}//end testAnAutoIssuedInvoiceWithEmailDeliveryIsSentByEmail()

	public function testAnAutoIssuedInvoiceWithoutEmailDeliveryIsNotSent(): void {
		$this->sending->expects($this->never())->method('sendUnattended');

		$this->generator()->generateForProfile(self::profile(['issueMode' => 'auto-issue', 'deliveryChannel' => 'none']));
	}//end testAnAutoIssuedInvoiceWithoutEmailDeliveryIsNotSent()

	public function testADraftForReviewIsNeverSent(): void {
		$this->sending->expects($this->never())->method('sendUnattended');

		$result = $this->generator()->generateForProfile(self::profile(['issueMode' => 'draft-for-review']));

		$this->assertSame('draft', $result['invoice']['lifecycleState']);
	}//end testADraftForReviewIsNeverSent()

	public function testAFailedSendKeepsTheInvoiceAndAdvancesTheProfile(): void {
		$this->sending->method('sendUnattended')->willThrowException(new RuntimeException('ARInvoice not found'));

		$result = $this->generator()->generateForProfile(self::profile(['issueMode' => 'auto-issue']));

		$this->assertTrue($result['created']);
		$this->assertCount(1, $this->store->dump('ARInvoice'));
		$this->assertNotSame('2027-01-01', (string)($result['profile']['nextRunDate'] ?? ''), 'the profile moved on');
	}//end testAFailedSendKeepsTheInvoiceAndAdvancesTheProfile()

	/**
	 * The generator over the in-memory store, with the send double.
	 *
	 * @return RecurringInvoiceGenerator
	 */
	private function generator(): RecurringInvoiceGenerator {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		return new RecurringInvoiceGenerator(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createStub(LoggerInterface::class),
			sending: $this->sending
		);
	}//end generator()

	/**
	 * A monthly profile of administration adm-1.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private static function profile(array $overrides): array {
		return array_merge(
			[
				'id' => 'prof-1',
				'administrationId' => 'adm-1',
				'customerReference' => 'C-001',
				'frequency' => 'monthly',
				'interval' => 1,
				'invoiceDay' => 1,
				'startDate' => '2027-01-01',
				'deliveryChannel' => 'email',
				'paymentTermsDays' => 30,
				'status' => 'active',
				'currency' => 'EUR',
				'lines' => [['description' => 'Abonnement {month} {year}', 'quantity' => 1, 'unitPrice' => 99.0, 'vatCode' => 21]],
			],
			$overrides
		);
	}//end profile()
}//end class
