<?php

/**
 * Backfill Payment Request Customer
 *
 * A payment request that stands on its own (leges on a case, a dwangsom, a
 * deposit) now carries its debtor's customer in `customerId`, so the customer
 * portal lists it and the debtor can pay it there (REQ-SOPR-005). Requests
 * raised before that existed name the debtor only in `debtor.customerMasterId`
 * and would stay invisible. This step gives each of them the value once, with
 * the same rule the writers use (PaymentRequestPortalScope). A request on an
 * invoice, a name-and-email debtor and a request already stamped are left
 * alone, so a rerun saves nothing.
 *
 * Runs post-migration, after InitializeSettings has imported PaymentRequest
 * 0.5.0. Best-effort: a failure warns and never blocks the upgrade. Reads and
 * writes are unscoped, because the repair context has no user.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\PaymentRequestPortalScope;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps `customerId` on payment requests without an invoice.
 *
 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
 */
class BackfillPaymentRequestCustomer implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * The schema this step writes.
	 *
	 * @var string
	 */
	private const SCHEMA = 'PaymentRequest';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register slug.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param PaymentRequestPortalScope $portalScope The stamping rule the writers share.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly PaymentRequestPortalScope $portalScope = new PaymentRequestPortalScope(),
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function getName(): string {
		return 'Shillinq: give payment requests without an invoice their customer, so the portal lists them';
	}//end getName()

	/**
	 * Stamp every request that needs it.
	 *
	 * @param IOutput $output The repair-step output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function run(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();
			$rows = $this->readAllRows(
				objectService: $this->objectService,
				registerSlug: $registerSlug,
				schema: self::SCHEMA,
				filters: ['subjectKind' => 'object']
			);

			$stamped = 0;
			foreach ($rows as $row) {
				$request = $this->rowPayload(row: $row);
				$uuid = ObjectIdentifier::resolve(saved: $row);
				if ($uuid === '' || $this->portalScope->needsStamp(request: $request) === false) {
					continue;
				}

				$this->objectService->saveObject(
					object: $this->portalScope->stamp(request: $this->withoutMetadata(row: $request)),
					register: $registerSlug,
					schema: self::SCHEMA,
					uuid: $uuid,
					_rbac: false,
					_multitenancy: false,
				);
				$stamped++;
			}

			$output->info(
				sprintf(
					'Shillinq: %d payment request(s) without an invoice stamped with their customer, %d left as they were.',
					$stamped,
					(count($rows) - $stamped)
				)
			);
		} catch (Throwable $e) {
			$output->warning('Shillinq: the payment request customer backfill failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: the payment request customer backfill failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end run()

	/**
	 * A row without its id and OpenRegister metadata, ready to save back.
	 *
	 * @param array<string, mixed> $row The row as read.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function withoutMetadata(array $row): array {
		$data = [];
		foreach ($row as $key => $value) {
			if ($key === 'id' || str_starts_with((string)$key, '@') === true) {
				continue;
			}

			$data[$key] = $value;
		}

		return $data;
	}//end withoutMetadata()
}//end class
