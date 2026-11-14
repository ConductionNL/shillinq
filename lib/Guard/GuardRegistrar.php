<?php

/**
 * Lifecycle guard tags owned by the Guard component
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude Structural composition root, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle guard tags owned by the Guard component.
 *
 * Application::register() hands the registration context to this class, so
 * the Guard component states its own container bindings and AppInfo does not
 * name the classes behind them. The bindings are unchanged.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural composition root, no functional requirement.
 */
final class GuardRegistrar {

	/**
	 * Register the Guard component's services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural composition root, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			'OCA\Shillinq\Guard\Iv3XmlValidationGuard::requireValidXml',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(Iv3XmlValidationGuard::class),
					method: 'requireValidXml',
					denyMessage: 'The export must have a generated XML attachment and at least one aggregated bucket before it can be validated.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Guard\Iv3SubmissionGuard::requireApproval',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(Iv3SubmissionGuard::class),
					method: 'requireApproval',
					denyMessage: 'This export has already been submitted, or is missing its generated XML/buckets.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Guard\KorLockoutGuard::requireLockoutExpired',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(KorLockoutGuard::class),
					method: 'requireLockoutExpired',
					denyMessage: 'The 3-year KOR re-entry lock-out (Wet OB 1968 art. 25 lid 3) has not yet elapsed.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Guard\ProjectActivationGuard::requireStartDate',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(ProjectActivationGuard::class),
					method: 'requireStartDate',
					denyMessage: 'startDate must be set before the project can be activated.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Guard\ProjectTransitionGuard::requireReason',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(ProjectTransitionGuard::class),
					method: 'requireReason',
					denyMessage: 'closureJustification must be set before the project can be put on hold.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Guard\ProjectCloseGuard::requireWipJustificationOrZero',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(ProjectCloseGuard::class),
					method: 'requireWipJustificationOrZero',
					denyMessage: 'This project has an open WIP balance; record closureJustification before closing.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Guard\VatSubmissionGuard::requireApproval',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(VatSubmissionGuard::class),
					method: 'requireApproval',
					denyMessage: 'This return exceeds the configured approval threshold and cannot be submitted yet.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Guard\BcfSubmissionGuard::requireApproval',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(BcfSubmissionGuard::class),
					method: 'requireApproval',
					denyMessage: 'This claim exceeds the configured approval threshold and cannot be submitted yet.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Guard\SubsidieRepaymentGuard::requireZeroRepaymentBalance',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(SubsidieRepaymentGuard::class),
					method: 'requireZeroRepaymentBalance',
					denyMessage: 'The outstanding repayment balance must be zero before this dossier can be closed.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Guard\RateScheduleOverlapGuard::requireNonOverlappingWindow',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(RateScheduleOverlapGuard::class),
					method: 'requireNonOverlappingWindow',
					denyMessage: 'Another active rate schedule for this tier/entity already covers an overlapping effective-date window.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// Revive-gl-tax-capabilities design D2 — the OSS money transitions
		// (`OssReturn.pay`, `OssPayment.reconcile`) have named
		// `OCA\Shillinq\Service\OssPaymentReconciliation::canMarkPaid` as
		// their `requires` guard since the bookkeeping-btw-oss-eu register
		// shipped, but the tag was never registered: OssPaymentReconciliation
		// is a plain pure-logic class and its canMarkPaid() takes TWO arrays,
		// so LifecycleGuardRegistry::resolve() threw and BOTH transitions
		// hard-failed with HTTP 500 (the shillinq#425/#433 defect class).
		// OssPaymentGuard is the single-array adapter that string always
		// implied — it resolves the counterpart record (payment -> its
		// OssReturn, return -> its OssPayment) and delegates to the
		// unmodified kernel. Registered under the EXACT literal tag the
		// register.d names. Fail-closed via RegisterRequiresGuardAdapter.
		$context->registerService(
			'OCA\Shillinq\Service\OssPaymentReconciliation::canMarkPaid',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(OssPaymentGuard::class),
					method: 'canMarkPaid',
					denyMessage: 'A matching bank transaction settling this OSS return in full must be linked before it can be marked paid.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// Revive-gl-tax-capabilities REQ-GLTAX-002 — the `eliminate`
		// transition on IntercompanyJournalEntry may only fire once the pair
		// reconciles (zero variance between the two booked sides). Fail-closed
		// when the counter-side entry cannot be resolved.
		$context->registerService(
			'OCA\Shillinq\Guard\IntercompanyEliminationGuard::requireReconciledPair',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(IntercompanyEliminationGuard::class),
					method: 'requireReconciledPair',
					denyMessage: 'The two sides of this intercompany pair do not reconcile; resolve the variance before booking the elimination.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// REQ-BSC-004 — registered (unlike the still-unregistered, pre-existing
		// CashflowRecurringGuard/ProgrammaLinkGuard `preconditions.save` tags,
		// shillinq#433's own fleet-wide gap, out of this change's scope) so
		// this guard actually enforces the one-unresolvable-conflict rule at
		// runtime.
		$context->registerService(
			'OCA\Shillinq\Guard\BudgetScenarioModifierGuard::validateOnSave',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(BudgetScenarioModifierGuard::class),
					method: 'validateOnSave',
					denyMessage: 'This modifier conflicts with an existing modifier in the same scenario, or is missing a required field for its type.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Guard\XbrlInstanceValidationGuard::requireValidInstance',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(XbrlInstanceValidationGuard::class),
					method: 'requireValidInstance',
					denyMessage: 'The XBRL instance could not be checked against its entry point; it cannot be validated now.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
	}//end register()
}//end class
