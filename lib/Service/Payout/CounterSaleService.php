<?php

/**
 * CounterSaleService
 *
 * A quick sale at the counter (receivables-provider-payouts D6, REQ-RPPO-006):
 * lines with VAT per line and an optional customer become a draft simplified
 * ARInvoice, whose payment is taken on the user's phone as Take payment now
 * does (D5). The capture issues it (CounterSaleSettlement); a failed payment,
 * or a customer who walks away, cancels the draft and nothing is booked.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payout
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payout;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentActionAuthorizerInterface;
use OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException;
use OCA\Shillinq\Service\FinancialDocumentGuard;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Counter prices include VAT, as a shop shows them: a line's net amount is
 * derived from its gross amount and VAT rate. A sale without a named customer
 * goes to the administration's counter-sales debtor, the CustomerMaster with
 * customerId COUNTER_SALES_CODE, which is created on the first sale.
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
 */
class CounterSaleService {

	/**
	 * The customerId of an administration's counter-sales debtor.
	 */
	public const COUNTER_SALES_CODE = 'COUNTER-SALES';

	/**
	 * The most lines one counter sale holds.
	 */
	public const MAX_LINES = 50;

	/**
	 * The address the counter-sales debtor carries: CustomerMaster requires
	 * one, and `.invalid` (RFC 2606) can never receive mail.
	 */
	private const COUNTER_SALES_EMAIL = 'counter-sales@example.invalid';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService    OpenRegister's object service.
	 * @param RegisterLocation                $registerLocation The register slug.
	 * @param FinancialDocumentGuard          $guard            The caller's payment action and administration.
	 * @param PointOfSalePaymentService       $payments         Takes the payment on the user's phone (D5).
	 * @param ObjectTransitionRunnerInterface $transitions      Cancels an abandoned sale.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly FinancialDocumentGuard $guard,
		private readonly PointOfSalePaymentService $payments,
		private readonly ObjectTransitionRunnerInterface $transitions,
	) {
	}//end __construct()

	/**
	 * Record a counter sale as a draft simplified invoice and take its payment.
	 *
	 * When the payment cannot be started (no phone registered, provider not
	 * connected) the draft is cancelled before the refusal is passed on, so no
	 * half-made sale stays behind.
	 *
	 * @param string                           $administrationId The administration that sells.
	 * @param list<array<string, mixed>>       $lines            `{description, quantity?, unitPrice, vatRate}`, unitPrice including VAT.
	 * @param string|null                      $customerId       A CustomerMaster of this administration, or null for the counter-sales debtor.
	 * @param string                           $today            Y-m-d.
	 *
	 * @return array{invoice: array<string, mixed>, mode: string, request: array<string, mixed>, checkoutUrl: string}
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller may not, the sale is malformed or the payment cannot start.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
	 */
	public function start(string $administrationId, array $lines, ?string $customerId, string $today): array {
		$this->guard->assertMay(action: PaymentActionAuthorizerInterface::ACTION_REQUEST);
		$this->guard->assertAdministration(administrationId: $administrationId);

		$invoiceLines = self::invoiceLines(lines: $lines);
		$customer = $this->customerFor(customerId: $customerId, administrationId: $administrationId);
		$invoice = $this->saveDraft(
			invoice: self::draft(lines: $invoiceLines, customerId: $customer, administrationId: $administrationId, today: $today)
		);

		try {
			$payment = $this->payments->payInvoice(invoice: $invoice, userId: $this->guard->callerId());
		} catch (FinancialDocumentActionRefusedException $e) {
			$this->transitions->run(objectId: (string)$invoice['id'], action: CounterSaleSettlement::TRANSITION_CANCEL);
			throw $e;
		}

		return ['invoice' => $invoice] + $payment;
	}//end start()

	/**
	 * Cancel a counter sale the customer walked away from.
	 *
	 * Only a draft counter sale of an administration the caller works in can be
	 * cancelled; a sale whose payment was captured in the meantime is issued
	 * and stays as it is.
	 *
	 * @param string $invoiceId The draft invoice of the sale.
	 *
	 * @return array<string, mixed> The invoice, cancelled.
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller may not, or the sale is no longer a draft.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
	 */
	public function abandon(string $invoiceId): array {
		$this->guard->assertMay(action: PaymentActionAuthorizerInterface::ACTION_REQUEST);
		$invoice = $this->guard->readableInvoice(invoiceId: $invoiceId);
		$this->guard->assertAdministration(administrationId: (string)($invoice['administrationId'] ?? ''));

		if (CounterSaleSettlement::isDraftSale(invoice: $invoice) === false) {
			throw new FinancialDocumentActionRefusedException(message: 'Only a counter sale that is not paid yet can be cancelled.', status: 409);
		}

		$this->transitions->run(objectId: (string)$invoice['id'], action: CounterSaleSettlement::TRANSITION_CANCEL);

		return ['lifecycleState' => 'cancelled'] + $invoice;
	}//end abandon()

	/**
	 * The invoice lines of a sale: EN 16931 BG-25 lines with net amounts derived from the VAT-inclusive price.
	 *
	 * @param array<int, mixed> $lines The lines as the page sends them.
	 *
	 * @return list<array<string, mixed>> The invoice lines, each with its `grossAmount` and `vatAmount`.
	 *
	 * @throws FinancialDocumentActionRefusedException When there is no line, too many, or one is malformed.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
	 */
	public static function invoiceLines(array $lines): array {
		if ($lines === [] || count($lines) > self::MAX_LINES) {
			throw new FinancialDocumentActionRefusedException(message: sprintf('A counter sale needs 1 to %d lines.', self::MAX_LINES), status: 400);
		}

		$out = [];
		foreach (array_values($lines) as $index => $line) {
			$out[] = self::invoiceLine(line: $line, number: ($index + 1));
		}

		return $out;
	}//end invoiceLines()

	/**
	 * One invoice line.
	 *
	 * @param mixed $line   The line as sent.
	 * @param int   $number Its position, from 1.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws FinancialDocumentActionRefusedException When the line is malformed.
	 */
	private static function invoiceLine(mixed $line, int $number): array {
		$description = '';
		if (is_array($line) === true) {
			$description = trim((string)($line['description'] ?? ''));
		}

		$quantity = 0.0;
		$price = -1.0;
		$rate = -1.0;
		if (is_array($line) === true) {
			$quantity = self::numberOr(value: ($line['quantity'] ?? 1), fallback: 0.0);
			$price = self::numberOr(value: ($line['unitPrice'] ?? null), fallback: -1.0);
			$rate = self::numberOr(value: ($line['vatRate'] ?? null), fallback: -1.0);
		}

		if ($description === '' || mb_strlen($description) > 200 || $quantity <= 0.0 || $price < 0.0 || $rate < 0.0 || $rate > 100.0) {
			throw new FinancialDocumentActionRefusedException(
				message: sprintf('Line %d needs a description, a quantity above zero, a price and a VAT rate from 0 to 100.', $number),
				status: 400
			);
		}

		$gross = round($quantity * $price, 2);
		$net = round($gross / (1 + ($rate / 100)), 2);

		return [
			'lineId' => (string)$number,
			'quantity' => $quantity,
			'unitCode' => 'C62',
			'itemName' => $description,
			'netPrice' => round($net / $quantity, 4),
			'netAmount' => $net,
			'vatCategory' => self::vatCategory(rate: $rate),
			'vatRate' => $rate,
			'vatAmount' => round($gross - $net, 2),
			'grossAmount' => $gross,
		];
	}//end invoiceLine()

	/**
	 * A number as a float, or the fallback when it is none.
	 *
	 * @param mixed $value    The value.
	 * @param float $fallback What a non-number reads as.
	 *
	 * @return float
	 */
	private static function numberOr(mixed $value, float $fallback): float {
		if (is_numeric($value) === true) {
			return (float)$value;
		}

		return $fallback;
	}//end numberOr()

	/**
	 * The EN 16931 VAT category of a rate: standard, or zero-rated.
	 *
	 * @param float $rate The VAT rate.
	 *
	 * @return string `S` or `Z`.
	 */
	private static function vatCategory(float $rate): string {
		if ($rate > 0.0) {
			return 'S';
		}

		return 'Z';
	}//end vatCategory()

	/**
	 * The draft invoice of a sale.
	 *
	 * @param list<array<string, mixed>> $lines            The invoice lines.
	 * @param string                     $customerId       The CustomerMaster billed.
	 * @param string                     $administrationId The administration that sells.
	 * @param string                     $today            Y-m-d.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
	 */
	public static function draft(array $lines, string $customerId, string $administrationId, string $today): array {
		$gross = round(array_sum(array_column($lines, 'grossAmount')), 2);
		$vat = round(array_sum(array_column($lines, 'vatAmount')), 2);
		$invoiceLines = array_map(
			static fn (array $line): array => array_diff_key($line, ['vatAmount' => true, 'grossAmount' => true]),
			$lines
		);

		return [
			'invoiceNumber' => sprintf('CS-%s-%s', str_replace('-', '', $today), strtoupper(bin2hex(random_bytes(4)))),
			'administrationId' => $administrationId,
			'periodId' => substr($today, 0, 7),
			'customerId' => $customerId,
			'invoiceDate' => $today,
			'dueDate' => $today,
			'currency' => 'EUR',
			'netAmount' => round($gross - $vat, 2),
			'vatAmount' => $vat,
			'grossAmount' => $gross,
			'lifecycleState' => 'draft',
			'invoiceType' => 'standard',
			'simplifiedInvoice' => true,
			'counterSale' => true,
			'invoiceLines' => array_values($invoiceLines),
		];
	}//end draft()

	/**
	 * The customer billed: the named one, checked to be of this administration, else the counter-sales debtor.
	 *
	 * @param string|null $customerId       The named CustomerMaster, or null.
	 * @param string      $administrationId The administration that sells.
	 *
	 * @return string The CustomerMaster id.
	 *
	 * @throws FinancialDocumentActionRefusedException When the named customer is not of this administration.
	 */
	private function customerFor(?string $customerId, string $administrationId): string {
		$customerId = trim((string)$customerId);
		if ($customerId === '') {
			return $this->counterSalesDebtor(administrationId: $administrationId);
		}

		$customer = ObjectIdentifier::findOne(scoped: $this->customers(), id: $customerId);
		if ($customer === null || (string)($customer['administrationId'] ?? '') !== $administrationId) {
			throw new FinancialDocumentActionRefusedException(message: 'This customer is not one of this administration.', status: 400);
		}

		return (string)$customer['id'];
	}//end customerFor()

	/**
	 * The administration's counter-sales debtor, created on its first sale.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return string The CustomerMaster id.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
	 */
	public function counterSalesDebtor(string $administrationId): string {
		$rows = $this->customers()->findAll(
			config: ['filters' => ['customerId' => self::COUNTER_SALES_CODE, 'administrationId' => $administrationId], 'limit' => 5],
			_rbac: false
		);
		foreach ($rows as $row) {
			$found = ObjectIdentifier::recordWithId(candidate: $row);
			// Re-checked: a filter the query layer ignores must not hand out another administration's debtor.
			if ($found !== null && (string)($found['customerId'] ?? '') === self::COUNTER_SALES_CODE
				&& (string)($found['administrationId'] ?? '') === $administrationId
			) {
				return (string)$found['id'];
			}
		}

		$saved = $this->objectService->saveObject(
			object: [
				'customerId' => self::COUNTER_SALES_CODE,
				'legalName' => 'Counter sales',
				'email' => self::COUNTER_SALES_EMAIL,
				'administrationId' => $administrationId,
				'lifecycleState' => 'active',
			],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->registerLocation->getRegisterSlug(), schema: 'CustomerMaster')),
			_rbac: false
		);

		return ObjectIdentifier::resolve(saved: $saved);
	}//end counterSalesDebtor()

	/**
	 * Save the draft and return it with its id.
	 *
	 * @param array<string, mixed> $invoice The draft.
	 *
	 * @return array<string, mixed> The draft with `id`.
	 *
	 * @throws FinancialDocumentActionRefusedException When OpenRegister answers without an id.
	 */
	private function saveDraft(array $invoice): array {
		$saved = $this->objectService->saveObject(
			object: $invoice,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->registerLocation->getRegisterSlug(), schema: 'ARInvoice'))
		);

		$id = ObjectIdentifier::resolve(saved: $saved);
		if ($id === '') {
			throw new FinancialDocumentActionRefusedException(message: 'The sale could not be saved. Try again.', status: 502);
		}

		return ['id' => $id] + $invoice;
	}//end saveDraft()

	/**
	 * The object service scoped to CustomerMaster.
	 *
	 * @return ObjectServiceInterface
	 */
	private function customers(): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema('CustomerMaster');
	}//end customers()
}//end class
