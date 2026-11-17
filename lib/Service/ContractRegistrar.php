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
 * @spec exclude Structural contract wiring, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\Contracts\AansluitingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AccountantDashboardServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AccountantsdossierExportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ActivityCostPriceCalculationInterface;
use OCA\Shillinq\Kernel\Contracts\AdministrationArchivalServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AdministrationManagementAccessInterface;
use OCA\Shillinq\Kernel\Contracts\AdministrationMembershipServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AnnualAccountsXbrlBuilderInterface;
use OCA\Shillinq\Kernel\Contracts\AppointmentConfirmationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ArInvoiceIcpPdfRendererInterface;
use OCA\Shillinq\Kernel\Contracts\AssetRecordsInterface;
use OCA\Shillinq\Kernel\Contracts\AssignmentHoursInterface;
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
use OCA\Shillinq\Kernel\Contracts\ChartTemplateServiceInterface;
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
use OCA\Shillinq\Kernel\Contracts\ContactCustomerResolverInterface;
use OCA\Shillinq\Kernel\Contracts\ContractCostRollupServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ContributionDeclineServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DirectDebitBatchServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DirectDebitFileServiceInterface;
use OCA\Shillinq\Kernel\Contracts\FiledReturnDriftCheckInterface;
use OCA\Shillinq\Kernel\Contracts\FxRateFeedStatusInterface;
use OCA\Shillinq\Kernel\Contracts\PensionValuationPostingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RecurringInvoiceGeneratorInterface;
use OCA\Shillinq\Kernel\Contracts\RetentionDueServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SbrFilingFileStoreInterface;
use OCA\Shillinq\Kernel\Contracts\SelfBilledInvoiceIntakeInterface;
use OCA\Shillinq\Kernel\Contracts\SelfBilledInvoiceReviewInterface;
use OCA\Shillinq\Kernel\Contracts\SubscriptionCancellationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ContributionRaiseServiceInterface;
use OCA\Shillinq\Kernel\Contracts\CrossSubsidyScanInterface;
use OCA\Shillinq\Kernel\Contracts\CustomerBridgeMetricsServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DBAVbarMonitorServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DebtorCreditServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DemoDataServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DepositReconciliationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DepreciationRunInterface;
use OCA\Shillinq\Kernel\Contracts\DepreciationScheduleServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DocudeskExtractionClientInterface;
use OCA\Shillinq\Kernel\Contracts\DoorsnijdingsVerbodValidatorInterface;
use OCA\Shillinq\Kernel\Contracts\DownPaymentServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DunningRunServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DunningStageRendererInterface;
use OCA\Shillinq\Kernel\Contracts\DunningTickRunnerInterface;
use OCA\Shillinq\Kernel\Contracts\EInvoiceServiceInterface;
use OCA\Shillinq\Kernel\Contracts\EmuReportingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ExceptionResolutionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ExtractionPrefillServiceInterface;
use OCA\Shillinq\Kernel\Contracts\FeeScheduleServiceInterface;
use OCA\Shillinq\Kernel\Contracts\FidoQuarterInterface;
use OCA\Shillinq\Kernel\Contracts\FieldRequirementsInterface;
use OCA\Shillinq\Kernel\Contracts\FifoValuationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\FinancialDashboardServiceInterface;
use OCA\Shillinq\Kernel\Contracts\FinancialDocumentActionsInterface;
use OCA\Shillinq\Kernel\Contracts\FinancialDocumentAuthoringInterface;
use OCA\Shillinq\Kernel\Contracts\FinancialDocumentFinderInterface;
use OCA\Shillinq\Kernel\Contracts\FinancialDocumentRowsInterface;
use OCA\Shillinq\Kernel\Contracts\FiscalYearContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\FixedAssetDepreciationInterface;
use OCA\Shillinq\Kernel\Contracts\FixedAssetDisposalServiceInterface;
use OCA\Shillinq\Kernel\Contracts\FluxServiceInterface;
use OCA\Shillinq\Kernel\Contracts\GlAccountSuggestionClientInterface;
use OCA\Shillinq\Kernel\Contracts\GlLineAdministrationBackfillMigratorInterface;
use OCA\Shillinq\Kernel\Contracts\GlLineFiscalYearBackfillMigratorInterface;
use OCA\Shillinq\Kernel\Contracts\GlLineResultStampsInterface;
use OCA\Shillinq\Kernel\Contracts\GoodsReceiptNoteServiceInterface;
use OCA\Shillinq\Kernel\Contracts\GRIRClearingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\HrmqCostRateAdapterInterface;
use OCA\Shillinq\Kernel\Contracts\IcpFilingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\IcpServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ImportBatchStepsInterface;
use OCA\Shillinq\Kernel\Contracts\ImportPeriodInterface;
use OCA\Shillinq\Kernel\Contracts\InboundPayableReaderInterface;
use OCA\Shillinq\Kernel\Contracts\InboundPayableServiceInterface;
use OCA\Shillinq\Kernel\Contracts\IncassoDossierComposerInterface;
use OCA\Shillinq\Kernel\Contracts\InnovatieboxAggregationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\InnovatieboxAuditEventLoggerInterface;
use OCA\Shillinq\Kernel\Contracts\InnovatieboxSbrExportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\IntercompanyJournalServiceInterface;
use OCA\Shillinq\Kernel\Contracts\IntercompanyLinkServiceInterface;
use OCA\Shillinq\Kernel\Contracts\InterestAllocationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\InventoryMobileScannerServiceInterface;
use OCA\Shillinq\Kernel\Contracts\InventoryScanServiceInterface;
use OCA\Shillinq\Kernel\Contracts\InventoryValuationReportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\InvoiceGenerationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\InvoicePdfGeneratorInterface;
use OCA\Shillinq\Kernel\Contracts\InvoiceSettlementServiceInterface;
use OCA\Shillinq\Kernel\Contracts\JointArrangementAllocationInterface;
use OCA\Shillinq\Kernel\Contracts\KlantbeeldResultInterface;
use OCA\Shillinq\Kernel\Contracts\KorMonitorServiceInterface;
use OCA\Shillinq\Kernel\Contracts\LandedCostAllocationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\LeaseDisclosureServiceInterface;
use OCA\Shillinq\Kernel\Contracts\LeasePaymentScheduleServiceInterface;
use OCA\Shillinq\Kernel\Contracts\LeaseReassessmentServiceInterface;
use OCA\Shillinq\Kernel\Contracts\LedgerPivotServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Kernel\Contracts\ManualMatchServiceInterface;
use OCA\Shillinq\Kernel\Contracts\MeterReadingImportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\MeterReadingRatingInterface;
use OCA\Shillinq\Kernel\Contracts\MilestoneTemplateServiceInterface;
use OCA\Shillinq\Kernel\Contracts\MovingAverageValuationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\MultiPoConsolidationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\NexusCalculationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\NoShowFeeCaptureServiceInterface;
use OCA\Shillinq\Kernel\Contracts\NrvWriteDownServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectApiRequestInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectPaymentRequestValidatorInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectRequestBankMatcherInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectRequestBankSettlementInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectRequestCommandServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectRequestInvoiceServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectRequestReceiptMailerInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectRequestRefundServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Kernel\Contracts\ObligationTaskBridgeInterface;
use OCA\Shillinq\Kernel\Contracts\OssPaymentReconciliationInterface;
use OCA\Shillinq\Kernel\Contracts\OssRateResolverInterface;
use OCA\Shillinq\Kernel\Contracts\OssRecordResolverInterface;
use OCA\Shillinq\Kernel\Contracts\OssReturnGeneratorInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentActionAppGrantInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentActionAuthorizerInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentReconciliationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRequestFinderInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRequestPortalScopeInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentSettlementServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PayrollServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PeriodCloseAssistantServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PeriodCloseServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PipelinqConfigInterface;
use OCA\Shillinq\Kernel\Contracts\PipelinqContactAdapterInterface;
use OCA\Shillinq\Kernel\Contracts\PortalPaymentSessionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ProductCatalogServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ProgrammabegrotingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PurchaseOrderApprovalServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PurchaseOrderServiceInterface;
use OCA\Shillinq\Kernel\Contracts\QuickDraftInvoiceServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ReconciliationResolutionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RecurringSeriesServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Kernel\Contracts\ReinvestmentReservesInterface;
use OCA\Shillinq\Kernel\Contracts\RelationBothSidesServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RelationLinkServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RequisitionConversionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RequisitionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ReserveBalancesInterface;
use OCA\Shillinq\Kernel\Contracts\RevenueContractRenameMigratorInterface;
use OCA\Shillinq\Kernel\Contracts\RevenueCutoffServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RevenueRecognitionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RuleTestDataSeederInterface;
use OCA\Shillinq\Kernel\Contracts\SalesDispatchStockIssueServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SchemaDefinitionsInterface;
use OCA\Shillinq\Kernel\Contracts\SepaAuditServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ServiceReceiptServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SigningDelegationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SignoffDecisionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SlotServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SoftCloseExecutorInterface;
use OCA\Shillinq\Kernel\Contracts\SpendAnalyticsServiceInterface;
use OCA\Shillinq\Kernel\Contracts\StandardsPolicyServiceInterface;
use OCA\Shillinq\Kernel\Contracts\StatementIntakeServiceInterface;
use OCA\Shillinq\Kernel\Contracts\StatementManifestServiceInterface;
use OCA\Shillinq\Kernel\Contracts\StockLedgerServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SubjectCostServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SupplierInvoiceChecksInterface;
use OCA\Shillinq\Kernel\Contracts\SupplierInvoiceServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SuspenseAgeingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\TaskFieldRealisationInterface;
use OCA\Shillinq\Kernel\Contracts\TaxNotificationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\TaxPaymentReconciliationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\TaxReportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\TenderNedStatusSyncInterface;
use OCA\Shillinq\Kernel\Contracts\ThreeWayMatchingEngineInterface;
use OCA\Shillinq\Kernel\Contracts\TimeIntakeServiceInterface;
use OCA\Shillinq\Kernel\Contracts\TransactionalGuardInterface;
use OCA\Shillinq\Kernel\Contracts\TrialBalanceServiceInterface;
use OCA\Shillinq\Kernel\Contracts\VatLineBackfillInterface;
use OCA\Shillinq\Kernel\Contracts\VatNumberCheckInterface;
use OCA\Shillinq\Kernel\Contracts\VATReturnServiceInterface;
use OCA\Shillinq\Kernel\Contracts\VatReturnXbrlBuilderInterface;
use OCA\Shillinq\Kernel\Contracts\VatSuppletieDetectionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\VatSuppletieFilingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\VendorPerformanceAggregationInterface;
use OCA\Shillinq\Kernel\Contracts\ViesServiceInterface;
use OCA\Shillinq\Kernel\Contracts\VoluntaryContributionPolicyInterface;
use OCA\Shillinq\Kernel\Contracts\VsoLockingValidatorInterface;
use OCA\Shillinq\Kernel\Contracts\WbsoAccountServiceInterface;
use OCA\Shillinq\Kernel\Contracts\WbsoAdministratieServiceInterface;
use OCA\Shillinq\Kernel\Contracts\WbsoDocumentServiceInterface;
use OCA\Shillinq\Kernel\Contracts\WbsoRbacResolverInterface;
use OCA\Shillinq\Kernel\Contracts\WbsoTransactionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\WebshopOrderInvoicerInterface;
use OCA\Shillinq\Kernel\Contracts\WidgetAuthServiceInterface;
use OCA\Shillinq\Kernel\Contracts\WidgetServiceInterface;
use OCA\Shillinq\Kernel\Contracts\XbrlInstanceCheckInterface;
use OCA\Shillinq\Kernel\Contracts\XbrlTaxonomyLookupInterface;
use OCA\Shillinq\Kernel\Model\KlantbeeldResult;
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
use OCA\Shillinq\Service\ChartTemplate\ChartTemplateService;
use OCA\Shillinq\Service\Commitment\CommitmentCarryOverService;
use OCA\Shillinq\Service\Commitment\CommitmentInvoicing;
use OCA\Shillinq\Service\Commitment\CommitmentLedger;
use OCA\Shillinq\Service\Commitment\CommitmentMaterialisationService;
use OCA\Shillinq\Service\Dunning\DunningStageRenderer;
use OCA\Shillinq\Service\Dunning\DunningTickRunner;
use OCA\Shillinq\Service\Dunning\IncassoDossierComposer;
use OCA\Shillinq\Service\Dunning\VoluntaryContributionPolicy;
use OCA\Shillinq\Service\EInvoice\EInvoiceService;
use OCA\Shillinq\Service\EInvoice\SelfBilledInvoiceIntake;
use OCA\Shillinq\Service\EInvoice\SelfBilledInvoiceReview;
use OCA\Shillinq\Service\Extraction\ChartOfAccountsCandidateService;
use OCA\Shillinq\Service\Extraction\DocudeskExtractionClient;
use OCA\Shillinq\Service\Extraction\ExtractionPrefillService;
use OCA\Shillinq\Service\Extraction\GlAccountSuggestionClient;
use OCA\Shillinq\Service\Import\ImportBatchSteps;
use OCA\Shillinq\Service\Import\ImportPeriod;
use OCA\Shillinq\Service\Ledger\GlLineResultStamps;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\Migration\GlLineAdministrationBackfillMigrator;
use OCA\Shillinq\Service\Migration\GlLineFiscalYearBackfillMigrator;
use OCA\Shillinq\Service\Migration\RevenueContractRenameMigrator;
use OCA\Shillinq\Service\Payment\PortalPaymentSessionService;
use OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService;
use OCA\Shillinq\Service\Pipelinq\PipelinqConfig;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pivot\LedgerPivotService;
use OCA\Shillinq\Service\Platform\FieldRequirements;
use OCA\Shillinq\Service\Platform\ObjectApiRequest;
use OCA\Shillinq\Service\Platform\SchemaDefinitions;
use OCA\Shillinq\Service\Project\AssignmentHours;
use OCA\Shillinq\Service\PublicSector\ActivityCostPriceCalculation;
use OCA\Shillinq\Service\PublicSector\CrossSubsidyScan;
use OCA\Shillinq\Service\PublicSector\FidoQuarter;
use OCA\Shillinq\Service\PublicSector\InterestAllocationService;
use OCA\Shillinq\Service\PublicSector\JointArrangementAllocation;
use OCA\Shillinq\Service\PublicSector\ReserveBalances;
use OCA\Shillinq\Service\PublicSector\TaskFieldRealisation;
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\Receivables\DirectDebitBatchService;
use OCA\Shillinq\Service\Receivables\DirectDebitFileService;
use OCA\Shillinq\Service\Relation\RelationBothSidesService;
use OCA\Shillinq\Service\Relation\RelationLinkService;
use OCA\Shillinq\Service\Sales\DownPaymentService;
use OCA\Shillinq\Service\Sales\QuickDraftInvoiceService;
use OCA\Shillinq\Service\Sbr\AnnualAccountsXbrlBuilder;
use OCA\Shillinq\Service\Sbr\SbrFilingFileStore;
use OCA\Shillinq\Service\Sbr\VatReturnXbrlBuilder;
use OCA\Shillinq\Service\Sbr\XbrlInstanceCheck;
use OCA\Shillinq\Service\Sbr\XbrlTaxonomyLookup;
use OCA\Shillinq\Service\Signing\SigningDelegationService;
use OCA\Shillinq\Service\Signing\SignoffDecisionService;
use OCA\Shillinq\Service\Tax\FiledReturnDriftCheck;
use OCA\Shillinq\Service\Tax\VatNumberCheck;
use OCA\Shillinq\Service\Tax\VatSuppletieFilingService;
use OCA\Shillinq\Service\Treasury\FxRateFeedStatus;
use OCA\Shillinq\Service\Usage\MeterReadingImportService;
use OCA\Shillinq\Service\Usage\MeterReadingRating;
use OCA\Shillinq\Service\Vat\VatLineBackfill;
use OCA\Shillinq\Service\Webshop\WebshopOrderInvoicer;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\Shillinq\Kernel\Contracts\EnsiaCycleStepsInterface;
use OCA\Shillinq\Kernel\Contracts\LawfulnessParagraphInterface;
use OCA\Shillinq\Service\PublicSector\EnsiaCycleSteps;
use OCA\Shillinq\Service\PublicSector\LawfulnessParagraph;

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
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 */
final class ContractRegistrar {

