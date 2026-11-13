<?php

/**
 * Bindings of the Kernel contracts to their Service implementations
 *
 * @category  Service
 * @package   OCA\Shillinq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\Contracts\AansluitingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AccountantDashboardServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AccountantsdossierExportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AdministrationArchivalServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AdministrationManagementAccessInterface;
use OCA\Shillinq\Kernel\Contracts\AdministrationMembershipServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AppointmentConfirmationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ArInvoiceIcpPdfRendererInterface;
use OCA\Shillinq\Kernel\Contracts\AssetRecordsInterface;
use OCA\Shillinq\Kernel\Contracts\AuditExportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BadoControleprotocolServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BankfeedIntakeServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BankRulePreviewServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BankRuleSuggestionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BbvProgrammeBudgetServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BbvSeedServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BcfClaimServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BcfCompensationCalculatorInterface;
use OCA\Shillinq\Kernel\Contracts\BegrotingswijzigingStackerInterface;
use OCA\Shillinq\Kernel\Contracts\BIKStaffelCalculatorInterface;
use OCA\Shillinq\Kernel\Contracts\BillableLinesServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BillablePeriodInvoiceServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BookingNotificationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BudgetChartSeriesServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BudgetGridCalculatorInterface;
use OCA\Shillinq\Kernel\Contracts\BudgetGridReaderInterface;
use OCA\Shillinq\Kernel\Contracts\BudgetImpactEmitterInterface;
use OCA\Shillinq\Kernel\Contracts\BudgetScenarioDefaultPromoterInterface;
use OCA\Shillinq\Kernel\Contracts\BudgetScenarioEvaluatorInterface;
use OCA\Shillinq\Kernel\Contracts\BudgetScenarioReaderInterface;
use OCA\Shillinq\Kernel\Contracts\CalendarRowsInterface;
use OCA\Shillinq\Kernel\Contracts\CashflowExportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\CBSExportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ChartOfAccountsCandidateServiceInterface;
use OCA\Shillinq\Kernel\Contracts\CogsPosterServiceInterface;
use OCA\Shillinq\Kernel\Contracts\CommitmentCarryOverServiceInterface;
use OCA\Shillinq\Kernel\Contracts\CommitmentInvoicingInterface;
use OCA\Shillinq\Kernel\Contracts\CommitmentLedgerInterface;
use OCA\Shillinq\Kernel\Contracts\CommitmentMaterialisationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ComplianceDeadlineCalendarServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ComplianceExportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ComplianceServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ConfirmationTokenServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ConflictDetectionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ConnectionReportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ContractCostRollupServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ContributionDeclineServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ContributionRaiseServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DBAVbarMonitorServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DebtorCreditServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DemoDataServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DepositReconciliationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DepreciationRunInterface;
use OCA\Shillinq\Kernel\Contracts\DepreciationScheduleServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DocudeskExtractionClientInterface;
use OCA\Shillinq\Kernel\Contracts\DoorsnijdingsVerbodValidatorInterface;
use OCA\Shillinq\Kernel\Contracts\DunningRunServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DunningStageRendererInterface;
use OCA\Shillinq\Kernel\Contracts\DunningTickRunnerInterface;
use OCA\Shillinq\Kernel\Contracts\EInvoiceServiceInterface;
use OCA\Shillinq\Kernel\Contracts\EmuReportingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ExtractionPrefillServiceInterface;
use OCA\Shillinq\Kernel\Contracts\FixedAssetDepreciationInterface;
use OCA\Shillinq\Kernel\Contracts\GlAccountSuggestionClientInterface;
use OCA\Shillinq\Kernel\Contracts\IncassoDossierComposerInterface;
use OCA\Shillinq\Kernel\Contracts\InvoiceSettlementServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ManualMatchServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectRequestBankMatcherInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectRequestBankSettlementInterface;
use OCA\Shillinq\Kernel\Contracts\ReinvestmentReservesInterface;
use OCA\Shillinq\Kernel\Contracts\StatementIntakeServiceInterface;
use OCA\Shillinq\Kernel\Contracts\TransactionalGuardInterface;
use OCA\Shillinq\Kernel\Contracts\VoluntaryContributionPolicyInterface;
use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Asset\DepreciationRun;
use OCA\Shillinq\Service\Asset\DepreciationScheduleService;
use OCA\Shillinq\Service\Asset\FixedAssetDepreciation;
use OCA\Shillinq\Service\Asset\ReinvestmentReserves;
use OCA\Shillinq\Service\Bank\BankfeedIntakeService;
use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCA\Shillinq\Service\Bank\ObjectRequestBankMatcher;
use OCA\Shillinq\Service\Bank\ObjectRequestBankSettlement;
use OCA\Shillinq\Service\Bank\StatementIntakeService;
use OCA\Shillinq\Service\Booking\CalendarRows;
use OCA\Shillinq\Service\Booking\ConfirmationTokenService;
use OCA\Shillinq\Service\Booking\TransactionalGuard;
use OCA\Shillinq\Service\Commitment\CommitmentCarryOverService;
use OCA\Shillinq\Service\Commitment\CommitmentInvoicing;
use OCA\Shillinq\Service\Commitment\CommitmentLedger;
use OCA\Shillinq\Service\Commitment\CommitmentMaterialisationService;
use OCA\Shillinq\Service\Dunning\DunningStageRenderer;
use OCA\Shillinq\Service\Dunning\DunningTickRunner;
use OCA\Shillinq\Service\Dunning\IncassoDossierComposer;
use OCA\Shillinq\Service\Dunning\VoluntaryContributionPolicy;
use OCA\Shillinq\Service\EInvoice\EInvoiceService;
use OCA\Shillinq\Service\Extraction\ChartOfAccountsCandidateService;
use OCA\Shillinq\Service\Extraction\DocudeskExtractionClient;
use OCA\Shillinq\Service\Extraction\ExtractionPrefillService;
use OCA\Shillinq\Service\Extraction\GlAccountSuggestionClient;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Shillinq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
final class ContractRegistrar {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(AansluitingServiceInterface::class, AansluitingService::class);
		$context->registerServiceAlias(AccountantDashboardServiceInterface::class, AccountantDashboardService::class);
		$context->registerServiceAlias(AccountantsdossierExportServiceInterface::class, AccountantsdossierExportService::class);
		$context->registerServiceAlias(AdministrationArchivalServiceInterface::class, AdministrationArchivalService::class);
		$context->registerServiceAlias(AdministrationContextServiceInterface::class, AdministrationContextService::class);
		$context->registerServiceAlias(AdministrationManagementAccessInterface::class, AdministrationManagementAccess::class);
		$context->registerServiceAlias(AdministrationMembershipServiceInterface::class, AdministrationMembershipService::class);
		$context->registerServiceAlias(AppointmentConfirmationServiceInterface::class, AppointmentConfirmationService::class);
		$context->registerServiceAlias(ArInvoiceIcpPdfRendererInterface::class, ArInvoiceIcpPdfRenderer::class);
		$context->registerServiceAlias(AssetRecordsInterface::class, AssetRecords::class);
		$context->registerServiceAlias(AuditExportServiceInterface::class, AuditExportService::class);
		$context->registerServiceAlias(BIKStaffelCalculatorInterface::class, BIKStaffelCalculator::class);
		$context->registerServiceAlias(BadoControleprotocolServiceInterface::class, BadoControleprotocolService::class);
		$context->registerServiceAlias(BankRulePreviewServiceInterface::class, BankRulePreviewService::class);
		$context->registerServiceAlias(BankRuleSuggestionServiceInterface::class, BankRuleSuggestionService::class);
		$context->registerServiceAlias(BankfeedIntakeServiceInterface::class, BankfeedIntakeService::class);
		$context->registerServiceAlias(BbvProgrammeBudgetServiceInterface::class, BbvProgrammeBudgetService::class);
		$context->registerServiceAlias(BbvSeedServiceInterface::class, BbvSeedService::class);
		$context->registerServiceAlias(BcfClaimServiceInterface::class, BcfClaimService::class);
		$context->registerServiceAlias(BcfCompensationCalculatorInterface::class, BcfCompensationCalculator::class);
		$context->registerServiceAlias(BegrotingswijzigingStackerInterface::class, BegrotingswijzigingStacker::class);
		$context->registerServiceAlias(BillableLinesServiceInterface::class, BillableLinesService::class);
		$context->registerServiceAlias(BillablePeriodInvoiceServiceInterface::class, BillablePeriodInvoiceService::class);
		$context->registerServiceAlias(BookingNotificationServiceInterface::class, BookingNotificationService::class);
		$context->registerServiceAlias(BudgetChartSeriesServiceInterface::class, BudgetChartSeriesService::class);
		$context->registerServiceAlias(BudgetGridCalculatorInterface::class, BudgetGridCalculator::class);
		$context->registerServiceAlias(BudgetGridReaderInterface::class, BudgetGridReader::class);
		$context->registerServiceAlias(BudgetImpactEmitterInterface::class, BudgetImpactEmitter::class);
		$context->registerServiceAlias(BudgetScenarioDefaultPromoterInterface::class, BudgetScenarioDefaultPromoter::class);
		$context->registerServiceAlias(BudgetScenarioEvaluatorInterface::class, BudgetScenarioEvaluator::class);
		$context->registerServiceAlias(BudgetScenarioReaderInterface::class, BudgetScenarioReader::class);
		$context->registerServiceAlias(CBSExportServiceInterface::class, CBSExportService::class);
		$context->registerServiceAlias(CalendarRowsInterface::class, CalendarRows::class);
		$context->registerServiceAlias(CashflowExportServiceInterface::class, CashflowExportService::class);
		$context->registerServiceAlias(ChartOfAccountsCandidateServiceInterface::class, ChartOfAccountsCandidateService::class);
		$context->registerServiceAlias(CogsPosterServiceInterface::class, CogsPosterService::class);
		$context->registerServiceAlias(CommitmentCarryOverServiceInterface::class, CommitmentCarryOverService::class);
		$context->registerServiceAlias(CommitmentInvoicingInterface::class, CommitmentInvoicing::class);
		$context->registerServiceAlias(CommitmentLedgerInterface::class, CommitmentLedger::class);
		$context->registerServiceAlias(CommitmentMaterialisationServiceInterface::class, CommitmentMaterialisationService::class);
		$context->registerServiceAlias(ComplianceDeadlineCalendarServiceInterface::class, ComplianceDeadlineCalendarService::class);
		$context->registerServiceAlias(ComplianceExportServiceInterface::class, ComplianceExportService::class);
		$context->registerServiceAlias(ComplianceServiceInterface::class, ComplianceService::class);
		$context->registerServiceAlias(ConfirmationTokenServiceInterface::class, ConfirmationTokenService::class);
		$context->registerServiceAlias(ConflictDetectionServiceInterface::class, ConflictDetectionService::class);
		$context->registerServiceAlias(ConnectionReportServiceInterface::class, ConnectionReportService::class);
		$context->registerServiceAlias(ContractCostRollupServiceInterface::class, ContractCostRollupService::class);
		$context->registerServiceAlias(ContributionDeclineServiceInterface::class, ContributionDeclineService::class);
		$context->registerServiceAlias(ContributionRaiseServiceInterface::class, ContributionRaiseService::class);
		$context->registerServiceAlias(DBAVbarMonitorServiceInterface::class, DBAVbarMonitorService::class);
		$context->registerServiceAlias(DebtorCreditServiceInterface::class, DebtorCreditService::class);
		$context->registerServiceAlias(DemoDataServiceInterface::class, DemoDataService::class);
		$context->registerServiceAlias(DepositReconciliationServiceInterface::class, DepositReconciliationService::class);
		$context->registerServiceAlias(DepreciationRunInterface::class, DepreciationRun::class);
		$context->registerServiceAlias(DepreciationScheduleServiceInterface::class, DepreciationScheduleService::class);
		$context->registerServiceAlias(DocudeskExtractionClientInterface::class, DocudeskExtractionClient::class);
		$context->registerServiceAlias(DoorsnijdingsVerbodValidatorInterface::class, DoorsnijdingsVerbodValidator::class);
		$context->registerServiceAlias(DunningRunServiceInterface::class, DunningRunService::class);
		$context->registerServiceAlias(DunningStageRendererInterface::class, DunningStageRenderer::class);
		$context->registerServiceAlias(DunningTickRunnerInterface::class, DunningTickRunner::class);
		$context->registerServiceAlias(EInvoiceServiceInterface::class, EInvoiceService::class);
		$context->registerServiceAlias(EmuReportingServiceInterface::class, EmuReportingService::class);
		$context->registerServiceAlias(ExtractionPrefillServiceInterface::class, ExtractionPrefillService::class);
		$context->registerServiceAlias(FixedAssetDepreciationInterface::class, FixedAssetDepreciation::class);
		$context->registerServiceAlias(GlAccountSuggestionClientInterface::class, GlAccountSuggestionClient::class);
		$context->registerServiceAlias(IncassoDossierComposerInterface::class, IncassoDossierComposer::class);
		$context->registerServiceAlias(InvoiceSettlementServiceInterface::class, InvoiceSettlementService::class);
		$context->registerServiceAlias(ManualMatchServiceInterface::class, ManualMatchService::class);
		$context->registerServiceAlias(ObjectRequestBankMatcherInterface::class, ObjectRequestBankMatcher::class);
		$context->registerServiceAlias(ObjectRequestBankSettlementInterface::class, ObjectRequestBankSettlement::class);
		$context->registerServiceAlias(ReinvestmentReservesInterface::class, ReinvestmentReserves::class);
		$context->registerServiceAlias(StatementIntakeServiceInterface::class, StatementIntakeService::class);
		$context->registerServiceAlias(TransactionalGuardInterface::class, TransactionalGuard::class);
		$context->registerServiceAlias(VoluntaryContributionPolicyInterface::class, VoluntaryContributionPolicy::class);
	}//end register()
}//end class
