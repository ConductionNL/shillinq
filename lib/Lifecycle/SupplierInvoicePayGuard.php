<?php

/**
 * Supplier Invoice Pay Guard
 *
 * Guards `SupplierInvoice.pay` (approved -> paid). An invoice is paid when the
 * AP transaction it was handed to is paid; that state is reached by the bank
 * match on the AP side. A supplier invoice marked paid while its AP
 * transaction is open, or without one, is refused. The hand-over writes the
 * invoice uuid on the transaction, so pointing an invoice at another
 * invoice's paid transaction is refused too.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lets a supplier invoice become paid only when its own AP transaction is paid.
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
 */
final class SupplierInvoicePayGuard implements LifecycleGuardInterface {

	/**
	 * Refusal shown to the caller.
	 *
	 * @var string
	 */
	public const MESSAGE = 'The invoice is paid when its accounts-payable transaction is paid. Its transaction is not paid yet.';

	/**
	 * Construct the guard.
	 *
	 * @param ContainerInterface     $container       DI container for the lazy ObjectService.
	 * @param SettingsService        $settingsService Resolves the register slug.
	 * @param LoggerInterface        $logger          Logger.
	 * @param ListenerSchemaResolver $schemas         Tells which register and schema an object is in.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ListenerSchemaResolver $schemas,
	) {
	}//end __construct()

	/**
	 * Allow the transition only when the invoice's own AP transaction is paid.
	 *
	 * @param array<string, mixed> $object The invoice as it would be saved.
	 * @param string               $action The transition, `pay`.
	 * @param string               $userId The caller.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$apTransactionId = trim((string)($object['apTransactionId'] ?? ''));
		if ($apTransactionId === '' || trim((string)($object['administrationId'] ?? '')) === '') {
			return GuardResult::deny(self::MESSAGE);
		}

		try {
			$transaction = $this->apTransaction(id: $apTransactionId);
		} catch (Throwable $e) {
			$this->logger->error('SupplierInvoicePayGuard: AP transaction lookup failed; refusing', ['exception' => $e->getMessage()]);
			return GuardResult::deny(self::MESSAGE);
		}

		if ($transaction !== null && $this->isTransactionOf(transaction: $transaction, invoice: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::MESSAGE);
	}//end check()

	/**
	 * The AP transaction with this uuid, read without RBAC in the caller's
	 * organisation; null when the object found is not an AP transaction of
	 * this app's register.
	 *
	 * OpenRegister's find() does not use the register and schema set on the
	 * service, and retries a uuid miss without the schema, so the object it
	 * answers with is checked here.
	 *
	 * @param string $id The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function apTransaction(string $id): ?array {
		$register = $this->settingsService->getRegisterSlug();
		try {
			$found = $this->container->get('OCA\OpenRegister\Service\ObjectService')
				->find($id, register: $register, schema: 'APTransaction', _rbac: false);
		} catch (\OCP\AppFramework\Db\DoesNotExistException | \RuntimeException $notFound) {
			return null;
		}

		if (is_object($found) === false
			|| $this->schemas->matchesRegisterAndSchema(entity: $found, registerSlug: $register, schemaSlug: 'APTransaction') === false
		) {
			return null;
		}

		return $found->getObject();
	}//end apTransaction()

	/**
	 * Whether the transaction was written for this invoice by the hand-over
	 * (it names the invoice's uuid, which a write to the invoice cannot change)
	 * and is paid with the invoice's details.
	 *
	 * @param array<string, mixed> $transaction The AP transaction.
	 * @param array<string, mixed> $invoice     The supplier invoice.
	 *
	 * @return bool
	 */
	private function isTransactionOf(array $transaction, array $invoice): bool {
		$invoiceId = trim((string)($invoice['id'] ?? ''));
		return $invoiceId !== ''
			&& (string)($transaction['supplierInvoiceId'] ?? '') === $invoiceId
			&& $this->isPaidTransactionOf(transaction: $transaction, invoice: $invoice) === true;
	}//end isTransactionOf()

	/**
	 * Whether the transaction is paid and is the one this invoice was handed to:
	 * the same administration, invoice number, vendor and total.
	 *
	 * @param array<string, mixed> $transaction The AP transaction.
	 * @param array<string, mixed> $invoice     The supplier invoice.
	 *
	 * @return bool
	 */
	private function isPaidTransactionOf(array $transaction, array $invoice): bool {
		return (string)($transaction['state'] ?? '') === 'paid'
			&& (string)($transaction['administrationId'] ?? '') === (string)$invoice['administrationId']
			&& (string)($transaction['invoiceNumber'] ?? '') === (string)($invoice['invoiceNumber'] ?? '')
			&& (string)($transaction['vendorId'] ?? '') === (string)($invoice['supplierId'] ?? '')
			&& abs((float)($transaction['totalAmount'] ?? 0) - round(((int)($invoice['totalInclVat'] ?? 0)) / 100, 2)) < 0.005;
	}//end isPaidTransactionOf()
}//end class
