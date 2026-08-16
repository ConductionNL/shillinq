<?php

/**
 * Account Balance Guard
 *
 * Lifecycle preconditions for Account state transitions referenced from
 * lib/Settings/shillinq_register.json. Thin PHP seam per ADR-031
 * §"PHP guards remain a legitimate seam" — no domain logic, only
 * preconditions that the declarative lifecycle engine cannot express.
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
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
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
 * Guards Account lifecycle transitions + closing-account uniqueness.
 *
 * Methods are referenced by name from the Account schema's
 * x-openregister-lifecycle `requires:` clauses. Each returns true when the
 * precondition is satisfied (transition / save permitted), false otherwise.
 *
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 */
class AccountBalanceGuard {
	/**
	 * Construct the guard with lazy DI of OR's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for dynamic register slug resolution (C3).
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
	 * C3: single source of truth — mirrors DeepLinkRegistrationListener and
	 * SettingsService::getRegisterSlug() so all writes and reads use the same
	 * register even when the admin reconfigures the slug.
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
	 * Precondition for `archive*` transitions: the account's running balance
	 * must be zero before it can be archived (otherwise downstream period-close
	 * + financial-statements would see orphan postings).
	 *
	 * **T1 behaviour:** balance is implicitly 0 because no GLLine register
	 * exists yet (T2 `bookkeeping-general-ledger` introduces it). Returns
	 * true unconditionally with a debug log noting the deferral.
	 *
	 * **T2+ behaviour:** retrieves all GLLine records for the account via
	 * OR's real `setRegister()->setSchema()->findAll()` API and sums
	 * debit minus credit in PHP. Returns true iff the sum is zero.
	 *
	 * @param array<string, mixed> $account Account object array (loaded by OR)
	 *
	 * @return bool True when archive is permitted
	 *
	 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md (REQ-CoA-005)
	 */
	public function requireZeroBalance(array $account): bool {
		// ADR-084 removed the T1 probe that used to live here: it resolved
		// ObjectService from the container inside a try/catch so that "OR absent"
		// (permit) stayed distinguishable from "computation failed" (deny). With
		// the contract injected non-nullably that probe became an EMPTY try block
		// whose catch could never fire — a dead branch that read like a live
		// fail-open. It is deleted rather than left looking load-bearing.
		//
		// Consequence, stated rather than hidden: on an instance without
		// OpenRegister the call below throws and the fail-closed catch DENIES the
		// archive. That is the safe direction, but it is a behaviour change from
		// the T1 permit, and it belongs to the ADR-084 migration, not to this fix.
		//
		// T2+ check: compute balance via GLLine records. Fail-closed on any error
		// so a transient DB failure denies archive rather than silently permitting it.
		// The ObjectService is resolved again inside this guarded block so a failure
		// to resolve it for the computation path also denies (rather than permits).
		try {
			// Page through all GLLine records in batches to avoid hitting the
			// default findAll() limit when an account has many postings (L1).
			$pageSize = 500;
			$page = 1;
			$lines = [];
			$batchSize = 0;
			do {
				$batch = $this->objectService
					->setRegister($this->getRegisterSlug())
					->setSchema('GLLine')
					->findAll(
						[
							'filters' => [
								'accountNumber' => ($account['accountNumber'] ?? ''),
								'administrationId' => ($account['administrationId'] ?? ''),
							],
							'limit' => $pageSize,
							'offset' => ($page - 1) * $pageSize,
						]
					);
				$lines = array_merge($lines, $batch);
				$batchSize = count($batch);
				$page++;
			} while ($batchSize === $pageSize);

			// Use integer cents to avoid IEEE-754 float equality issues (C1).
			// 0.1 + 0.2 - 0.3 in floats ≠ 0.0, but (10 + 20 - 30) === 0.
			$balanceCents = array_sum(
				array_map(
					static fn ($line) => (int)round(((float)($line['debit'] ?? 0) - (float)($line['credit'] ?? 0)) * 100),
					$lines
				)
			);

			return $balanceCents === 0;
		} catch (\Throwable $e) {
			$this->logger->error(
				'AccountBalanceGuard: balance computation failed — denying archive (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end requireZeroBalance()

	/**
	 * Precondition for save: at most one Account per administration may have
	 * `isClosingAccount: true` (REQ-CoA-009). Called from the Account schema's
	 * lifecycle on every save attempt. Returns false if a different Account
	 * in the same administration already has `isClosingAccount: true`.
	 *
	 * Permits the same account to be re-saved (e.g., editing its name) — the
	 * uniqueness check excludes the current record by primary key.
	 *
	 * @param array<string, mixed> $account Account object array (loaded by OR)
	 *
	 * @return bool True when the closing-account invariant holds
	 *
	 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md (REQ-CoA-009)
	 */
	public function requireSingleClosingAccount(array $account): bool {
		if (($account['isClosingAccount'] ?? false) !== true) {
			// Account isn't being marked as closing — invariant trivially holds.
			return true;
		}

		try {
			// No LIMIT: we need ALL closing accounts in the administration so we
			// can correctly exclude the current record and count the remainder (L2).
			$existing = $this->objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('Account')
				->findAll(
					[
						'filters' => [
							'administrationId' => ($account['administrationId'] ?? ''),
							'isClosingAccount' => true,
						],
					]
				);

			// Filter out the current account (by id, if persisted; by accountNumber otherwise).
			// Defence-in-depth: also verify administrationId matches to prevent cross-tenant leakage.
			$currentId = ($account['id'] ?? null);
			$currentAccountNumber = ($account['accountNumber'] ?? null);
			$currentAdminId = ($account['administrationId'] ?? null);
			$otherClosing = array_filter(
				$existing,
				static function ($candidate) use ($currentId, $currentAccountNumber, $currentAdminId) {
					// Cross-tenant defence: only consider candidates in the same administration.
					if ($currentAdminId !== null && ($candidate['administrationId'] ?? null) !== $currentAdminId) {
						return false;
					}

					if ($currentId !== null && ($candidate['id'] ?? null) === $currentId) {
						return false;
					}

					if ($currentId === null && ($candidate['accountNumber'] ?? null) === $currentAccountNumber) {
						return false;
					}

					return true;
				}
			);

			return count($otherClosing) === 0;
		} catch (\Throwable $e) {
			$this->logger->error(
				'AccountBalanceGuard: closing-account uniqueness check failed — denying save (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end requireSingleClosingAccount()
}//end class
