<?php

/**
 * Supplier Invoice Checks
 *
 * The rules every supplier invoice meets however it arrived (UBL, CSV or
 * typed): which payee it is from, whether its number was already booked for
 * that payee, and whether it names a bank account the payee record does not
 * know (purchasing-supplier-invoice-intake design.md D2 to D4).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Purchasing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Purchasing;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use Throwable;

/**
 * Payee resolution, the duplicate rule and the IBAN rule for supplier invoices.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 */
class SupplierInvoiceChecks {

	/**
	 * The duplicate marker when the lookup itself failed: a possible duplicate.
	 *
	 * @var string
	 */
	public const DUPLICATE_UNKNOWN = 'lookup-failed';

	/**
	 * The most records read per lookup.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 5000;

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
	 * The payee of the administration with this KvK number, else this VAT number.
	 *
	 * @param string $administrationId The administration.
	 * @param string $kvkNumber        The supplier's KvK number, '' when unknown.
	 * @param string $vatNumber        The supplier's VAT number, '' when unknown.
	 *
	 * @return array<string, mixed>|null The payee, with its id.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-1.1
	 */
	public function resolvePayee(string $administrationId, string $kvkNumber, string $vatNumber): ?array {
		$payees = $this->read(schema: 'Payee', administrationId: $administrationId);
		$wanted = [
			'kvkNumber' => self::compact(value: $kvkNumber),
			'vatNumber' => self::compact(value: $vatNumber),
		];
		foreach ($wanted as $field => $value) {
			if ($value === '') {
				continue;
			}

			foreach ($payees as $payee) {
				if (self::compact(value: (string)($payee[$field] ?? '')) === $value) {
					return $payee;
				}
			}
		}

		return null;

	}//end resolvePayee()

