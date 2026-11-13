<?php

/**
 * Bindings of the Kernel contracts to their Lifecycle implementations
 *
 * @category  Lifecycle
 * @package   OCA\Shillinq\Lifecycle
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\Kernel\Contracts\AansluitingResolutionGuardInterface;
use OCA\Shillinq\Kernel\Contracts\BudgetBlockerInterface;
use OCA\Shillinq\Kernel\Contracts\ImportBatchGuardInterface;
use OCA\Shillinq\Kernel\Contracts\LotSellabilityGuardInterface;
use OCA\Shillinq\Kernel\Contracts\MandateEnforcerInterface;
use OCA\Shillinq\Kernel\Contracts\MandateGuardInterface;
use OCA\Shillinq\Kernel\Contracts\MaterialiseGlTransactionActionInterface;
use OCA\Shillinq\Kernel\Contracts\OrderFulfilmentGuardInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRunReconcileGuardInterface;
use OCA\Shillinq\Kernel\Contracts\PurchaseOrderApprovalGuardInterface;
use OCA\Shillinq\Kernel\Contracts\RequisitionConversionGuardInterface;
use OCA\Shillinq\Kernel\Contracts\StatementParserInterface;
use OCA\Shillinq\Kernel\Contracts\SupplierInvoiceExceptionApprovalGuardInterface;
use OCA\Shillinq\Kernel\Contracts\VarianceGateInterface;
use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Shillinq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 */
final class LifecycleContractRegistrar {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural contract wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(AansluitingResolutionGuardInterface::class, AansluitingResolutionGuard::class);
		$context->registerServiceAlias(BudgetBlockerInterface::class, BudgetBlocker::class);
		$context->registerServiceAlias(ImportBatchGuardInterface::class, ImportBatchGuard::class);
		$context->registerServiceAlias(LotSellabilityGuardInterface::class, LotSellabilityGuard::class);
		$context->registerServiceAlias(MandateEnforcerInterface::class, MandateEnforcer::class);
		$context->registerServiceAlias(MandateGuardInterface::class, MandateGuard::class);
		$context->registerServiceAlias(MaterialiseGlTransactionActionInterface::class, MaterialiseGlTransactionAction::class);
		$context->registerServiceAlias(OrderFulfilmentGuardInterface::class, OrderFulfilmentGuard::class);
		$context->registerServiceAlias(PaymentRunReconcileGuardInterface::class, PaymentRunReconcileGuard::class);
		$context->registerServiceAlias(PurchaseOrderApprovalGuardInterface::class, PurchaseOrderApprovalGuard::class);
		$context->registerServiceAlias(RequisitionConversionGuardInterface::class, RequisitionConversionGuard::class);
		$context->registerServiceAlias(StatementParserInterface::class, StatementParser::class);
		$context->registerServiceAlias(SupplierInvoiceExceptionApprovalGuardInterface::class, SupplierInvoiceExceptionApprovalGuard::class);
		$context->registerServiceAlias(VarianceGateInterface::class, VarianceGate::class);
	}//end register()
}//end class
