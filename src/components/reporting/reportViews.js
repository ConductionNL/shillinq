// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Report VIEW pages surfaced as navigate-cards on the Reporting & Compliance
 * overview (kind="view" — open their page by route name rather than generating a
 * file), so the menu holds one "Reporting & Compliance" entry instead of 28
 * scattered report sub-items.
 */

export const reportViewCategories = {
 "compliance": "Compliance, audit & dossiers",
 "statements": "Jaarrekening & consolidatie",
 "tax": "Belastingrapportages",
 "public-sector": "Overheidsrapportages",
 "sbr": "SBR / XBRL"
}

export const reportViews = [
 {
  "id": "AuditDocuments",
  "label": "Auditdocumenten",
  "icon": "FileSignOutline",
  "category": "compliance"
 },
 {
  "id": "BalanceSheet",
  "label": "Balance Sheet",
  "icon": "TableAccountOutline",
  "category": "statements"
 },
 {
  "id": "BtwAangiften",
  "label": "BTW-aangiften",
  "icon": "FileDocumentOutline",
  "category": "tax"
 },
 {
  "id": "BtwCorrecties",
  "label": "BTW-correcties",
  "icon": "FileDocumentEditOutline",
  "category": "tax"
 },
 {
  "id": "ComplianceAuditTrails",
  "label": "Compliance audittrail",
  "icon": "ClipboardCheckOutline",
  "category": "compliance"
 },
 {
  "id": "ConsolidatedReport",
  "label": "Consolidated Report",
  "icon": "FileChartOutline",
  "category": "statements"
 },
 {
  "id": "DBAEvidenceBrowser",
  "label": "DBA Evidence Browser",
  "icon": "FolderLockOutline",
  "category": "compliance"
 },
 {
  "id": "DBAIntakeWizard",
  "label": "DBA Intake Wizard",
  "icon": "ClipboardListOutline",
  "category": "compliance"
 },
 {
  "id": "DBAModelovereenkomstRegister",
  "label": "DBA Modelovereenkomst Register",
  "icon": "FileDocumentMultipleOutline",
  "category": "compliance"
 },
 {
  "id": "DBAPortfolioDashboard",
  "label": "DBA Portfolio Dashboard",
  "icon": "ChartDonutVariant",
  "category": "compliance"
 },
 {
  "id": "ENSIAAuditTrail",
  "label": "ENSIA Audit Trail",
  "icon": "ClipboardCheckOutline",
  "category": "compliance"
 },
 {
  "id": "ENSIACollegeVerklaring",
  "label": "ENSIA College Verklaring",
  "icon": "FileSignOutline",
  "category": "compliance"
 },
 {
  "id": "ENSIACycles",
  "label": "ENSIA Cycles",
  "icon": "CalendarCheckOutline",
  "category": "compliance"
 },
 {
  "id": "ENSIAEvaluations",
  "label": "ENSIA Evaluations",
  "icon": "ClipboardQuestionOutline",
  "category": "compliance"
 },
 {
  "id": "ENSIAFindings",
  "label": "ENSIA Findings",
  "icon": "AlertCircleOutline",
  "category": "compliance"
 },
 {
  "id": "EliminationRules",
  "label": "Elimination Rules",
  "icon": "VectorDifference",
  "category": "statements"
 },
 {
  "id": "IcpOpgaaf",
  "label": "ICP-opgaaf",
  "icon": "EarthArrowRight",
  "category": "tax"
 },
 {
  "id": "IntercompanyTransactions",
  "label": "Inter-Company Transactions",
  "icon": "SwapHorizontalBold",
  "category": "statements"
 },
 {
  "id": "Iv3Rapportages",
  "label": "IV3-rapportages",
  "icon": "FileChartOutline",
  "category": "public-sector"
 },
 {
  "id": "ManagementLetters",
  "label": "Management letters",
  "icon": "EmailOutline",
  "category": "compliance"
 },
 {
  "id": "SBRDocuments",
  "label": "SBR Documents",
  "icon": "FileDocumentEditOutline",
  "category": "sbr"
 },
 {
  "id": "SbrXbrlFilings",
  "label": "SBR/XBRL Filings",
  "icon": "FileXmlBoxOutline",
  "category": "sbr"
 },
 {
  "id": "SisaRapportages",
  "label": "SiSa-rapportages",
  "icon": "FileDocumentCheckOutline",
  "category": "public-sector"
 },
 {
  "id": "TrialBalance",
  "label": "Trial Balance",
  "icon": "ScaleBalanceOutline",
  "category": "statements"
 },
 {
  "id": "TrialBalanceLines",
  "label": "Trial Balance (by account)",
  "icon": "ScaleBalance",
  "category": "statements"
 },
 {
  "id": "VATByPeriod",
  "label": "VAT by Period",
  "icon": "ChartTimelineVariantOutline",
  "category": "tax"
 },
 {
  "id": "XBRLMappingValidation",
  "label": "Mapping Validation",
  "icon": "ArrowDecisionOutline",
  "category": "sbr"
 },
 {
  "id": "XBRLTaxonomies",
  "label": "XBRL Taxonomies",
  "icon": "FileTreeOutline",
  "category": "sbr"
 }
]
