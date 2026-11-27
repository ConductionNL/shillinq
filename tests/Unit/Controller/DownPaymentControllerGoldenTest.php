<?php

/**
 * Golden test for DownPaymentController::show and its panel.
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
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\DownPaymentController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Sales\DownPaymentService;
use OCP\IL10N;
use OCP\IRequest;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the panel for every invoice kind, the grouping of open down payments, and the calls behind it.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class DownPaymentControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Show one invoice.
	 *
	 * @param array<string,mixed>|null $invoice The invoice findInvoice answers; null makes it unknown.
	 * @param bool                     $member  Whether the caller may see its administration.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function show(array|null $invoice, bool $member=true): array {
		$service = $this->createMock(DownPaymentService::class);
		$service->method('findInvoice')->willReturnCallback(
			function (string $invoiceId) use ($invoice): array {
				$this->calls[] = ['findInvoice', $invoiceId];
				if ($invoice === null) {
					throw new OutOfBoundsException('gone');
				}

				return $invoice;
			}
		);
		$service->method('openDownPayments')->willReturnCallback(
			function (array $given): array {
				$this->calls[] = ['openDownPayments', $given['id']];
				return [
					[
						'id' => 'd1',
						'invoiceNumber' => 'N1',
						'grossAmount' => '10.115',
						'lifecycleState' => 'paid',
						'downPayment' => ['orderReference' => 'o1', 'orderLabel' => 'First',
					]],
					['id' => 'd2', 'grossAmount' => 5.2, 'downPayment' => ['orderReference' => 'o2']],
					[
						'invoiceNumber' => 'N3',
						'grossAmount' => 0.01,
						'lifecycleState' => 'issued',
						'downPayment' => ['orderReference' => 'o1', 'orderLabel' => 'Renamed',
					]],
					['id' => 'd4'],
				];
			}
		);
		$service->method('position')->willReturnCallback(
			function (array $given): array {
				$this->calls[] = ['position', $given['id']];
				return [['line' => 1]];
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($member): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return $member;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => 'T:' . $text);
		$controller = new DownPaymentController($this->createMock(IRequest::class), $service, $context, $l10n, $this->createMock(LoggerInterface::class));
		$response   = $controller->show('i1');
		return [$response->getStatus(), $response->getData()];
	}//end show()

	/**
	 * An unknown invoice and one outside the caller's administrations are both masked as 404.
	 *
	 * @return void
	 */
	public function testMasked(): void {
		self::assertSame(expected: [404, ['message' => 'T:Invoice not found']], actual: $this->show(invoice: null));
		$actual = $this->show(invoice: ['id' => 'i1', 'administrationId' => 'a2'], member: false);
		self::assertSame(expected: [404, ['message' => 'T:Invoice not found']], actual: $actual);
		self::assertSame(expected: [['findInvoice', 'i1'], ['findInvoice', 'i1'], ['canAccess', 'a2']], actual: $this->calls);
	}//end testMasked()

	/**
	 * A draft that is not itself a down payment lists the open down payments grouped per order.
	 *
	 * @return void
	 */
	public function testDraftGroupsOpenOrders(): void {
		$actual = $this->show(invoice: ['id' => 'i1', 'administrationId' => 'a1', 'lifecycleState' => 'draft', 'downPayment' => ['orderLabel' => 'L']]);
		$o1     = [
			'orderReference' => 'o1',
			'orderLabel' => 'Renamed',
			'grossAmount' => 10.13,
			'downPayments' => [
				['id' => 'd1', 'invoiceNumber' => 'N1', 'grossAmount' => 10.115, 'paid' => true],
				['id' => '', 'invoiceNumber' => 'N3', 'grossAmount' => 0.01, 'paid' => false],
			],
		];
		$o2     = [
			'orderReference' => 'o2',
			'orderLabel' => 'o2',
			'grossAmount' => 5.2,
			'downPayments' => [['id' => 'd2', 'invoiceNumber' => '', 'grossAmount' => 5.2, 'paid' => false]],
		];
		$blank  = [
			'orderReference' => '',
			'orderLabel' => '',
			'grossAmount' => 0.0,
			'downPayments' => [['id' => 'd4', 'invoiceNumber' => '', 'grossAmount' => 0.0, 'paid' => false]],
		];
		$panel  = ['kind' => '', 'orderLabel' => 'L', 'position' => [['line' => 1]], 'openOrders' => [$o1, $o2, $blank], 'canDeduct' => true];
		self::assertSame(expected: [200, $panel], actual: $actual);
		$actual = $this->calls;
		self::assertSame(expected: [['findInvoice', 'i1'], ['canAccess', 'a1'], ['openDownPayments', 'i1'], ['position', 'i1']], actual: $actual);
	}//end testDraftGroupsOpenOrders()

	/**
	 * A down-payment draft and a final invoice list nothing to deduct and never ask for open down payments.
	 *
	 * @return void
	 */
	public function testNothingToDeduct(): void {
		$dpLabel  = ['kind' => 'down-payment', 'orderLabel' => 'L'];
		$actual   = $this->show(invoice: ['id' => 'i1', 'administrationId' => 'a1', 'lifecycleState' => 'draft', 'downPayment' => $dpLabel]);
		$expected = [200, ['kind' => 'down-payment', 'orderLabel' => 'L', 'position' => [['line' => 1]], 'openOrders' => [], 'canDeduct' => false]];
		self::assertSame(expected: $expected, actual: $actual);
		$actual   = $this->show(invoice: ['id' => 'i1', 'lifecycleState' => 'issued']);
		$expected = [200, ['kind' => '', 'orderLabel' => '', 'position' => [['line' => 1]], 'openOrders' => [], 'canDeduct' => false]];
		self::assertSame(expected: $expected, actual: $actual);
		$expected = [['findInvoice', 'i1'], ['canAccess', 'a1'], ['position', 'i1'], ['findInvoice', 'i1'], ['canAccess', ''], ['position', 'i1']];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testNothingToDeduct()
}//end class