	/**
	 * Every contract and the class that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	private const ALIASES = [
		AansluitingServiceInterface::class => AansluitingService::class,
		AccountantDashboardServiceInterface::class => AccountantDashboardService::class,
		AccountantsdossierExportServiceInterface::class => AccountantsdossierExportService::class,
		ActivityCostPriceCalculationInterface::class => ActivityCostPriceCalculation::class,
		AdministrationArchivalServiceInterface::class => AdministrationArchivalService::class,
		AdministrationContextServiceInterface::class => AdministrationContextService::class,
		AdministrationManagementAccessInterface::class => AdministrationManagementAccess::class,
		AdministrationMembershipServiceInterface::class => AdministrationMembershipService::class,
		AnnualAccountsXbrlBuilderInterface::class => AnnualAccountsXbrlBuilder::class,
		AppointmentConfirmationServiceInterface::class => AppointmentConfirmationService::class,
		ArInvoiceIcpPdfRendererInterface::class => ArInvoiceIcpPdfRenderer::class,
		AssetRecordsInterface::class => AssetRecords::class,
		AssignmentHoursInterface::class => AssignmentHours::class,
		AuditExportServiceInterface::class => AuditExportService::class,
		BIKStaffelCalculatorInterface::class => BIKStaffelCalculator::class,
		BadoControleprotocolServiceInterface::class => BadoControleprotocolService::class,
		BankRulePreviewServiceInterface::class => BankRulePreviewService::class,
		BankRuleSuggestionServiceInterface::class => BankRuleSuggestionService::class,
		BankfeedIntakeServiceInterface::class => BankfeedIntakeService::class,
		BbvProgrammeBudgetServiceInterface::class => BbvProgrammeBudgetService::class,
		BbvSeedServiceInterface::class => BbvSeedService::class,
		BcfClaimServiceInterface::class => BcfClaimService::class,
		BcfCompensationCalculatorInterface::class => BcfCompensationCalculator::class,
		BegrotingswijzigingStackerInterface::class => BegrotingswijzigingStacker::class,
		BillableLinesServiceInterface::class => BillableLinesService::class,
		BillablePeriodInvoiceServiceInterface::class => BillablePeriodInvoiceService::class,
		BookingNotificationServiceInterface::class => BookingNotificationService::class,
		BudgetChartSeriesServiceInterface::class => BudgetChartSeriesService::class,
		BudgetGridCalculatorInterface::class => BudgetGridCalculator::class,
		BudgetGridReaderInterface::class => BudgetGridReader::class,
		BudgetImpactEmitterInterface::class => BudgetImpactEmitter::class,
		BudgetScenarioDefaultPromoterInterface::class => BudgetScenarioDefaultPromoter::class,
		BudgetScenarioEvaluatorInterface::class => BudgetScenarioEvaluator::class,
		BudgetScenarioReaderInterface::class => BudgetScenarioReader::class,
		CBSExportServiceInterface::class => CBSExportService::class,
		CalendarRowsInterface::class => CalendarRows::class,
		CashflowExportServiceInterface::class => CashflowExportService::class,
		ChartOfAccountsCandidateServiceInterface::class => ChartOfAccountsCandidateService::class,
		ChartTemplateServiceInterface::class => ChartTemplateService::class,
		CogsPosterServiceInterface::class => CogsPosterService::class,
		CommitmentCarryOverServiceInterface::class => CommitmentCarryOverService::class,
		CommitmentInvoicingInterface::class => CommitmentInvoicing::class,
		CommitmentLedgerInterface::class => CommitmentLedger::class,
		CommitmentMaterialisationServiceInterface::class => CommitmentMaterialisationService::class,
		ComplianceDeadlineCalendarServiceInterface::class => ComplianceDeadlineCalendarService::class,
		ComplianceExportServiceInterface::class => ComplianceExportService::class,
		ComplianceServiceInterface::class => ComplianceService::class,
		ConfirmationTokenServiceInterface::class => ConfirmationTokenService::class,
		ConflictDetectionServiceInterface::class => ConflictDetectionService::class,
		ConnectionReportServiceInterface::class => ConnectionReportService::class,
		ContactCustomerResolverInterface::class => ContactCustomerResolver::class,
		ContractCostRollupServiceInterface::class => ContractCostRollupService::class,
		ContributionDeclineServiceInterface::class => ContributionDeclineService::class,
		DirectDebitBatchServiceInterface::class => DirectDebitBatchService::class,
		DirectDebitFileServiceInterface::class => DirectDebitFileService::class,
		FiledReturnDriftCheckInterface::class => FiledReturnDriftCheck::class,
		FxRateFeedStatusInterface::class => FxRateFeedStatus::class,
		PensionValuationPostingServiceInterface::class => PensionValuationPostingService::class,
		RecurringInvoiceGeneratorInterface::class => RecurringInvoiceGenerator::class,
		RetentionDueServiceInterface::class => RetentionDueService::class,
		SbrFilingFileStoreInterface::class => SbrFilingFileStore::class,
		SelfBilledInvoiceIntakeInterface::class => SelfBilledInvoiceIntake::class,
		SelfBilledInvoiceReviewInterface::class => SelfBilledInvoiceReview::class,
		SubscriptionCancellationServiceInterface::class => SubscriptionCancellationService::class,
		ContributionRaiseServiceInterface::class => ContributionRaiseService::class,
		CrossSubsidyScanInterface::class => CrossSubsidyScan::class,
		CustomerBridgeMetricsServiceInterface::class => CustomerBridgeMetricsService::class,
		DBAVbarMonitorServiceInterface::class => DBAVbarMonitorService::class,
		DebtorCreditServiceInterface::class => DebtorCreditService::class,
		DemoDataServiceInterface::class => DemoDataService::class,
		DepositReconciliationServiceInterface::class => DepositReconciliationService::class,
		DepreciationRunInterface::class => DepreciationRun::class,
		DepreciationScheduleServiceInterface::class => DepreciationScheduleService::class,
		DocudeskExtractionClientInterface::class => DocudeskExtractionClient::class,
		DoorsnijdingsVerbodValidatorInterface::class => DoorsnijdingsVerbodValidator::class,
		DownPaymentServiceInterface::class => DownPaymentService::class,
		DunningRunServiceInterface::class => DunningRunService::class,
		DunningStageRendererInterface::class => DunningStageRenderer::class,
		DunningTickRunnerInterface::class => DunningTickRunner::class,
		EInvoiceServiceInterface::class => EInvoiceService::class,
		EmuReportingServiceInterface::class => EmuReportingService::class,
		EnsiaCycleStepsInterface::class => EnsiaCycleSteps::class,
		ExceptionResolutionServiceInterface::class => ExceptionResolutionService::class,
		ExtractionPrefillServiceInterface::class => ExtractionPrefillService::class,
		FeeScheduleServiceInterface::class => FeeScheduleService::class,
		FidoQuarterInterface::class => FidoQuarter::class,
		FieldRequirementsInterface::class => FieldRequirements::class,
		FifoValuationServiceInterface::class => FifoValuationService::class,
		FinancialDashboardServiceInterface::class => FinancialDashboardService::class,
		FinancialDocumentActionsInterface::class => FinancialDocumentActions::class,
		FinancialDocumentAuthoringInterface::class => FinancialDocumentAuthoring::class,
		FinancialDocumentFinderInterface::class => FinancialDocumentFinder::class,
		FinancialDocumentRowsInterface::class => FinancialDocumentRows::class,
		FiscalYearContextServiceInterface::class => FiscalYearContextService::class,
		FixedAssetDepreciationInterface::class => FixedAssetDepreciation::class,
		FixedAssetDisposalServiceInterface::class => FixedAssetDisposalService::class,
		FluxServiceInterface::class => FluxService::class,
		GRIRClearingServiceInterface::class => GRIRClearingService::class,
		GlAccountSuggestionClientInterface::class => GlAccountSuggestionClient::class,
		GlLineAdministrationBackfillMigratorInterface::class => GlLineAdministrationBackfillMigrator::class,
		GlLineFiscalYearBackfillMigratorInterface::class => GlLineFiscalYearBackfillMigrator::class,
		GlLineResultStampsInterface::class => GlLineResultStamps::class,
		GoodsReceiptNoteServiceInterface::class => GoodsReceiptNoteService::class,
		HrmqCostRateAdapterInterface::class => HrmqCostRateAdapter::class,
		IcpFilingServiceInterface::class => IcpFilingService::class,
		IcpServiceInterface::class => IcpService::class,
		ImportBatchStepsInterface::class => ImportBatchSteps::class,
		ImportPeriodInterface::class => ImportPeriod::class,
		InboundPayableReaderInterface::class => InboundPayableReader::class,
		InboundPayableServiceInterface::class => InboundPayableService::class,
		IncassoDossierComposerInterface::class => IncassoDossierComposer::class,
		InnovatieboxAggregationServiceInterface::class => InnovatieboxAggregationService::class,
		InnovatieboxAuditEventLoggerInterface::class => InnovatieboxAuditEventLogger::class,
		InnovatieboxSbrExportServiceInterface::class => InnovatieboxSbrExportService::class,
		IntercompanyJournalServiceInterface::class => IntercompanyJournalService::class,
		IntercompanyLinkServiceInterface::class => IntercompanyLinkService::class,
		InterestAllocationServiceInterface::class => InterestAllocationService::class,
		InventoryMobileScannerServiceInterface::class => InventoryMobileScannerService::class,
		InventoryScanServiceInterface::class => InventoryScanService::class,
		InventoryValuationReportServiceInterface::class => InventoryValuationReportService::class,
		InvoiceGenerationServiceInterface::class => InvoiceGenerationService::class,
		InvoicePdfGeneratorInterface::class => InvoicePdfGenerator::class,
		InvoiceSettlementServiceInterface::class => InvoiceSettlementService::class,
		JointArrangementAllocationInterface::class => JointArrangementAllocation::class,
		KlantbeeldResultInterface::class => KlantbeeldResult::class,
		KorMonitorServiceInterface::class => KorMonitorService::class,
		LandedCostAllocationServiceInterface::class => LandedCostAllocationService::class,
		LawfulnessParagraphInterface::class => LawfulnessParagraph::class,
		LeaseDisclosureServiceInterface::class => LeaseDisclosureService::class,
		LeasePaymentScheduleServiceInterface::class => LeasePaymentScheduleService::class,
		LeaseReassessmentServiceInterface::class => LeaseReassessmentService::class,
		LedgerPivotServiceInterface::class => LedgerPivotService::class,
		ListenerSchemaResolverInterface::class => ListenerSchemaResolver::class,
		ManualMatchServiceInterface::class => ManualMatchService::class,
		MeterReadingImportServiceInterface::class => MeterReadingImportService::class,
		MeterReadingRatingInterface::class => MeterReadingRating::class,
		MilestoneTemplateServiceInterface::class => MilestoneTemplateService::class,
		MovingAverageValuationServiceInterface::class => MovingAverageValuationService::class,
		MultiPoConsolidationServiceInterface::class => MultiPoConsolidationService::class,
		NexusCalculationServiceInterface::class => NexusCalculationService::class,
		NoShowFeeCaptureServiceInterface::class => NoShowFeeCaptureService::class,
		NrvWriteDownServiceInterface::class => NrvWriteDownService::class,
		ObjectApiRequestInterface::class => ObjectApiRequest::class,
		ObjectPaymentRequestValidatorInterface::class => ObjectPaymentRequestValidator::class,
		ObjectRequestBankMatcherInterface::class => ObjectRequestBankMatcher::class,
		ObjectRequestBankSettlementInterface::class => ObjectRequestBankSettlement::class,
		ObjectRequestCommandServiceInterface::class => ObjectRequestCommandService::class,
		ObjectRequestInvoiceServiceInterface::class => ObjectRequestInvoiceService::class,
		ObjectRequestReceiptMailerInterface::class => ObjectRequestReceiptMailer::class,
		ObjectRequestRefundServiceInterface::class => ObjectRequestRefundService::class,
		ObjectTransitionRunnerInterface::class => ObjectTransitionRunner::class,
		ObligationTaskBridgeInterface::class => ObligationTaskBridge::class,
		OssPaymentReconciliationInterface::class => OssPaymentReconciliation::class,
		OssRateResolverInterface::class => OssRateResolver::class,
		OssRecordResolverInterface::class => OssRecordResolver::class,
		OssReturnGeneratorInterface::class => OssReturnGenerator::class,
		PaymentActionAppGrantInterface::class => PaymentActionAppGrant::class,
		PaymentActionAuthorizerInterface::class => PaymentActionAuthorizer::class,
		PaymentReconciliationServiceInterface::class => PaymentReconciliationService::class,
		PaymentRequestFinderInterface::class => PaymentRequestFinder::class,
		PaymentRequestPortalScopeInterface::class => PaymentRequestPortalScope::class,
		PaymentSettlementServiceInterface::class => PaymentSettlementService::class,
		PayrollServiceInterface::class => PayrollService::class,
		PeriodCloseAssistantServiceInterface::class => PeriodCloseAssistantService::class,
		PeriodCloseServiceInterface::class => PeriodCloseService::class,
		PipelinqConfigInterface::class => PipelinqConfig::class,
		PipelinqContactAdapterInterface::class => PipelinqContactAdapter::class,
		PortalPaymentSessionServiceInterface::class => PortalPaymentSessionService::class,
		ProductCatalogServiceInterface::class => ProductCatalogService::class,
		ProgrammabegrotingServiceInterface::class => ProgrammabegrotingService::class,
		PurchaseOrderApprovalServiceInterface::class => PurchaseOrderApprovalService::class,
		PurchaseOrderServiceInterface::class => PurchaseOrderService::class,
		QuickDraftInvoiceServiceInterface::class => QuickDraftInvoiceService::class,
		ReconciliationResolutionServiceInterface::class => ReconciliationResolutionService::class,
		RecurringSeriesServiceInterface::class => RecurringSeriesService::class,
		RegisterLocationInterface::class => RegisterLocation::class,
		ReinvestmentReservesInterface::class => ReinvestmentReserves::class,
		RelationBothSidesServiceInterface::class => RelationBothSidesService::class,
		RelationLinkServiceInterface::class => RelationLinkService::class,
		RequisitionConversionServiceInterface::class => RequisitionConversionService::class,
		RequisitionServiceInterface::class => RequisitionService::class,
		ReserveBalancesInterface::class => ReserveBalances::class,
		RevenueContractRenameMigratorInterface::class => RevenueContractRenameMigrator::class,
		RevenueCutoffServiceInterface::class => RevenueCutoffService::class,
		RevenueRecognitionServiceInterface::class => RevenueRecognitionService::class,
		RuleTestDataSeederInterface::class => RuleTestDataSeeder::class,
		SalesDispatchStockIssueServiceInterface::class => SalesDispatchStockIssueService::class,
		SchemaDefinitionsInterface::class => SchemaDefinitions::class,
		SepaAuditServiceInterface::class => SepaAuditService::class,
		ServiceReceiptServiceInterface::class => ServiceReceiptService::class,
		SettingsServiceInterface::class => SettingsService::class,
		SigningDelegationServiceInterface::class => SigningDelegationService::class,
		SignoffDecisionServiceInterface::class => SignoffDecisionService::class,
		SlotServiceInterface::class => SlotService::class,
		SoftCloseExecutorInterface::class => SoftCloseExecutor::class,
		SpendAnalyticsServiceInterface::class => SpendAnalyticsService::class,
		StandardsPolicyServiceInterface::class => StandardsPolicyService::class,
		StatementIntakeServiceInterface::class => StatementIntakeService::class,
		StatementManifestServiceInterface::class => StatementManifestService::class,
		StockLedgerServiceInterface::class => StockLedgerService::class,
		SubjectCostServiceInterface::class => SubjectCostService::class,
		SupplierInvoiceChecksInterface::class => SupplierInvoiceChecks::class,
		SupplierInvoiceServiceInterface::class => SupplierInvoiceService::class,
		SuspenseAgeingServiceInterface::class => SuspenseAgeingService::class,
		TaskFieldRealisationInterface::class => TaskFieldRealisation::class,
		TaxNotificationServiceInterface::class => TaxNotificationService::class,
		TaxPaymentReconciliationServiceInterface::class => TaxPaymentReconciliationService::class,
		TaxReportServiceInterface::class => TaxReportService::class,
		TenderNedStatusSyncInterface::class => TenderNedStatusSync::class,
		ThreeWayMatchingEngineInterface::class => ThreeWayMatchingEngine::class,
		TimeIntakeServiceInterface::class => TimeIntakeService::class,
		TransactionalGuardInterface::class => TransactionalGuard::class,
		TrialBalanceServiceInterface::class => TrialBalanceService::class,
		VATReturnServiceInterface::class => VATReturnService::class,
		VatLineBackfillInterface::class => VatLineBackfill::class,
		VatNumberCheckInterface::class => VatNumberCheck::class,
		VatReturnXbrlBuilderInterface::class => VatReturnXbrlBuilder::class,
		VatSuppletieDetectionServiceInterface::class => VatSuppletieDetectionService::class,
		VatSuppletieFilingServiceInterface::class => VatSuppletieFilingService::class,
		VendorPerformanceAggregationInterface::class => VendorPerformanceAggregation::class,
		ViesServiceInterface::class => ViesService::class,
		VoluntaryContributionPolicyInterface::class => VoluntaryContributionPolicy::class,
		VsoLockingValidatorInterface::class => VsoLockingValidator::class,
		WbsoAccountServiceInterface::class => WbsoAccountService::class,
		WbsoAdministratieServiceInterface::class => WbsoAdministratieService::class,
		WbsoDocumentServiceInterface::class => WbsoDocumentService::class,
		WbsoRbacResolverInterface::class => WbsoRbacResolver::class,
		WbsoTransactionServiceInterface::class => WbsoTransactionService::class,
		WebshopOrderInvoicerInterface::class => WebshopOrderInvoicer::class,
		WidgetAuthServiceInterface::class => WidgetAuthService::class,
		WidgetServiceInterface::class => WidgetService::class,
		XbrlInstanceCheckInterface::class => XbrlInstanceCheck::class,
		XbrlTaxonomyLookupInterface::class => XbrlTaxonomyLookup::class,
	];

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
		foreach (self::ALIASES as $contract => $implementation) {
			$context->registerServiceAlias($contract, $implementation);
		}
	}//end register()
}//end class
