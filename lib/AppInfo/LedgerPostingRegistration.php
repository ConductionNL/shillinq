<?php

/**
 * Ledger posting path registrations (#516, #1103)
 *
 * Registers the `requires` guard of every transition that posts to the
 * ledger, so OpenRegister's LifecycleGuardRegistry can resolve it. Each tag
 * was declared in the register and never registered, so the transition threw
 * `Lifecycle guard "..." is not registered` before it could post: a journal
 * entry, a GL transaction, a purchase invoice, a sales invoice and an AP
 * transaction could not be booked.
 *
 * A tag containing `::` cannot autowire, so each is registered by its exact
 * literal string and mapped through RegisterRequiresGuardAdapter onto the
 * method it names. Every method named here takes the object array the
 * adapter passes (a method that takes an id string would throw a TypeError
 * in the adapter, which fails closed and denies every transition).
 *
 * The posting actions themselves are declared by FQCN
 * (`OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction`,
 * `...\EvaluateAllocationRulesAction`), which OpenRegister's server-container
 * lookup reaches without a registration here.
 *
 * Lives in its own class for the same reason as
 * OrderFulfilmentGateRegistration: Application sits at PHPMD's class-length
 * threshold.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\Shillinq\Lifecycle\BalanceGuard;
use OCA\Shillinq\Lifecycle\JournalEntryGuard;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCA\Shillinq\Lifecycle\RuleComplianceGuard;
use OCA\Shillinq\Lifecycle\ThreeWayMatchGuard;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the guard tags of the posting transitions.
 *
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 */
final class LedgerPostingRegistration {

	/**
	 * Literal `requires` tag => [guard class, method, deny message].
	 *
	 * @var array<string, array{0: class-string, 1: string, 2: string}>
	 */
	public const GUARDS = [
		// GLTransaction.post (add-shillinq-rule-compliance-guard.json).
		'OCA\Shillinq\Lifecycle\RuleComplianceGuard::validateTransaction' => [
			RuleComplianceGuard::class,
			'validateTransaction',
			'The transaction cannot be posted: it is not balanced, has fewer than two complete lines, or breaks a mandatory ledger rule.',
		],
		// ARInvoice.issue (add-shillinq-rule-compliance-guard.json).
		'OCA\Shillinq\Lifecycle\RuleComplianceGuard::validateInvoice' => [
			RuleComplianceGuard::class,
			'validateInvoice',
			'The invoice cannot be issued: it breaks a mandatory invoice rule, such as a missing number or a total that is not net plus VAT.',
		],
		// JournalEntry.post and JournalEntry.postDirect (add-shillinq-bookkeeping-foundation.json).
		'OCA\Shillinq\Lifecycle\JournalEntryGuard::canPost' => [
			JournalEntryGuard::class,
			'canPost',
			'The journal entry cannot be posted: it needs at least two lines and its debits must equal its credits.',
		],
		// APInvoice.post (shillinq_register.json).
		'OCA\Shillinq\Lifecycle\ThreeWayMatchGuard::matches' => [
			ThreeWayMatchGuard::class,
			'matches',
			'The purchase invoice cannot be posted: it does not match its purchase order and goods receipt.',
		],
		// APTransaction.issue (bookkeeping-accounts-payable-core.json).
		'OCA\Shillinq\Lifecycle\BalanceGuard::isInvoiceBalanced' => [
			BalanceGuard::class,
			'isInvoiceBalanced',
			'The invoice cannot be issued: its lines plus VAT do not add up to the invoice total.',
		],
	];

	/**
	 * Register one adapter per posting guard tag.
	 *
	 * @param IRegistrationContext $context The app registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
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
