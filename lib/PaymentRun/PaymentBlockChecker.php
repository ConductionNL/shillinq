<?php

/**
 * Payment Block Checker
 *
 * Names every line of a payment run that may not be paid: its invoice is
 * payment blocked or disputed, or its payee is payment blocked. The one check
 * the proposal, the export guard and the export service share, so a block set
 * after approval still stops the file (banking-payment-run design.md D4).
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/banking-payment-run/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Finds the blocked lines of a payment run. Lookups that fail throw, so a
 * caller that must fail closed can.
 */
class PaymentBlockChecker {

	public const REASON_INVOICE_BLOCKED = 'invoice-blocked';
	public const REASON_INVOICE_DISPUTED = 'invoice-disputed';
	public const REASON_PAYEE_BLOCKED = 'payee-blocked';
	public const REASON_NOT_FOUND = 'not-found';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SettingsService        $settings      The register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
	) {

	}//end __construct()

	/**
	 * The lines of a run that may not be paid, with the reason for each.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object.
	 *
	 * @return list<array{apTransactionRef: string, invoiceNumber: string, reason: string, detail: string}>
	 *
	 * @spec openspec/changes/banking-payment-run/tasks.md#task-2.3
	 */
	public function blockedLines(array $paymentRun): array {
		$lines = ($paymentRun['paymentLines'] ?? []);
		if (is_array($lines) === false) {
			return [];
		}

		$blocked = [];
		foreach ($lines as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$ref = trim((string)($line['apTransactionRef'] ?? ''));
			$invoice = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'APTransaction'), id: $ref, fallbackProperty: 'invoiceNumber');
			if ($invoice === null) {
				$blocked[] = ['apTransactionRef' => $ref, 'invoiceNumber' => $ref, 'reason' => self::REASON_NOT_FOUND, 'detail' => ''];
				continue;
			}

			$payeeId = trim((string)($invoice['vendorId'] ?? ($line['payeeId'] ?? '')));
			$reason = $this->reasonFor(invoice: $invoice, payee: $this->payee(payeeId: $payeeId));
			if ($reason !== null) {
				$blocked[] = ['apTransactionRef' => $ref, 'invoiceNumber' => (string)($invoice['invoiceNumber'] ?? $ref)] + $reason;
			}
		}//end foreach

		return $blocked;

	}//end blockedLines()

	/**
	 * Why one invoice may not be paid, or null when it may.
	 *
	 * @param array<string, mixed>      $invoice The APTransaction.
	 * @param array<string, mixed>|null $payee   Its payee, null when it names none.
	 *
	 * @return array{reason: string, detail: string}|null
	 *
	 * @spec openspec/changes/banking-payment-run/tasks.md#task-2.3
	 */
	public function reasonFor(array $invoice, ?array $payee): ?array {
		if (($invoice['paymentBlocked'] ?? false) === true) {
			return ['reason' => self::REASON_INVOICE_BLOCKED, 'detail' => (string)($invoice['paymentBlockReason'] ?? '')];
		}

		if ((string)($invoice['state'] ?? '') === 'disputed') {
			return ['reason' => self::REASON_INVOICE_DISPUTED, 'detail' => ''];
		}

		if ($payee !== null && ($payee['paymentBlocked'] ?? false) === true) {
			return ['reason' => self::REASON_PAYEE_BLOCKED, 'detail' => (string)($payee['paymentBlockReason'] ?? '')];
		}

		return null;

	}//end reasonFor()

	/**
	 * The payee an invoice names, null when it names none.
	 *
	 * A payee id that resolves to nothing reads as a blocked payee, so the
	 * check fails closed.
	 *
	 * @param string $payeeId The payee's uuid or vendor number.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/banking-payment-run/tasks.md#task-2.3
	 */
	public function payee(string $payeeId): ?array {
		if ($payeeId === '') {
			return null;
		}

		$payee = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'Payee'), id: $payeeId, fallbackProperty: 'vendorNumber');
		if ($payee === null) {
			return ['paymentBlocked' => true, 'paymentBlockReason' => 'payee ' . $payeeId . ' not found'];
		}

		return $payee;

	}//end payee()

	/**
	 * The object service scoped to one schema of the shillinq register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
