<?php

/**
 * Open Balance Guard
 *
 * Lifecycle preconditions for vendor/customer master-data transitions and
 * AR/AP invoice creation referenced from lib/Settings/shillinq_register.json.
 * Thin PHP seam per ADR-031 §"PHP guards remain a legitimate seam" — each
 * method is a single precondition the declarative lifecycle engine cannot
 * yet express (it needs to aggregate sub-ledger balances or read related
 * master records). No domain orchestration, no report assembly.
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
 * @spec openspec/specs/bookkeeping-accounts-payable-core/spec.md (REQ-AP-008)
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md (REQ-AR-006)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards vendor/customer archival, blocked-state propagation, and the AR
 * credit-limit precondition.
 *
 * Methods are referenced by name from the VendorMaster / CustomerMaster /
 * APInvoice / ARInvoice schema lifecycle `requires:` / `preconditions:`
 * clauses. Each returns true when the precondition is satisfied.
 *
 * @spec openspec/specs/bookkeeping-accounts-payable-core/spec.md#req-ap-002
 */
class OpenBalanceGuard {
	/**
	 * Construct the guard with lazy DI of OR's ObjectService.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is
	 *                                      fetched lazily so this class stays
	 *                                      usable before related registers exist.
	 * @param IAppConfig $appConfig App config for register-slug resolution.
	 * @param LoggerInterface $logger Nextcloud logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the configured register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Sum the outstanding gross amount of open sub-ledger invoices for a party.
	 *
	 * Pages through all matching invoices and sums grossAmount in integer cents
	 * to avoid IEEE-754 float drift. Open = lifecycleState NOT in the excluded
	 * set. Returns the balance in cents, or null when the schema is unavailable.
	 *
	 * @param string $schema 'APInvoice' or 'ARInvoice'.
	 * @param string $partyField 'vendorId' or 'customerId'.
	 * @param string $partyId The party identifier.
	 * @param string $adminId The administration identifier.
	 * @param array<string> $excluded lifecycleState values to exclude.
	 *
	 * @return int|null Balance in cents, or null when the schema is not present.
	 */
	private function openBalanceCents(
		string $schema,
		string $partyField,
		string $partyId,
		string $adminId,
		array $excluded,
	): ?int {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->debug('OpenBalanceGuard: ObjectService unavailable', ['exception' => $e->getMessage()]);
			return null;
		}

