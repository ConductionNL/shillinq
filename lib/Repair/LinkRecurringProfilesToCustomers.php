<?php

/**
 * Link Recurring Profiles To Customers
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A recurring profile names its customer as a Nextcloud contact; from
 * sales-cancellation on it also carries the `CustomerMaster` it bills
 * (`customerMasterId`), which every generated invoice copies into its
 * `customerId` and the portal's subscriptions scope on. Profiles made before
 * that have none. This step gives each the customer its contact resolves to
 * (ContactCustomerResolver) and names, in the upgrade log, every profile it
 * could not resolve, so someone can link it by hand. A profile already linked
 * is left alone, so a rerun saves nothing.
 *
 * Runs post-migration, after InitializeSettings has imported
 * RecurringInvoiceProfile 0.2.0. Best-effort: a failure warns and never blocks
 * the upgrade. Reads and writes are unscoped, because the repair context has
 * no user.
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
 * @spec openspec/changes/sales-cancellation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\ContactCustomerResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills `customerMasterId` on recurring profiles that lack it.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md
 */
class LinkRecurringProfilesToCustomers implements IRepairStep {
	use ReadsSourceRowsInBatches;

	private const SCHEMA = 'RecurringInvoiceProfile';

	/**
	 * Constructor.
	 *
	 * @param RegisterLocation        $settingsService The register slug.
	 * @param LoggerInterface         $logger          Logger.
	 * @param ObjectServiceInterface  $objectService   OpenRegister's objects.
	 * @param ContactCustomerResolver $resolver        Contact to customer.
	 */
	public function __construct(
		private readonly RegisterLocation $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly ContactCustomerResolver $resolver,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade log.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md
	 */
	public function getName(): string {
		return 'Shillinq: link recurring profiles to the customer their contact resolves to';
	}//end getName()

	/**
	 * Link every unlinked profile that resolves, and name the rest.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md
	 */
	public function run(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();
			$customers    = $this->customers(registerSlug: $registerSlug);
			$linked       = 0;
			$unresolved   = [];
			foreach ($this->readAllRows(objectService: $this->objectService, registerSlug: $registerSlug, schema: self::SCHEMA) as $row) {
				$profile = $this->rowPayload(row: $row);
				$uuid    = ObjectIdentifier::resolve(saved: $row);
				if ($uuid === '' || trim((string)($profile['customerMasterId'] ?? '')) !== '') {
					continue;
				}

				$customerId = $this->resolver->resolve(reference: (string)($profile['customerReference'] ?? ''), customers: $customers);
				if ($customerId === null) {
					$unresolved[] = trim((string)($profile['name'] ?? '')) . ' (' . $uuid . ')';
					continue;
				}

				$profile['customerMasterId'] = $customerId;
				$this->objectService->saveObject(
					object: self::withoutMetadata(row: $profile),
					register: $registerSlug,
					schema: self::SCHEMA,
					uuid: $uuid,
					_rbac: false,
					_multitenancy: false,
				);
				$linked++;
			}//end foreach

			$output->info(sprintf('Shillinq: %d recurring profile(s) linked to their customer, %d could not be resolved.', $linked, count($unresolved)));
			if ($unresolved !== []) {
				$output->warning('Shillinq: link these recurring profiles to their customer by hand: ' . implode(', ', $unresolved));
			}
		} catch (Throwable $e) {
			$output->warning('Shillinq: linking recurring profiles to their customer failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: linking recurring profiles to their customer failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end run()

	/**
	 * Every CustomerMaster row, with its id.
	 *
	 * @param string $registerSlug The register.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function customers(string $registerSlug): array {
		$customers = [];
		foreach ($this->readAllRows(objectService: $this->objectService, registerSlug: $registerSlug, schema: 'CustomerMaster') as $row) {
			$customers[] = array_merge($this->rowPayload(row: $row), ['id' => ObjectIdentifier::resolve(saved: $row)]);
		}

		return $customers;
	}//end customers()

	/**
	 * The row without its id and `@` metadata, as a save expects it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private static function withoutMetadata(array $row): array {
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
