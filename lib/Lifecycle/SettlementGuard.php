<?php

/**
 * Settlement Guard
 *
 * ADR-031 exception-path lifecycle guard for dual-mode expense settlement
 * (expense-reimbursement-or-passthrough). Implements the cross-schema and
 * submission-time-immutability rules that the declarative OpenRegister DSL
 * cannot yet express:
 *
 *  - settlement classification + mixed-mode rejection at submit (REQ-ERP-003);
 *  - pass-through customer + AR-account validation at submit (REQ-ERP-002);
 *  - markup-rule priority lookup + amount calculation (REQ-ERP-002/005);
 *  - claim aggregate totals over child receipts (REQ-ERP-003);
 *  - settlement-mode immutability after submission + reversal authorisation
 *    on a post-submission mode change (REQ-ERP-010/011);
 *  - the reimbursement notification payload on post (REQ-ERP-008).
 *
 * Referenced from the expense-reimbursement-or-passthrough register fragment
 * (lib/Settings/register.d/expense-reimbursement-or-passthrough.json) via the
 * Receipt/ExpenseClaimEntry x-openregister-calculations guards and the
 * ExpenseClaimEntry x-openregister-settlement contract.
 *
 * ADR-022: only the real OpenRegister ObjectService API is used
 * (setRegister/setSchema/find/findAll). All resolution is fail-closed for
 * authorisation decisions (CWE-863) and returns null for pure calculations
 * when inputs are missing.
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
 * @spec openspec/specs/expense-reimbursement-or-passthrough/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition + calculation guard for dual-mode expense settlement.
 *
 * The guard intentionally bundles the several small settlement concerns
 * (classification, markup, aggregation, mode-change, notification) that the
 * ExpenseClaimEntry/Receipt fragment references, mirroring the cohesion of the
 * existing ExpenseClaimGuard/AppointmentGuard.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/expense-reimbursement-or-passthrough/spec.md
 */
class SettlementGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
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
	 * Resolve the OpenRegister ObjectService, or null when unavailable.
	 *
	 * @return object|null The ObjectService, or null if OpenRegister is not loaded.
	 */
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->debug(
				'SettlementGuard: OpenRegister ObjectService unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}

	}//end objectService()

	/**
	 * Load all child Receipt records linked to a claim.
	 *
	 * @param string $claimId The claim id.
	 *
	 * @return array<int, array<string, mixed>> Child receipts (possibly empty).
	 */
	private function loadReceipts(string $claimId): array {
		$objectService = $this->objectService();
		if ($objectService === null || $claimId === '') {
			return [];
		}

		try {
			$receipts = $objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: 'Receipt')
				->findAll(['filters' => ['claimId' => $claimId]]);
			if (is_array(value: $receipts) === true) {
				return $receipts;
			}

			return [];
		} catch (\Throwable $e) {
			$this->logger->warning(
				'SettlementGuard: failed to load child receipts',
				['claimId' => $claimId, 'exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end loadReceipts()

	/**
	 * Precondition for the submit transition (REQ-ERP-002/003).
	 *
	 * Enforces, fail-closed:
	 *  1. A single settlement mode across the claim — every classified child
	 *     receipt MUST share the claim's settlementMode (mixed mode is rejected).
	 *  2. Every pass-through receipt MUST carry a linkedCustomerId and a
	 *     passthroughDebitAccountCode (AR account configured).
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array.
	 *
	 * @return bool True when the claim may be submitted.
	 *
	 * @spec openspec/specs/expense-reimbursement-or-passthrough/spec.md
	 */
	public function requireSettlementClassification(array $claim): bool {
		try {
			$claimMode = $this->normaliseMode(value: ($claim['settlementMode'] ?? null));
			$claimId = (string)($claim['id'] ?? '');
			$receipts = $this->loadReceipts(claimId: $claimId);

			foreach ($receipts as $receipt) {
				$mode = $this->normaliseMode(value: ($receipt['settlementMode'] ?? null));

				// Mixed-mode rejection: a classified line must match the claim mode.
				if ($mode !== null && $claimMode !== null && $mode !== $claimMode) {
					$this->logger->info(
						'SettlementGuard: mixed settlement mode — denying submit',
						['claimId' => $claimId, 'claimMode' => $claimMode, 'receiptMode' => $mode]
					);
					return false;
				}

				// Pass-through completeness: customer + AR account required.
				if ($mode === 'pass-through') {
					$customer = trim(string: (string)($receipt['linkedCustomerId'] ?? ''));
					$account = trim(string: (string)($receipt['passthroughDebitAccountCode'] ?? ''));
					if ($customer === '' || $account === '') {
						$this->logger->info(
							'SettlementGuard: pass-through receipt missing customer or AR account — denying submit',
							['claimId' => $claimId, 'receiptId' => ($receipt['id'] ?? 'unknown')]
						);
						return false;
					}
				}
			}//end foreach

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'SettlementGuard: requireSettlementClassification failed — denying submit (fail-closed)',
				['claimId' => ($claim['id'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireSettlementClassification()

	/**
	 * Authorisation for a post-submission settlement-mode change (REQ-ERP-011).
	 *
	 * Fail-closed: only the privileged bookkeeper role may change the mode after
	 * submission; if the claim is already posted, the caller MUST reverse the
	 * existing GLTransaction (T1 REQ-GL-004) before re-materialising.
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array.
	 * @param array<string, mixed> $actor The acting user context (roles[]).
	 *
	 * @return bool True when the mode change is permitted.
	 *
	 * @spec openspec/specs/expense-reimbursement-or-passthrough/spec.md
	 */
	public function canChangeSettlementMode(array $claim, array $actor = []): bool {
		try {
			$state = (string)($claim['state'] ?? '');

			// Before submission the mode is freely editable.
			if ($state === '' || $state === 'draft') {
				return true;
			}

			$roles = (array)($actor['roles'] ?? []);
			if (in_array(needle: 'bookkeeper', haystack: $roles, strict: true) === false) {
				$this->logger->info(
					'SettlementGuard: non-bookkeeper attempted post-submission mode change — denying',
					['claimId' => ($claim['id'] ?? 'unknown'), 'state' => $state]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'SettlementGuard: canChangeSettlementMode failed — denying (fail-closed)',
				['claimId' => ($claim['id'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canChangeSettlementMode()

	/**
	 * Compute the pass-through markup amount for a single Receipt (REQ-ERP-002).
	 *
	 * Looks up the matching PassThroughMarkupRule with priority
	 * (customer+category > customer-only > global default), then applies it:
	 *  - percentage  → amountInBaseCurrency × markupValue
	 *  - fixedAmount → markupValue
	 * Returns null for reimbursable (or unclassified) receipts.
	 *
	 * @param array<string, mixed> $receipt Receipt object array.
	 *
	 * @return float|null The markup amount, or null when not pass-through.
	 *
	 * @spec openspec/specs/expense-reimbursement-or-passthrough/spec.md
	 */
	public function computeMarkupAmount(array $receipt): ?float {
		if ($this->normaliseMode(value: ($receipt['settlementMode'] ?? null)) !== 'pass-through') {
			return null;
		}

		$rule = $this->matchMarkupRule(
			customerId: (string)($receipt['linkedCustomerId'] ?? ''),
			category: (string)($receipt['category'] ?? ''),
			administrationId: (string)($receipt['administrationId'] ?? ''),
			fiscalYear: $this->fiscalYearOf(date: (string)($receipt['receiptDate'] ?? '')),
		);

		if ($rule === null) {
			return null;
		}

		$amount = (float)($receipt['amountInBaseCurrency'] ?? 0.0);
		$value = (float)($rule['markupValue'] ?? 0.0);

		if (($rule['markupType'] ?? '') === 'fixedAmount') {
			return round(num: $value, precision: 2);
		}

		return round(num: ($amount * $value), precision: 2);
	}//end computeMarkupAmount()

	/**
	 * Match the highest-priority PassThroughMarkupRule (REQ-ERP-005).
	 *
	 * Priority order: (customer + category) > (customer only) > (global default),
	 * scoped to the administration and effective fiscal year.
	 *
	 * @param string $customerId The linked customer id.
	 * @param string $category The expense category.
	 * @param string $administrationId The administration id.
	 * @param int $fiscalYear The claim/receipt fiscal year.
	 *
	 * @return array<string, mixed>|null The matched rule, or null when none.
	 *
	 * @spec openspec/specs/expense-reimbursement-or-passthrough/spec.md
	 */
	public function matchMarkupRule(
		string $customerId,
		string $category,
		string $administrationId,
		int $fiscalYear,
	): ?array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$filters = ['administrationId' => $administrationId];
			if ($fiscalYear > 0) {
				$filters['effectiveFromYear'] = ['lte' => $fiscalYear];
			}

			$rules = $objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: 'PassThroughMarkupRule')
				->findAll(['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'SettlementGuard: markup-rule lookup failed',
				['customerId' => $customerId, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

		if (is_array(value: $rules) === false || count($rules) === 0) {
			return null;
		}

		return $this->selectByPriority(rules: $rules, customerId: $customerId, category: $category);
	}//end matchMarkupRule()

	/**
	 * Score a single rule against a target by specificity (REQ-ERP-005).
	 *
	 * Returns the priority tier (lower = more specific) or null when the rule
	 * does not apply to the target:
	 *   0 = customer + category, 1 = customer only, 2 = category-global,
	 *   3 = global default.
	 *
	 * @param array<string, mixed> $rule The candidate rule.
	 * @param string $customerId The target customer id.
	 * @param string $category The target category.
	 *
	 * @return int|null The tier, or null when the rule does not match.
	 */
	private function ruleTier(array $rule, string $customerId, string $category): ?int {
		$ruleCustomer = (string)($rule['targetCustomerId'] ?? '');
		$ruleCategory = (string)($rule['targetCategory'] ?? '');
		$customerMatches = ($ruleCustomer !== '' && $ruleCustomer === $customerId);
		$categoryMatches = ($ruleCategory !== '' && $ruleCategory === $category);

		// A rule scoped to a different customer never applies.
		if ($ruleCustomer !== '' && $customerMatches === false) {
			return null;
		}

		// A rule scoped to a different category never applies.
		if ($ruleCategory !== '' && $categoryMatches === false) {
			return null;
		}

		// Both narrowed to the target (or wildcard) — score by specificity:
		// customer-wildcard adds 2, category-wildcard adds 1 (lower = more specific).
		$tier = 0;
		if ($customerMatches === false) {
			$tier += 2;
		}

		if ($categoryMatches === false) {
			$tier += 1;
		}

		return $tier;
	}//end ruleTier()

	/**
	 * Pick the most specific applicable rule from a candidate list (REQ-ERP-005).
	 *
	 * @param array<int, array<string, mixed>> $rules Candidate rules.
	 * @param string $customerId The target customer id.
	 * @param string $category The target category.
	 *
	 * @return array<string, mixed>|null The best-matching rule, or null.
	 */
	private function selectByPriority(array $rules, string $customerId, string $category): ?array {
		$best = null;
		$bestTier = null;

		foreach ($rules as $rule) {
			$tier = $this->ruleTier(rule: $rule, customerId: $customerId, category: $category);
			if ($tier === null) {
				continue;
			}

			if ($bestTier === null || $tier < $bestTier) {
				$best = $rule;
				$bestTier = $tier;
			}
		}

		return $best;
	}//end selectByPriority()

	/**
	 * Aggregate dual-path totals over a claim's child receipts (REQ-ERP-003).
	 *
	 * Returns:
	 *  - totalReimbursableAmount: sum of reimbursable receipt amounts;
	 *  - totalPassThroughAmount: sum of (cost + markup) for pass-through receipts;
	 *  - passThroughCustomerIds: unique customers across pass-through receipts.
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array.
	 *
	 * @return array{totalReimbursableAmount: float, totalPassThroughAmount: float, passThroughCustomerIds: array<int, string>}
	 *
	 * @spec openspec/specs/expense-reimbursement-or-passthrough/spec.md
	 */
	public function aggregateClaimTotals(array $claim): array {
		$totalReimbursable = 0.0;
		$totalPassThrough = 0.0;
		$customers = [];

		$receipts = $this->loadReceipts(claimId: (string)($claim['id'] ?? ''));
		foreach ($receipts as $receipt) {
			$mode = $this->normaliseMode(value: ($receipt['settlementMode'] ?? null));
			$amount = (float)($receipt['amountInBaseCurrency'] ?? 0.0);

			if ($mode === 'reimbursable') {
				$totalReimbursable += $amount;
			} elseif ($mode === 'pass-through') {
				$markup = $this->computeMarkupAmount(receipt: $receipt);
				if ($markup === null) {
					$markup = (float)($receipt['markupAmountCalculated'] ?? 0.0);
				}

				$totalPassThrough += ($amount + $markup);

				$customer = (string)($receipt['linkedCustomerId'] ?? '');
				if ($customer !== '' && in_array(needle: $customer, haystack: $customers, strict: true) === false) {
					$customers[] = $customer;
				}
			}//end if
		}//end foreach

		return [
			'totalReimbursableAmount' => round(num: $totalReimbursable, precision: 2),
			'totalPassThroughAmount' => round(num: $totalPassThrough, precision: 2),
			'passThroughCustomerIds' => $customers,
		];

	}//end aggregateClaimTotals()

	/**
	 * Build the reimbursement notification payload on post (REQ-ERP-008).
	 *
	 * Pure payload assembly — emission/transport (event bus, audit trail) is the
	 * caller's concern; a missing event bus MUST NOT block posting. Returns null
	 * for non-reimbursable claims.
	 *
	 * @param array<string, mixed> $claim ExpenseClaimEntry object array.
	 * @param string $policyId The ReimbursementPolicy id in force.
	 *
	 * @return array<string, mixed>|null The notification payload, or null.
	 *
	 * @spec openspec/specs/expense-reimbursement-or-passthrough/spec.md
	 */
	public function onReimbursablePosted(array $claim, string $policyId = ''): ?array {
		if ($this->normaliseMode(value: ($claim['settlementMode'] ?? null)) !== 'reimbursable') {
			return null;
		}

		return [
			'event' => 'ExpenseClaimReimbursementNotification',
			'payload' => [
				'claimId' => (string)($claim['claimNumber'] ?? ($claim['id'] ?? '')),
				'employeeId' => (string)($claim['employeeId'] ?? ''),
				'employeeBankAccount' => (string)($claim['employeeBankAccount'] ?? ''),
				'amount' => (float)($claim['totalReimbursableAmount'] ?? ($claim['totalAmount'] ?? 0.0)),
				'currency' => (string)($claim['currency'] ?? 'EUR'),
				'glEntryId' => (string)($claim['glReimbursableTransactionId'] ?? ($claim['glTransactionId'] ?? '')),
				'policyId' => $policyId,
			],
		];

	}//end onReimbursablePosted()

	/**
	 * Normalise a settlement-mode value to one of the two enum tokens or null.
	 *
	 * @param mixed $value The raw settlementMode value.
	 *
	 * @return string|null 'reimbursable', 'pass-through', or null.
	 */
	private function normaliseMode(mixed $value): ?string {
		$mode = trim(string: (string)($value ?? ''));
		if ($mode === 'reimbursable' || $mode === 'pass-through') {
			return $mode;
		}

		return null;
	}//end normaliseMode()

	/**
	 * Derive a fiscal year (integer) from an ISO date string.
	 *
	 * @param string $date ISO date (YYYY-MM-DD) or empty.
	 *
	 * @return int The year, or 0 when unparseable.
	 */
	private function fiscalYearOf(string $date): int {
		if ($date === '' || strlen(string: $date) < 4) {
			return 0;
		}

		$year = (int)substr(string: $date, offset: 0, length: 4);
		if ($year > 0) {
			return $year;
		}

		return 0;
	}//end fiscalYearOf()
}//end class
