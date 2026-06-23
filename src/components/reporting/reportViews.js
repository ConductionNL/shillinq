// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Report VIEW pages surfaced as navigate-cards on the Reporting & Compliance
 * overview (kind="view" — open their page by route name). Sourced from the menu-IA
 * audit REPORTS verdicts so the whole report surface lives on one cards page.
 */

export const reportViewCategories = {
 "operational": "Operationele overzichten",
 "compliance": "Compliance, audit & dossiers",
 "statements": "Jaarrekening & consolidatie",
 "public-sector": "Overheidsrapportages",
 "tax": "Belastingrapportages",
 "sbr": "SBR / XBRL"
}

export const reportViews = [
 {
  "id": "APAging",
  "label": "AP Aging",
  "icon": "CalendarClockOutline",
  "category": "operational"
 },
 {
  "id": "ARAging",
  "label": "AR Aging",
  "icon": "CalendarClockOutline",
  "category": "operational"
 },
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
  "id": "BcfClaims",
  "label": "BCF-claims",
  "icon": "CashRefund",
  "category": "public-sector"
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
  "id": "CashflowDashboard",
  "label": "Cashflow Dashboard",
  "icon": "ChartBarStacked",
  "category": "compliance"
 },
 {
  "id": "CashflowScenarios",
  "label": "Scenarios",
  "icon": "SourceBranch",
  "category": "compliance"
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
  "id": "DeferredTaxMovements",
  "label": "Mutatieoverzicht",
  "icon": "ArrowUpDown",
  "category": "tax"
 },
 {
  "id": "DunningTimeline",
  "label": "Dunning Timeline",
  "icon": "EmailAlertOutline",
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
  "category": "operational"
 },
 {
  "id": "ENSIAFindings",
  "label": "ENSIA Findings",
  "icon": "AlertCircleOutline",
  "category": "compliance"
 },
 {
  "id": "EmuRapportage",
  "label": "EMU-rapportage",
  "icon": "ChartBarOutline",
  "category": "public-sector"
 },
 {
  "id": "ExpiryAlerts",
  "label": "Expiry Alerts",
  "icon": "AlertCircleOutline",
  "category": "compliance"
 },
 {
  "id": "GRConsolidated",
  "label": "Geconsolideerde view",
  "icon": "TableMergeCellsOutline",
  "category": "statements"
 },
 {
  "id": "IbAangifte",
  "label": "IB-aangifte",
  "icon": "FileExportOutline",
  "category": "tax"
 },
 {
  "id": "IcpOpgaaf",
  "label": "ICP-opgaaf",
  "icon": "EarthArrowRight",
  "category": "tax"
 },
 {
  "id": "InventoryPostingHistory",
  "label": "Posting Historie",
  "icon": "BookOpenVariantOutline",
  "category": "compliance"
 },
 {
  "id": "InventoryValuation",
  "label": "Valuation",
  "icon": "ScaleBalance",
  "category": "operational"
 },
 {
  "id": "Iv3Aanlevering",
  "label": "Iv3-aanlevering",
  "icon": "ChartTimelineVariant",
  "category": "public-sector"
 },
 {
  "id": "Iv3Rapportages",
  "label": "IV3-rapportages",
  "icon": "FileChartOutline",
  "category": "public-sector"
 },
 {
  "id": "KorStatus",
  "label": "KOR-status",
  "icon": "ShieldCheckOutline",
  "category": "tax"
 },
 {
  "id": "LowStockAlerts",
  "label": "Low Stock Alerts",
  "icon": "AlertCircleOutline",
  "category": "operational"
 },
 {
  "id": "ManagementLetters",
  "label": "Management letters",
  "icon": "EmailOutline",
  "category": "compliance"
 },
 {
  "id": "PensionDisclosureTables",
  "label": "Disclosure Tables",
  "icon": "TableLarge",
  "category": "compliance"
 },
 {
  "id": "SbrXbrlFilings",
  "label": "SBR/XBRL Filings",
  "icon": "FileXmlBoxOutline",
  "category": "sbr"
 },
 {
  "id": "SchatkistPositie",
  "label": "Schatkist-positie",
  "icon": "BankOutline",
  "category": "public-sector"
 },
 {
  "id": "SisaRapportages",
  "label": "SiSa-rapportages",
  "icon": "FileDocumentCheckOutline",
  "category": "public-sector"
 },
 {
  "id": "StockLevelsDashboard",
  "label": "Stock Levels Dashboard",
  "icon": "ChartBarOutline",
  "category": "operational"
 },
 {
  "id": "TaxEstimates",
  "label": "Tax Estimates",
  "icon": "CalculatorOutline",
  "category": "compliance"
 },
 {
  "id": "TaxFilingPrep",
  "label": "Tax Filing Prep",
  "icon": "FileDocumentCheckOutline",
  "category": "compliance"
 },
 {
  "id": "TaxLossCarryForwards",
  "label": "Compensabele verliezen",
  "icon": "AccountGroup",
  "category": "compliance"
 },
 {
  "id": "TaxProvisions",
  "label": "Belastinglatentie",
  "icon": "CurrencyEur",
  "category": "tax"
 },
 {
  "id": "TaxRateReconciliations",
  "label": "ETR-aansluiting",
  "icon": "ChartBar",
  "category": "tax"
 },
 {
  "id": "TemporaryDifferences",
  "label": "Tijdelijke verschillen",
  "icon": "ScaleBalance",
  "category": "tax"
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
  "id": "Utilisatie",
  "label": "Utilisatie",
  "icon": "ChartLineVariant",
  "category": "compliance"
 },
 {
  "id": "VATByPeriod",
  "label": "VAT by Period",
  "icon": "ChartTimelineVariantOutline",
  "category": "tax"
 },
 {
  "id": "VarianceReport",
  "label": "Variance Report",
  "icon": "ChartTimelineVariantShimmer",
  "category": "operational"
 },
 {
  "id": "WBSOExport",
  "label": "WBSO Export Dashboard",
  "icon": "FileExportOutline",
  "category": "compliance"
 },
 {
  "id": "YearEndCloseChecklist",
  "label": "Year-End Close Checklist",
  "icon": "ClipboardCheckOutline",
  "category": "compliance"
 },
 {
  "id": "ZzpAftrek",
  "label": "ZZP-aftrek",
  "icon": "AccountCashOutline",
  "category": "tax"
 },
 {
  "id": "Jaarrekening",
  "label": "Annual accounts",
  "icon": "FileDocumentOutline",
  "category": "statements"
 },
 {
  "id": "IbAangiftenZzp",
  "label": "IB returns",
  "icon": "FileDocumentEditOutline",
  "category": "tax"
 },
 {
  "id": "VpbBalansAangifte",
  "label": "Vpb balance + return preparation",
  "icon": "FileDocumentMultipleOutline",
  "category": "tax"
 },
 {
  "id": "VpbReports",
  "label": "Quarterly statement",
  "icon": "FileChartOutline",
  "category": "tax"
 }
]
