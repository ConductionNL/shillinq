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

use OCA\Shillinq\AppInfo\AppIdentity;
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
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
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
		if ($this->hasCustomerScope(invoice: $invoice) === false) {
			return false;
		}

		try {
			return $this->withinLimit(run: new CreditLimitRun(invoice: $invoice, registerSlug: $this->getRegisterSlug()));
		} catch (\Throwable $e) {
			$this->logger->error(
				'CreditLimitGuard: credit-limit computation failed — denying issue (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireWithinCreditLimit()

	/**
	 * Whether the invoice names its customer and administration; fail-closed
	 * and logged when it does not.
	 *
	 * @param array<string, mixed> $invoice ARInvoice object array.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md (REQ-007-002)
	 */
	private function hasCustomerScope(array $invoice): bool {
		$customerNumber = (string)($invoice['customerNumber'] ?? '');
		$administrationId = (string)($invoice['administrationId'] ?? '');

		if ($customerNumber === '' || $administrationId === '') {
			$this->logger->error(
				'CreditLimitGuard: invoice missing customerNumber or administrationId — denying issue (fail-closed)',
				['invoiceNumber' => ($invoice['invoiceNumber'] ?? 'unknown')]
			);
			return false;
		}

		return true;
	}//end hasCustomerScope()

	/**
	 * Resolve the customer to read their credit limit; a customer that cannot
	 * be found denies the issue (logged).
	 *
	 * @param CreditLimitRun $run The check.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md (REQ-007-002)
	 */
	private function withinLimit(CreditLimitRun $run): bool {
		$customer = $this->findCustomer(run: $run);
		if ($customer === null) {
			$this->logger->error(
				'CreditLimitGuard: customer not found — denying issue (fail-closed)',
				[
					'customerNumber' => $run->customerNumber,
					'administrationId' => $run->administrationId,
				]
			);
			return false;
		}

		return $this->limitHolds(run: $run, customer: $customer);
	}//end withinLimit()

	/**
	 * The customer of the check, or null when there is none.
	 *
	 * @param CreditLimitRun $run The check.
	 *
	 * @return mixed The first customer record, or null.
	 */
	private function findCustomer(CreditLimitRun $run): mixed {
		$customers = $this->objectService
			->setRegister($run->registerSlug)
			->setSchema('CustomerMaster')
			->findAll($run->customerQuery());

		if (empty($customers) === true) {
			return null;
		}

		return $customers[0];
	}//end findCustomer()

	/**
	 * Whether the outstanding receivable plus this invoice stays within the
	 * customer's credit limit. No credit limit configured → unlimited credit by policy.
	 *
	 * @param CreditLimitRun $run      The check.
	 * @param mixed          $customer The customer record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md (REQ-007-002)
	 */
	private function limitHolds(CreditLimitRun $run, mixed $customer): bool {
		$creditLimitCents = $customer['creditLimitCents'] ?? null;
		if ($creditLimitCents === null) {
			return true;
		}

		$projectedCents = ($this->sumOutstandingCents(run: $run) + $run->totalCents);

		return $projectedCents <= (int)$creditLimitCents;
	}//end limitHolds()

	/**
	 * Sum the outstanding receivable for a customer in integer cents.
	 *
	 * Pages through all ARInvoice records for the customer in the
	 * administration, counting the outstanding ones except the invoice
	 * currently being issued. Uses integer cents throughout.
	 *
	 * @param CreditLimitRun $run The check.
	 *
	 * @return int Outstanding receivable in integer cents.
	 */
	private function sumOutstandingCents(CreditLimitRun $run): int {
		$page = 1;
		$total = 0;

		do {
			$batch = $this->objectService
				->setRegister($run->registerSlug)
				->setSchema('ARInvoice')
				->findAll($run->invoicePageQuery(page: $page));

			$total += $this->outstandingIn(run: $run, batch: $batch);
			$batchSize = count($batch);
			$page++;
		} while ($batchSize === CreditLimitRun::PAGE_SIZE);

		return $total;
	}//end sumOutstandingCents()

	/**
	 * The cents of the invoices in a page that count toward the outstanding receivable.
	 *
	 * @param CreditLimitRun    $run   The check.
	 * @param array<int, mixed> $batch One page of ARInvoice records.
	 *
	 * @return int
	 */
	private function outstandingIn(CreditLimitRun $run, array $batch): int {
		$total = 0;
		foreach ($batch as $line) {
			if ($run->countsTowardOutstanding(line: $line) === true) {
				$total += (int)($line['totalCents'] ?? 0);
			}
		}

		return $total;
	}//end outstandingIn()
}//end class
