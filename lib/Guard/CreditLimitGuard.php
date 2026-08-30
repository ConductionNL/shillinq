<?php

/**
 * Credit Limit Guard
 *
 * Lifecycle precondition for the ARInvoice `issue` transition referenced from
 * lib/Settings/shillinq_register.json. Thin PHP seam per ADR-031
 * §"PHP guards remain a legitimate seam" and the Risk-3 exception — the
 * declarative aggregation engine can sum outstanding amounts, but it cannot
 * enforce a per-transition precondition that compares the running outstanding
 * total (across other invoices) plus the invoice being issued against the
 * customer's credit limit. No domain logic beyond that single precondition.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Guards the ARInvoice `issue` transition against the customer credit limit.
 *
 * The single method is referenced by name from the ARInvoice schema's
 * x-openregister-lifecycle `requires:` clause on the `issue` transition.
 * It returns true when issuing the invoice keeps the customer's outstanding
 * receivable within their configured credit limit, false otherwise.
 *
 * All amounts are integer cents — never floats — so the comparison is exact
 * and free of IEEE-754 rounding error. The check is fail-closed: any error
 * resolving the customer or summing outstanding invoices denies the issue.
 *
 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md (REQ-007-002)
 */
class CreditLimitGuard {
	/**
	 * Statuses that count toward a customer's outstanding receivable.
	 *
	 * Paid and written-off invoices are excluded — they no longer consume
	 * credit. Mirrors the ARInvoice outstandingByCustomer aggregation filter.
	 */
	private const OUTSTANDING_STATUSES = ['draft', 'issued', 'overdue', 'disputed'];

	/**
	 * Construct the guard with lazy DI of OR's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for dynamic register slug resolution.
	 * @param LoggerInterface $logger Nextcloud logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq' if unset.
	 *
	 * Single source of truth — mirrors AccountBalanceGuard::getRegisterSlug() and
	 * SettingsService::getRegisterSlug() so all reads use the same register even
	 * when the admin reconfigures the slug.
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
	 * Precondition for the ARInvoice `issue` transition: the customer's
	 * outstanding receivable (sum of non-paid, non-written-off invoices in the
	 * same administration, excluding this invoice) plus this invoice's total
	 * must not exceed the customer's credit limit.
	 *
	 * **No credit limit set** (null or absent `creditLimitCents`): the customer
	 * has unlimited credit by policy — the issue is permitted.
	 *
	 * **Customer not found**: fail-closed — deny the issue and log, because a
	 * dangling customerNumber should never silently bypass credit control.
	 *
	 * All amounts are integer cents.
	 *
	 * @param array<string, mixed> $invoice ARInvoice object array (loaded by OR)
	 *
	 * @return bool True when issuing keeps the customer within their credit limit
	 *
	 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md (REQ-007-002)
	 */
	public function requireWithinCreditLimit(array $invoice): bool {
		$customerNumber = (string)($invoice['customerNumber'] ?? '');
		$administrationId = (string)($invoice['administrationId'] ?? '');

		if ($customerNumber === '' || $administrationId === '') {
			$this->logger->error(
				'CreditLimitGuard: invoice missing customerNumber or administrationId — denying issue (fail-closed)',
				['invoiceNumber' => ($invoice['invoiceNumber'] ?? 'unknown')]
			);
			return false;
		}

		try {
			$registerSlug = $this->getRegisterSlug();

			// Resolve the customer to read their credit limit.
			$customers = $this->objectService
				->setRegister($registerSlug)
				->setSchema('CustomerMaster')
				->findAll(
					[
						'filters' => [
							'customerNumber' => $customerNumber,
							'administrationId' => $administrationId,
						],
						'limit' => 1,
					]
				);

			if (empty($customers) === true) {
				$this->logger->error(
					'CreditLimitGuard: customer not found — denying issue (fail-closed)',
					[
						'customerNumber' => $customerNumber,
						'administrationId' => $administrationId,
					]
				);
				return false;
			}

			$customer = $customers[0];
			$creditLimitCents = $customer['creditLimitCents'] ?? null;

			// No credit limit configured → unlimited credit by policy.
			if ($creditLimitCents === null) {
				return true;
			}

			$creditLimitCents = (int)$creditLimitCents;

			// Sum the customer's outstanding receivable, excluding this invoice
			// (so a re-issue or idempotent retry doesn't double-count itself).
			$outstandingCents = $this->sumOutstandingCents(
				objectService: $this->objectService,
				registerSlug: $registerSlug,
				customerNumber: $customerNumber,
				administrationId: $administrationId,
				excludeInvoiceNumber: (string)($invoice['invoiceNumber'] ?? '')
			);
			$thisInvoiceCents = (int)($invoice['totalCents'] ?? 0);
			$projectedCents = ($outstandingCents + $thisInvoiceCents);

			return $projectedCents <= $creditLimitCents;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CreditLimitGuard: credit-limit computation failed — denying issue (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireWithinCreditLimit()

	/**
	 * Sum the outstanding receivable for a customer in integer cents.
	 *
	 * Pages through all outstanding ARInvoice records (status in
	 * OUTSTANDING_STATUSES) for the customer in the administration, excluding
	 * the invoice currently being issued. Uses integer cents throughout.
	 *
	 * @param object $objectService OR ObjectService (already DI-resolved).
	 * @param string $registerSlug Configured register slug.
	 * @param string $customerNumber Customer FK.
	 * @param string $administrationId Administration scope (multi-tenant).
	 * @param string $excludeInvoiceNumber Invoice to exclude from the sum.
	 *
	 * @return int Outstanding receivable in integer cents.
	 */
	private function sumOutstandingCents(
		object $objectService,
		string $registerSlug,
		string $customerNumber,
		string $administrationId,
		string $excludeInvoiceNumber,
	): int {
		$pageSize = 500;
		$page = 1;
		$total = 0;
		$batchSize = 0;

		do {
			$batch = $objectService
				->setRegister($registerSlug)
				->setSchema('ARInvoice')
				->findAll(
					[
						'filters' => [
							'customerNumber' => $customerNumber,
							'administrationId' => $administrationId,
						],
						'limit' => $pageSize,
						'offset' => (($page - 1) * $pageSize),
					]
				);

			foreach ($batch as $line) {
				$status = (string)($line['status'] ?? '');
				if (in_array($status, self::OUTSTANDING_STATUSES, true) === false) {
					continue;
				}

				if ((string)($line['invoiceNumber'] ?? '') === $excludeInvoiceNumber && $excludeInvoiceNumber !== '') {
					continue;
				}

				$total += (int)($line['totalCents'] ?? 0);
			}

			$batchSize = count($batch);
			$page++;
		} while ($batchSize === $pageSize);

		return $total;
	}//end sumOutstandingCents()
}//end class