		try {
			$pageSize = 500;
			$page = 1;
			$cents = 0;
			do {
				$batch = $objectService
					->setRegister($this->getRegisterSlug())
					->setSchema($schema)
					->findAll(
						[
							'filters' => [
								$partyField => $partyId,
								'administrationId' => $adminId,
							],
							'limit' => $pageSize,
							'offset' => (($page - 1) * $pageSize),
						]
					);

				foreach ($batch as $invoice) {
					if (in_array(($invoice['lifecycleState'] ?? ''), $excluded, true) === true) {
						continue;
					}

					$cents += (int)round(((float)($invoice['grossAmount'] ?? 0)) * 100);
				}

				$batchSize = count($batch);
				$page++;
			} while ($batchSize === $pageSize);

			return $cents;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'OpenBalanceGuard: balance query failed (schema likely absent)',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end openBalanceCents()

	/**
	 * Read a single master record (vendor or customer) by its business key.
	 *
	 * @param string $schema 'VendorMaster' or 'CustomerMaster'.
	 * @param string $keyField 'vendorId' or 'customerId'.
	 * @param string $keyValue The business-key value.
	 * @param string $adminId The administration identifier.
	 *
	 * @return array<string,mixed>|null The record, or null when not found/unavailable.
	 */
	private function findMaster(string $schema, string $keyField, string $keyValue, string $adminId): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$matches = $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema($schema)
				->findAll(
					[
						'filters' => [
							$keyField => $keyValue,
							'administrationId' => $adminId,
						],
						'limit' => 1,
					]
				);

			if (empty($matches) === true) {
				return null;
			}

			return $matches[0];
		} catch (\Throwable $e) {
			$this->logger->debug(
				'OpenBalanceGuard: master lookup failed',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end findMaster()

	/**
	 * Precondition for VendorMaster archival: the vendor must have a zero open
	 * AP balance (REQ-AP-008). When the APInvoice schema is not yet present the
	 * balance is implicitly zero and archival is permitted.
	 *
	 * @param array<string,mixed> $vendor VendorMaster object array.
	 *
	 * @return bool True when archival is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-payable-core/spec.md#req-ap-002
	 */
	public function vendorBalanceZero(array $vendor): bool {
		$cents = $this->openBalanceCents(
			schema: 'APInvoice',
			partyField: 'vendorId',
			partyId: (string)($vendor['vendorId'] ?? ''),
			adminId: (string)($vendor['administrationId'] ?? ''),
			excluded: ['paid', 'voided']
		);

		return ($cents === null || $cents === 0);
	}//end vendorBalanceZero()

	/**
	 * Precondition for CustomerMaster archival: the customer must have a zero
	 * open AR balance. When the ARInvoice schema is absent, archival is allowed.
	 *
	 * @param array<string,mixed> $customer CustomerMaster object array.
	 *
	 * @return bool True when archival is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md#req-ar-002
	 */
	public function customerBalanceZero(array $customer): bool {
		$cents = $this->openBalanceCents(
			schema: 'ARInvoice',
			partyField: 'customerId',
			partyId: (string)($customer['customerId'] ?? ''),
			adminId: (string)($customer['administrationId'] ?? ''),
			excluded: ['paid', 'written-off']
		);

		return ($cents === null || $cents === 0);
	}//end customerBalanceZero()

	/**
	 * Precondition for APInvoice creation: the referenced vendor must not be in
	 * the `blocked` lifecycle state (REQ-AP-008). When the vendor cannot be
	 * resolved the precondition is permissive (the FK validation handles a
	 * genuinely missing vendor); only an explicitly blocked vendor is rejected.
	 *
	 * @param array<string,mixed> $invoice APInvoice object array.
	 *
	 * @return bool True when the vendor is not blocked.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-payable-core/spec.md#req-ap-002
	 */
	public function vendorNotBlocked(array $invoice): bool {
		$vendor = $this->findMaster(
			schema: 'VendorMaster',
			keyField: 'vendorId',
			keyValue: (string)($invoice['vendorId'] ?? ''),
			adminId: (string)($invoice['administrationId'] ?? '')
		);

		if ($vendor === null) {
			return true;
		}

		return (($vendor['lifecycleState'] ?? 'active') !== 'blocked');
	}//end vendorNotBlocked()

	/**
	 * Precondition for ARInvoice creation: the referenced customer must not be
	 * blocked. Permissive when the customer cannot be resolved.
	 *
	 * @param array<string,mixed> $invoice ARInvoice object array.
	 *
	 * @return bool True when the customer is not blocked.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md#req-ar-002
	 */
	public function customerNotBlocked(array $invoice): bool {
		$customer = $this->findMaster(
			schema: 'CustomerMaster',
			keyField: 'customerId',
			keyValue: (string)($invoice['customerId'] ?? ''),
			adminId: (string)($invoice['administrationId'] ?? '')
		);

		if ($customer === null) {
			return true;
		}

		return (($customer['lifecycleState'] ?? 'active') !== 'blocked');
	}//end customerNotBlocked()

	/**
	 * Precondition for the ARInvoice `draft → issued` transition: issuing this
	 * invoice must not push the customer's outstanding AR balance past the
	 * customer's creditLimit (REQ-AR-006). When creditLimit is null or 0 the
	 * check is skipped. The outstanding-balance aggregation is declared on the
	 * ARInvoice schema (outstandingByCustomer); this guard composes that data
	 * with the candidate invoice amount — the engine cannot yet express a
	 * "current sum + this row" precondition declaratively.
	 *
	 * @param array<string,mixed> $invoice ARInvoice object array being issued.
	 *
	 * @return bool True when issuing stays within the credit limit.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md#req-ar-006
	 */
	public function withinCreditLimit(array $invoice): bool {
		$customer = $this->findMaster(
			schema: 'CustomerMaster',
			keyField: 'customerId',
			keyValue: (string)($invoice['customerId'] ?? ''),
			adminId: (string)($invoice['administrationId'] ?? '')
		);

		$limit = (float)($customer['creditLimit'] ?? 0);
		if ($customer === null || $limit <= 0.0) {
			// No limit configured — check skipped per REQ-AR-006.
			return true;
		}

		$outstandingCents = $this->openBalanceCents(
			schema: 'ARInvoice',
			partyField: 'customerId',
			partyId: (string)($invoice['customerId'] ?? ''),
			adminId: (string)($invoice['administrationId'] ?? ''),
			excluded: ['paid', 'written-off']
		);

		if ($outstandingCents === null) {
			// ARInvoice schema not present — nothing outstanding yet.
			$outstandingCents = 0;
		}

		$candidateCents = (int)round(((float)($invoice['grossAmount'] ?? 0)) * 100);
		$limitCents = (int)round($limit * 100);

		return (($outstandingCents + $candidateCents) <= $limitCents);
	}//end withinCreditLimit()
}//end class
