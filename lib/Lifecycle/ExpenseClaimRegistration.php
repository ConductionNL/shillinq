<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Expense Claim Registration
 *
 * Registers the `requires` guards the ExpenseClaimEntry transitions declare,
 * so OpenRegister's LifecycleGuardRegistry can resolve them: the settlement
 * mode and markup-threshold checks of ExpenseReimbursementGuard
 * (expense-reimbursement-or-passthrough.json) and the cost centre and open
 * period checks of ExpenseClaimGuard (shillinq_register.json). Each tag was
 * declared and never registered, so submit, approve and post threw
 * `Lifecycle guard "..." is not registered` before a claim could move.
 *
 * Same shape as LedgerPostingRegistration: a tag containing `::` cannot
 * autowire, so each is registered by its literal string and mapped through
 * RegisterRequiresGuardAdapter onto the method it names.
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
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the guard tags of the expense claim transitions.
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.4
 */
final class ExpenseClaimRegistration {
	/**
	 * Literal `requires` tag => [guard class, method, deny message].
	 *
	 * @var array<string, array{0: class-string, 1: string, 2: string}>
	 */
	public const GUARDS = [
		// ExpenseClaimEntry.submit (expense-reimbursement-or-passthrough.json).
		'OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard::requireSettlementModeConsistency' => [
			ExpenseReimbursementGuard::class,
			'requireSettlementModeConsistency',
			'The claim cannot be submitted: it needs a settlement mode, every line must have the same one, and a pass-through line needs a customer.',
		],
		// ExpenseClaimEntry.approve (expense-reimbursement-or-passthrough.json).
		'OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard::requireMarkupApprovalIfThreshold' => [
			ExpenseReimbursementGuard::class,
			'requireMarkupApprovalIfThreshold',
			'The claim cannot be approved here: its pass-through markup reaches the policy threshold and needs the extra approver.',
		],
		// ExpenseClaimEntry.markInvoiced (expense-reimbursement-or-passthrough.json).
		'OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard::requirePassThroughMode' => [
			ExpenseReimbursementGuard::class,
			'requirePassThroughMode',
			'Only a pass-through claim can be marked as invoiced.',
		],
		// ExpenseClaimEntry.changeSettlementMode (expense-reimbursement-or-passthrough.json).
		'OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard::requireGlReversalForModeChange' => [
			ExpenseReimbursementGuard::class,
			'requireGlReversalForModeChange',
			'The settlement mode cannot change: reverse the posted ledger transaction first.',
		],
		// ExpenseClaimEntry.submit (shillinq_register.json; replaced by the fragment's guard, registered for the base declaration).
		'OCA\Shillinq\Lifecycle\ExpenseClaimGuard::requireCostCentresAndItems' => [
			ExpenseClaimGuard::class,
			'requireCostCentresAndItems',
			'The claim cannot be submitted: it needs at least one line and every line needs a cost centre.',
		],
		// ExpenseClaimEntry.post (shillinq_register.json).
		'OCA\Shillinq\Lifecycle\ExpenseClaimGuard::requireOpenPeriodAndCostCentres' => [
			ExpenseClaimGuard::class,
			'requireOpenPeriodAndCostCentres',
			'The claim cannot be posted: its fiscal year is not open or a line has no cost centre.',
		],
	];

	/**
	 * Register one adapter per expense claim guard tag.
	 *
	 * @param IRegistrationContext $context The app registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.4
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::GUARDS as $tag => [$guardClass, $method, $denyMessage]) {
			$context->registerService(
				$tag,
				static function ($c) use ($guardClass, $method, $denyMessage): RegisterRequiresGuardAdapter {
					return new RegisterRequiresGuardAdapter(
						guard: $c->get($guardClass),
						method: $method,
						denyMessage: $denyMessage,
						logger: $c->get(LoggerInterface::class),
					);
				}
			);
		}
	}//end register()
}//end class
