<?php

/**
 * Unit tests for DownPaymentGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\DownPaymentGuard;
use OCA\Shillinq\Service\Sales\DownPaymentService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-SDP-004: issuing a final invoice checks its deductions first and stamps them after.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DownPaymentGuardTest extends TestCase {
	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * A down payment deducted on 2026-0587 and a second final invoice that deducts it too.
	 *
	 * @return array{0: DownPaymentGuard, 1: array<string,mixed>}
	 */
	private function guardAndSecondFinal(): array {
		$customer = '5b0c1a6e-2d4f-4c1e-9a7b-3f2e1d0c9b8a';
		$this->store = new InMemoryObjectServiceStub(
			[
				'ARInvoice' => [
					[
						'id' => 'ar-dp', 'invoiceNumber' => '2026-0412', 'customerId' => $customer, 'administrationId' => 'adm-kvl',
						'invoiceTypeCode' => '386', 'lifecycleState' => 'paid', 'grossAmount' => 5445.0,
						'downPayment' => [
							'kind' => 'down-payment', 'orderReference' => 'order-117',
							'deductedOnInvoiceId' => 'ar-kitchen', 'deductedOnInvoiceNumber' => '2026-0587',
						],
					],
				],
			]
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$service = new DownPaymentService($this->store, $settings, $l10n, $this->createMock(LoggerInterface::class));

		$second = [
			'id' => 'ar-second', 'invoiceNumber' => '2026-0601', 'customerId' => $customer, 'administrationId' => 'adm-kvl',
			'lifecycleState' => 'issued', 'grossAmount' => 12705.0,
			'downPayment' => [
				'kind' => 'final', 'orderReference' => 'order-117',
				'deductions' => [['invoiceId' => 'ar-dp', 'invoiceNumber' => '2026-0412', 'rate' => 0.21, 'net' => 4500.0, 'vat' => 945.0]],
			],
		];

		return [new DownPaymentGuard($service), $second];

	}//end guardAndSecondFinal()

	/**
	 * The spec scenario: the issue is refused naming invoice 2026-0587.
	 *
	 * @return void
	 */
	public function testTheCheckRefusesASecondDeductionNamingTheFirstInvoice(): void {
		[$guard, $second] = $this->guardAndSecondFinal();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Down payment 2026-0412 was already deducted on invoice 2026-0587.');
		$guard->execute($second, [], ['step' => 'check'], DownPaymentGuard::class);

	}//end testTheCheckRefusesASecondDeductionNamingTheFirstInvoice()

	/**
	 * The stamp records the final invoice on the down payment and returns the invoice unchanged.
	 *
	 * @return void
	 */
	public function testTheStampRecordsTheFinalInvoice(): void {
		[$guard, $second] = $this->guardAndSecondFinal();
		$second['id'] = 'ar-kitchen';
		$second['invoiceNumber'] = '2026-0587';

		self::assertSame($second, $guard->execute($second, [], ['step' => 'check'], DownPaymentGuard::class), 'its own deduction passes');
		self::assertSame($second, $guard->execute($second, [], ['step' => 'stamp'], DownPaymentGuard::class));
		$stamped = $this->store->setSchema('ARInvoice')->find('ar-dp')->getObject();
		self::assertSame('ar-kitchen', $stamped['downPayment']['deductedOnInvoiceId']);

	}//end testTheStampRecordsTheFinalInvoice()

	/**
	 * An unknown step is a declaration error and fails loudly.
	 *
	 * @return void
	 */
	public function testAnUnknownStepIsRefused(): void {
		[$guard, $second] = $this->guardAndSecondFinal();

		$this->expectException(RuntimeException::class);
		$guard->execute($second, [], ['step' => 'later'], DownPaymentGuard::class);

	}//end testAnUnknownStepIsRefused()
}//end class
