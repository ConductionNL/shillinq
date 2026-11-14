<?php

/**
 * Lifecycle guard tags and guards owned by the Lifecycle component
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec exclude Structural composition root, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\Kernel\Contracts\PaymentBlockCheckerInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle guard tags and guards owned by the Lifecycle component.
 *
 * Application::register() hands the registration context to this class, so
 * the Lifecycle component states its own container bindings and AppInfo does not
 * name the classes behind them. The bindings are unchanged.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural composition root, no functional requirement.
 */
final class LifecycleRegistrar {

	/**
	 * Register the Lifecycle component's services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural composition root, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			'OCA\Shillinq\Lifecycle\FiscalYearGuard::requireAllPeriodsClosedForYear',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(FiscalYearGuard::class),
					method: 'requireAllPeriodsClosedForYear',
					denyMessage: 'Every FiscalPeriod within this fiscal year must be closed before the year-end close can begin.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Lifecycle\GLReversalGuard::isReversed',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(GLReversalGuard::class),
					method: 'isReversed',
					denyMessage: 'The linked GLTransaction must already be reversed before this record can be voided.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Lifecycle\WriteOffReasonGuard::requireReason',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(WriteOffReasonGuard::class),
					method: 'requireReason',
					denyMessage: 'writeOffReason must be set before the invoice can be written off.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Lifecycle\APGuard::isInvoiceNumberUnique',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(APGuard::class),
					method: 'isInvoiceNumberUnique',
					denyMessage: 'A vendor invoice with this invoice number already exists for this administration.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Lifecycle\APGuard::requireWriteOffReason',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(APGuard::class),
					method: 'requireWriteOffReason',
					denyMessage: 'writeOffReason must be set before the AP transaction can be written off.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Lifecycle\WBSOExportValidationGuard',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(WBSOExportValidationGuard::class),
					method: 'requireEligibleEntries',
					denyMessage: 'Every included Urenregistratie entry must carry wbsoTagId and activityCodeId before this export can be validated.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			'OCA\Shillinq\Lifecycle\PeriodCloseGuard::trialBalanceVerifies',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(PeriodCloseGuard::class),
					method: 'trialBalanceVerifies',
					denyMessage: 'Total posted debits must equal total posted credits for this period before the close can begin.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// Payment-run-four-eyes REQ-PR4E-001 — segregation of duties on the
		// outgoing SEPA payment-run `approve` transition. This guard
		// implements LifecycleGuardInterface directly (it needs the caller
		// uid, which the shared RegisterRequiresGuardAdapter does not forward),
		// so it is registered under its own FQCN tag — the exact string the
		// PaymentRun schema's `transitions.approve.requires` names. Registering
		// explicitly (rather than relying on container autowiring) matches the
		// shillinq#425 convention and guarantees LifecycleGuardRegistry can
		// resolve it. Fail-closed: an indeterminate preparer blocks the
		// release (ADR-022 audit trail is the sole preparer-of-record).
		// APTransaction matchFull / matchPartial run only inside the settlement
		// of a confirmed bank match; registered under the FQCN tag the
		// accounts-payable fragment names.
		$context->registerService(
			APTransactionSettlementGuard::class,
			static function ($c): APTransactionSettlementGuard {
				return new APTransactionSettlementGuard(logger: $c->get(LoggerInterface::class));
			}
		);

		$context->registerService(
			FourEyesPaymentRunGuard::class,
			static function ($c): FourEyesPaymentRunGuard {
				return new FourEyesPaymentRunGuard(
					container: $c->get(ContainerInterface::class),
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// Payment-control-guards REQ-PCG-001 — duplicate-payment control on the
		// outgoing SEPA payment-run `export` transition. Rejects exporting a
		// batch whose line settles an AP invoice that is already paid or already
		// queued in another open/executed batch. Implements
		// LifecycleGuardInterface directly and is registered under its own FQCN
		// tag — the exact string the PaymentRun schema's
		// `transitions.export.requires` names — so LifecycleGuardRegistry can
		// resolve it (the shillinq#425 convention). Fail-closed.
		$context->registerService(
			PaymentRunDuplicateGuard::class,
			static function ($c): PaymentRunDuplicateGuard {
				return new PaymentRunDuplicateGuard(
					container: $c->get(ContainerInterface::class),
					appConfig: $c->get(IAppConfig::class),
					logger: $c->get(LoggerInterface::class),
					blockChecker: $c->get(PaymentBlockCheckerInterface::class),
				);
			}
		);

		// Budget-core-schema REQ-BCS-006 — the AnnualBudget.activate
		// transition's `requires` tag
		// (OCA\Shillinq\Lifecycle\AnnualBudgetDefaultGuard::isUniqueDefault)
		// is a `Class::method`-shaped string, which per shillinq#425/#433
		// never autowires through Nextcloud's container without an explicit
		// alias. Registered under the exact literal tag the register.d
		// fragment names, mapped through the shared
		// RegisterRequiresGuardAdapter, matching the convention every guard
		// registered since #425 follows.
		$context->registerService(
			'OCA\Shillinq\Lifecycle\AnnualBudgetDefaultGuard::isUniqueDefault',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(AnnualBudgetDefaultGuard::class),
					method: 'isUniqueDefault',
					denyMessage: 'Another AnnualBudget already claims isDefault for this administration and fiscal year.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
	}//end register()
}//end class
