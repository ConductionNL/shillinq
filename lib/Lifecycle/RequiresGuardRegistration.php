<?php

/**
 * Registers every literal `requires` tag of the register whose guard method
 * takes the object (or its id and the object), so OpenRegister can resolve it.
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
 * @spec openspec/specs/missing-lifecycle-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister resolves a transition's `requires` tag as a DI service and
 * refuses the transition when no service answers to it ("Lifecycle guard ...
 * is not registered"). A `Class::method` tag is no class name, so each one
 * needs a registration; the transitions whose tag had none were refused on
 * every instance. This registers each such tag as a
 * RegisterRequiresGuardAdapter over its guard class and method.
 *
 * TAGS is every `Class::method` tag in lib/Settings whose method takes the
 * object, or its id and the object, and that no other registration in lib/
 * (LedgerPostingRegistration, ExpenseClaimRegistration, GuardRegistrar, ...)
 * binds with a message of its own.
 * RequiresGuardRegistrationTest derives the same list from the register, so
 * a new tag that is not listed here fails the suite.
 *
 * @spec openspec/specs/missing-lifecycle-guards/spec.md
 */
final class RequiresGuardRegistration {

	/**
	 * The refusal a guard without a message of its own gives.
	 */
	public const DENY_MESSAGE = 'This step is not allowed: a check it requires did not pass.';

