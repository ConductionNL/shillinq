<?php

/**
 * VAT number check
 *
 * Checks the VAT number on a customer, a supplier or an incoming supplier
 * invoice against the EU VIES register through ViesService, and writes the
 * outcome onto the record: valid, invalid or not reachable, with the date of
 * the last valid result (tax-vat-number-check).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Tax;

use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\ViesService;
use Psr\Log\LoggerInterface;

/**
 * Validates a record's VAT number through VIES and keeps the outcome on the record.
 */
class VatNumberCheck {

	/**
	 * The records a person can check, by route type: schema and the field holding the number.
	 *
	 * @var array<string,array{schema:string,field:string}>
	 */
	public const RECORDS = [
		'customer' => ['schema' => 'CustomerMaster', 'field' => 'vatId'],
		'supplier' => ['schema' => 'Payee', 'field' => 'vatNumber'],
	];

	/**
	 * The VIES member state prefixes (Greece is EL, Northern Ireland XI).
	 *
	 * @var list<string>
	 */
	public const EU_PREFIXES = [
		'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
		'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
	];

	/**
	 * Constructor.
	 *
	 * @param ViesService            $vies          The VIES client and evidence store.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SettingsService        $settings      For the register slug.
	 * @param LoggerInterface        $logger        Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ViesService $vies,
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Check a customer's or supplier's VAT number and write the outcome onto it (REQ-TVNC-001).
	 *
	 * A valid answer sets the status, the check date and the validity; an
	 * invalid one sets the status and the check date. When VIES cannot be
	 * reached the status reads not reachable and the date of the last valid
	 * result is kept, so the page shows when the number was last confirmed.
	 *
	 * @param string              $type   `customer` or `supplier`.
	 * @param array<string,mixed> $record The record, with `id` and `administrationId`.
	 *
	 * @return array{status:string,vatId:string,checkedAt:string,lastValidAt:?string,name:string,address:string}
	 *
	 * @throws DomainException When the record carries no VAT number.
	 *
	 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
	 */
	public function checkRecord(string $type, array $record): array {
		$target = self::RECORDS[$type];
		$number = trim((string)($record[$target['field']] ?? ''));
		if ($number === '') {
			throw new DomainException('This record has no VAT number to check.');
		}

		$administrationId = (string)($record['administrationId'] ?? '');
		$result = $this->vies->validate(administrationId: $administrationId, vatId: $number);
		$status = $this->status(result: $result);
		$lastValidAt = $this->lastValidAt(record: $record, prefix: 'vatId', result: $result, status: $status);

		$fields = ['vatIdValidationStatus' => $status, 'vatIdValidatedAt' => $lastValidAt];
		if ($status === 'valid') {
			$fields['vatIdValidUntil'] = $result['validUntil'];
		}

		$this->patch(schema: $target['schema'], id: (string)$record['id'], fields: $fields);

		return [
			'status' => $status,
			'vatId' => $result['vatId'],
			'checkedAt' => $result['validationTimestamp'],
			'lastValidAt' => $lastValidAt,
			'name' => $result['name'],
			'address' => $result['address'],
		];

	}//end checkRecord()

	/**
	 * Check the seller's number of an arrived supplier invoice when it is from another EU country (REQ-TVNC-003).
	 *
	 * Dutch and non-EU numbers are left alone. A failure is logged and never
	 * stops the intake.
	 *
	 * @param array<string,mixed> $invoice The saved supplier invoice, with `id`, `administrationId` and `sellerVatId`.
	 *
	 * @return array<string,string|null>|null The fields written, or null when no check applies.
	 *
	 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.2
	 */
	public function checkSellerOnArrival(array $invoice): ?array {
		$number = $this->vies->canonicalVatId(vatId: (string)($invoice['sellerVatId'] ?? ''));
		$prefix = substr($number, 0, 2);
		if (strlen($number) < 4 || $prefix === 'NL' || in_array($prefix, self::EU_PREFIXES, true) === false) {
			return null;
		}

		$invoiceId = (string)($invoice['id'] ?? '');
		if ($invoiceId === '') {
			return null;
		}

		try {
			$result = $this->vies->validate(administrationId: (string)($invoice['administrationId'] ?? ''), vatId: $number);
			$status = $this->status(result: $result);
			$fields = [
				'sellerVatIdValidationStatus' => $status,
				'sellerVatIdValidatedAt' => $this->lastValidAt(record: $invoice, prefix: 'sellerVatId', result: $result, status: $status),
			];
			$this->patch(schema: 'SupplierInvoice', id: $invoiceId, fields: $fields);

			return $fields;
		} catch (\Throwable $e) {
			$this->logger->warning('VatNumberCheck: the seller VAT number of an arrived invoice was not checked', ['invoiceId' => $invoiceId, 'exception' => $e->getMessage()]);

			return null;
		}

	}//end checkSellerOnArrival()

	/**
	 * Map a VIES outcome onto the record's status enum.
	 *
	 * @param array<string,mixed> $result The ViesService outcome.
	 *
	 * @return string valid, invalid or vies_outage.
	 */
	private function status(array $result): string {
		if ((bool)$result['outage'] === true) {
			return 'vies_outage';
		}

		if ((bool)$result['valid'] === true) {
			return 'valid';
		}

		return 'invalid';

	}//end status()

	/**
	 * The date of the last valid result after this check.
	 *
	 * A valid answer is today's. Otherwise the record's earlier date stays, or,
	 * when VIES is down and the record has none, the last valid evidence VIES
	 * gave for this number in the administration.
	 *
	 * @param array<string,mixed> $record The record before the check.
	 * @param string              $prefix The record's field prefix (`vatId` or `sellerVatId`).
	 * @param array<string,mixed> $result The ViesService outcome.
	 * @param string              $status The status written.
	 *
	 * @return string|null The date, or null when the number was never valid.
	 */
	private function lastValidAt(array $record, string $prefix, array $result, string $status): ?string {
		if ($status === 'valid') {
			return (string)$result['validationTimestamp'];
		}

		$earlier = trim((string)($record[$prefix . 'ValidatedAt'] ?? ''));
		if ($earlier !== '') {
			return $earlier;
		}

		if ($status !== 'vies_outage') {
			return null;
		}

		$prior = $this->vies->findRecentValid(administrationId: (string)($record['administrationId'] ?? ''), vatId: (string)$result['vatId']);
		if ($prior === null) {
			return null;
		}

		return (string)($prior['validationTimestamp'] ?? '');

	}//end lastValidAt()

	/**
	 * Write the outcome fields onto the record (a partial write).
	 *
	 * @param string              $schema The schema slug.
	 * @param string              $id     The record id.
	 * @param array<string,mixed> $fields The fields to write.
	 *
	 * @return void
	 */
	private function patch(string $schema, string $id, array $fields): void {
		$this->objectService->patchObject(objectId: $id, data: $fields, register: $this->settings->getRegisterSlug(), schema: $schema);

	}//end patch()
}//end class