	/**
	 * The payee a supplier invoice names, null when it names none or an unknown one.
	 *
	 * @param string $supplierId The payee id.
	 *
	 * @return array<string, mixed>|null The payee, with its id.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-3.2
	 */
	public function payee(string $supplierId): ?array {
		if (trim($supplierId) === '') {
			return null;
		}

		return ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'Payee'), id: $supplierId, fallbackProperty: 'vendorNumber');

	}//end payee()

	/**
	 * Another supplier invoice or AP transaction with this number for this payee.
	 *
	 * @param string       $administrationId The administration.
	 * @param string       $supplierId       The payee id.
	 * @param string       $invoiceNumber    The invoice number.
	 * @param list<string> $ignoreIds        The invoice itself and the AP transaction it was handed to.
	 *
	 * @return string|null The earlier record's id, DUPLICATE_UNKNOWN when the lookup failed, null when none.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
	 */
	public function duplicateOf(string $administrationId, string $supplierId, string $invoiceNumber, array $ignoreIds=[]): ?string {
		$number = trim($invoiceNumber);
		if ($number === '' || $supplierId === '') {
			return null;
		}

		$sources = [
			'SupplierInvoice' => 'supplierId',
			'APTransaction' => 'vendorId',
		];
		try {
			foreach ($sources as $schema => $supplierField) {
				$rows = $this->scoped(schema: $schema)->findAll(
					['filters' => ['administrationId' => $administrationId, 'invoiceNumber' => $number], 'limit' => self::READ_LIMIT]
				);
				foreach ($rows as $row) {
					$record = ObjectIdentifier::recordWithId(candidate: $row);
					$id = (string)($record['id'] ?? '');
					if ($record !== null && (string)($record[$supplierField] ?? '') === $supplierId && in_array($id, $ignoreIds, true) === false) {
						return $id;
					}
				}
			}
		} catch (Throwable $e) {
			return self::DUPLICATE_UNKNOWN;
		}

		return null;

	}//end duplicateOf()

	/**
	 * The duplicate rule for an invoice whose supplier is not a known payee:
	 * another supplier invoice stating the same supplier identifier and number.
	 *
	 * @param string $administrationId   The administration.
	 * @param string $supplierIdentifier The supplier as the invoice states it.
	 * @param string $invoiceNumber      The invoice number.
	 *
	 * @return string|null The earlier invoice's id, DUPLICATE_UNKNOWN when the lookup failed, null when none.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
	 */
	public function duplicateOfUnresolved(string $administrationId, string $supplierIdentifier, string $invoiceNumber): ?string {
		if (trim($invoiceNumber) === '' || trim($supplierIdentifier) === '') {
			return null;
		}

		try {
			$rows = $this->scoped(schema: 'SupplierInvoice')->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'invoiceNumber' => trim($invoiceNumber),
						'supplierIdentifier' => trim($supplierIdentifier),
					],
					'limit' => 1,
				]
			);
		} catch (Throwable $e) {
			return self::DUPLICATE_UNKNOWN;
		}

		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				return (string)($record['id'] ?? self::DUPLICATE_UNKNOWN);
			}
		}

		return null;

	}//end duplicateOfUnresolved()

	/**
	 * The IBAN an invoice names when the payee record does not know it.
	 *
	 * Compared without spaces and case against the payee's bank account and
	 * the payee's supplier qualification. An invoice naming no IBAN is never
	 * a mismatch.
	 *
	 * @param string $administrationId The administration.
	 * @param string $supplierId       The payee id.
	 * @param string $invoiceIban      The IBAN on the invoice.
	 *
	 * @return array{invoiceIban: string, knownIban: string}|null The two IBANs, null when they agree.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
	 */
	public function ibanMismatch(string $administrationId, string $supplierId, string $invoiceIban): ?array {
		$iban = self::compact(value: $invoiceIban);
		if ($iban === '' || $supplierId === '') {
			return null;
		}

		$known = [];
		$payee = $this->payee(supplierId: $supplierId);
		$known[] = self::compact(value: (string)($payee['bankAccount']['iban'] ?? ''));
		foreach ($this->read(schema: 'SupplierQualification', administrationId: $administrationId) as $qualification) {
			if ((string)($qualification['supplierId'] ?? '') === $supplierId) {
				$known[] = self::compact(value: (string)($qualification['iban'] ?? ''));
			}
		}

		$known = array_values(array_filter($known, static fn (string $value): bool => $value !== ''));
		if (in_array($iban, $known, true) === true) {
			return null;
		}

		return ['invoiceIban' => $iban, 'knownIban' => (string)($known[0] ?? '')];

	}//end ibanMismatch()

	/**
	 * The warning fields for one invoice, as the checks find them now.
	 *
	 * @param array<string, mixed> $invoice The SupplierInvoice.
	 *
	 * @return array{duplicateOfId: string, ibanMismatch: string} Each '' when clear (the register holds strings, not null).
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
	 */
	public function warnings(array $invoice): array {
		$administrationId = (string)($invoice['administrationId'] ?? '');
		$supplierId = (string)($invoice['supplierId'] ?? '');
		$ignore = array_values(array_filter([(string)($invoice['id'] ?? ''), (string)($invoice['apTransactionId'] ?? '')]));

		$mismatch = $this->ibanMismatch(administrationId: $administrationId, supplierId: $supplierId, invoiceIban: (string)($invoice['payeeIban'] ?? ''));
		$text = '';
		if ($mismatch !== null) {
			$text = $mismatch['invoiceIban'] . ' / ' . $mismatch['knownIban'];
		}

		return [
			'duplicateOfId' => (string)$this->duplicateOf(
				administrationId: $administrationId,
				supplierId: $supplierId,
				invoiceNumber: (string)($invoice['invoiceNumber'] ?? ''),
				ignoreIds: $ignore
			),
			'ibanMismatch' => $text,
		];

	}//end warnings()

	/**
	 * A value without spaces, dots and dashes, upper case.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
	 */
	public static function compact(string $value): string {
		return strtoupper((string)preg_replace('/[\s.\-]+/', '', $value));

	}//end compact()

	/**
	 * Every record of a schema for the administration, with its id.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function read(string $schema, string $administrationId): array {
		$rows = $this->scoped(schema: $schema)->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => self::READ_LIMIT]);
		$records = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;

	}//end read()

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