	/**
	 * The `Class::method` tags registered here.
	 *
	 * @var list<string>
	 */
	public const TAGS = [
		'OCA\Shillinq\Guard\AccountBalanceGuard::requireZeroBalance',
		'OCA\Shillinq\Guard\BankReconciliationGuard::requireResolvedMatches',
		'OCA\Shillinq\Guard\BbvComplianceGuard::requireMeerjarenramingSluitend',
		'OCA\Shillinq\Guard\EmuSubmissionGuard::requireApproval',
		'OCA\Shillinq\Guard\Iv3SubmissionGuard::requireApproval',
		'OCA\Shillinq\Guard\Iv3XmlValidationGuard::requireValidXml',
		'OCA\Shillinq\Guard\OssThresholdGuard::canEnableVoluntary',
		'OCA\Shillinq\Guard\RetentionGuard::allowsDisposal',
		'OCA\Shillinq\Guard\RetentionGuard::requiresReview',
		'OCA\Shillinq\Guard\StatementVerifyGuard::requireResolvedAndSignedOff',
		'OCA\Shillinq\Guard\StatementVerifyGuard::verifyStatementBalance',
		'OCA\Shillinq\Lifecycle\AnnualReportGuard::canOpmaken',
		'OCA\Shillinq\Lifecycle\AnnualReportGuard::canVaststellen',
		'OCA\Shillinq\Lifecycle\AuditorStatementGuard::canApprove',
		'OCA\Shillinq\Lifecycle\BalanceGuard::isBalanced',
		'OCA\Shillinq\Lifecycle\BcfClaimGuard::canSubmit',
		'OCA\Shillinq\Lifecycle\BegrotingswijzigingGuard::canVaststellen',
		'OCA\Shillinq\Lifecycle\BookingCancellationGuard::canCancel',
		'OCA\Shillinq\Lifecycle\CbcrPillar2Guard::canApproveComputation',
		'OCA\Shillinq\Lifecycle\CbcrPillar2Guard::canApproveGir',
		'OCA\Shillinq\Lifecycle\CbcrPillar2Guard::canReconcileCbcrReturn',
		'OCA\Shillinq\Lifecycle\CbcrPillar2Guard::canReconcileSummary',
		'OCA\Shillinq\Lifecycle\CbcrPillar2Guard::canSubmitCbcrReturn',
		'OCA\Shillinq\Lifecycle\CbcrPillar2Guard::canSubmitQdmtt',
		'OCA\Shillinq\Lifecycle\CcmFindingGuard::canApproveReport',
		'OCA\Shillinq\Lifecycle\CcmFindingGuard::canConfirm',
		'OCA\Shillinq\Lifecycle\CcmFindingGuard::canDismiss',
		'OCA\Shillinq\Lifecycle\CommitmentGuard::canActiveren',
		'OCA\Shillinq\Lifecycle\ComplianceValidator::isCompliant',
		'OCA\Shillinq\Lifecycle\ConsolidationGuard::canActivateEntity',
		'OCA\Shillinq\Lifecycle\ConsolidationGuard::canActivateGroup',
		'OCA\Shillinq\Lifecycle\ConsolidationGuard::canApproveElimination',
		'OCA\Shillinq\Lifecycle\ConsolidationGuard::canClosePeriod',
		'OCA\Shillinq\Lifecycle\ConsolidationGuard::canFinalizeBalance',
		'OCA\Shillinq\Lifecycle\ConsolidationGuard::canFinalizeIncomeStatement',
		'OCA\Shillinq\Lifecycle\ConsolidationGuard::canRejectElimination',
		'OCA\Shillinq\Lifecycle\ConsolidationGuard::canStartElimination',
		'OCA\Shillinq\Lifecycle\ConsolidationGuard::canSubmitForReview',
		'OCA\Shillinq\Lifecycle\ContractLifecycleGuard::canActivate',
		'OCA\Shillinq\Lifecycle\ContractLifecycleGuard::requireTerminationReason',
		'OCA\Shillinq\Lifecycle\CsrdEsrsGuard::canApproveDataPoint',
		'OCA\Shillinq\Lifecycle\CsrdEsrsGuard::canApproveMateriality',
		'OCA\Shillinq\Lifecycle\CsrdEsrsGuard::canIssueOpinion',
		'OCA\Shillinq\Lifecycle\CsrdEsrsGuard::canRestateDataPoint',
		'OCA\Shillinq\Lifecycle\CsrdEsrsGuard::canSubmitMateriality',
		'OCA\Shillinq\Lifecycle\DualGaapGuard::canActivateElection',
		'OCA\Shillinq\Lifecycle\DualGaapGuard::canReconcileTransaction',
		'OCA\Shillinq\Lifecycle\DunningRunExecuteGuard::canExecute',
		'OCA\Shillinq\Lifecycle\EliminationBalanceGuard::isBalanced',
		'OCA\Shillinq\Lifecycle\EliminationGuard::canChangeEliminationStatus',
		'OCA\Shillinq\Lifecycle\EuExpenditureGuard::canDeclare',
		'OCA\Shillinq\Lifecycle\EuExpenditureGuard::canSubmit',
		'OCA\Shillinq\Lifecycle\FidoTreasuryGuard::canRecordDerivaat',
		'OCA\Shillinq\Lifecycle\FidoTreasuryGuard::canRecordLening',
		'OCA\Shillinq\Lifecycle\FidoTreasuryGuard::canSubmitRapportage',
		'OCA\Shillinq\Lifecycle\IntercompanyToleranceGuard::isWithinTolerance',
		'OCA\Shillinq\Lifecycle\IntercompanyTransactionGuard::canActivateLoan',
		'OCA\Shillinq\Lifecycle\IntercompanyTransactionGuard::canPost',
		'OCA\Shillinq\Lifecycle\InventoryValuationMethodGuard::checkZeroStock',
		'OCA\Shillinq\Lifecycle\InvoiceFromBookingGuard::canComplete',
		'OCA\Shillinq\Lifecycle\IrregularityReportGuard::canEscalate',
		'OCA\Shillinq\Lifecycle\JournalEntryGuard::canVoid',
		'OCA\Shillinq\Lifecycle\KlantLadderOverrideApprovalGuard::isApprovedForElevatedStages',
		'OCA\Shillinq\Lifecycle\MandateGuard::canActivate',
		'OCA\Shillinq\Lifecycle\MandateGuard::canCancel',
		'OCA\Shillinq\Lifecycle\MandateGuard::canExpire',
		'OCA\Shillinq\Lifecycle\ObjectionPeriodGuard::canFileAppeal',
		'OCA\Shillinq\Lifecycle\ObjectionPeriodGuard::canFileObjection',
		'OCA\Shillinq\Lifecycle\PayrollGuard::canCalculate',
		'OCA\Shillinq\Lifecycle\PayrollGuard::canIssue',
		'OCA\Shillinq\Lifecycle\PensionIas19Guard::canActivatePlan',
		'OCA\Shillinq\Lifecycle\PensionIas19Guard::canApproveValuation',
		'OCA\Shillinq\Lifecycle\PensionIas19Guard::canLockValuation',
		'OCA\Shillinq\Lifecycle\PreNotificationGuard::canIncludeInBatch',
		'OCA\Shillinq\Lifecycle\ProgrammabegrotingGuard::canBehandelen',
		'OCA\Shillinq\Lifecycle\ProgrammabegrotingGuard::canVaststellen',
		'OCA\Shillinq\Lifecycle\ProvisionGuard::canActivateProvision',
		'OCA\Shillinq\Lifecycle\ProvisionGuard::canCloseMovement',
		'OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard::canAcceptQuote',
		'OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard::canCancelDelivery',
		'OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard::canConfirmDelivery',
		'OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard::canConfirmOrder',
		'OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard::canIssueCreditNote',
		'OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard::canIssueInvoice',
		'OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard::canReleaseCreditHold',
		'OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard::canSendQuote',
		'OCA\Shillinq\Lifecycle\RechtmatigheidGuard::canFinaliseToets',
		'OCA\Shillinq\Lifecycle\RechtmatigheidGuard::canResolveBevinding',
		'OCA\Shillinq\Lifecycle\RechtmatigheidGuard::canVaststellenParagraaf',
		'OCA\Shillinq\Lifecycle\RepostingEligibilityGuard::canRepost',
		'OCA\Shillinq\Lifecycle\RequisitionConversionGuard::canConvert',
		'OCA\Shillinq\Lifecycle\SegregatedLedgerGuard::canClose',
		'OCA\Shillinq\Lifecycle\StatementParser::allLinesResolved',
		'OCA\Shillinq\Lifecycle\StockMoveImmutabilityGuard::canCancel',
		'OCA\Shillinq\Lifecycle\StockMoveReasonGuard::requireReasonOnPost',
		'OCA\Shillinq\Lifecycle\SubsidieVerantwoordingGuard::canApprove',
		'OCA\Shillinq\Lifecycle\SupportingDocumentGuard::canCertify',
		'OCA\Shillinq\Lifecycle\TenderNedProcurementGuard::canAfronden',
		'OCA\Shillinq\Lifecycle\TenderNedProcurementGuard::canGunnen',
		'OCA\Shillinq\Lifecycle\VarianceGate::requireReasonsOnPost',
		'OCA\Shillinq\Lifecycle\VarianceGate::requireValidScope',
		'OCA\Shillinq\Lifecycle\VoluntaryDeclineGuard::requireVoluntary',
		'OCA\Shillinq\Lifecycle\VpbAangifteGuard::canAanslagOntvangen',
		'OCA\Shillinq\Lifecycle\VpbAangifteGuard::canIndienen',
		'OCA\Shillinq\Lifecycle\WbsoMededelingGuard::canSubmit',
		'OCA\Shillinq\Service\BadoControleprotocolService::canAdopt',
		'OCA\Shillinq\Service\BadoControleprotocolService::canSignVerklaring',
		'OCA\Shillinq\Service\BadoControleprotocolService::canSubmitForReview',
		'OCA\Shillinq\Service\BadoControleprotocolService::hasControllerResponse',
		'OCA\Shillinq\Service\BadoControleprotocolService::isFourEyeComplete',
		'OCA\Shillinq\Service\ConsolidationGuard::requireAllMembersFinalised',
		'OCA\Shillinq\Service\ConsolidationGuard::requireFiscalPeriodClosed',
		'OCA\Shillinq\Service\ConsolidationGuard::requirePublicationApproval',
		'OCA\Shillinq\Service\SisaReportingService::canBeFinalized',
	];

	/**
	 * Register each tag as a guard adapter over its class and method.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/missing-lifecycle-guards/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::TAGS as $tag) {
			[$guardClass, $method] = explode('::', $tag, 2);
			$context->registerService(
				$tag,
				static function ($c) use ($guardClass, $method): RegisterRequiresGuardAdapter {
					return new RegisterRequiresGuardAdapter(
						guard: $c->get($guardClass),
						method: $method,
						denyMessage: self::DENY_MESSAGE,
						logger: $c->get(LoggerInterface::class),
					);
				}
			);
		}
	}//end register()
}//end class
