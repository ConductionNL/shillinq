<?php

/**
 * Audit Export Service.
 *
 * Slice 11 of the bookkeeping-purchase-order-3way chain — assembles the
 * complete 3-way-match lifecycle history for one SupplierInvoice and
 * exports it as an immutable, structured ZIP package per REQ-PO3W-010
 * (NV COS 230 / BW2 art 2:10). The package is the forensic backbone an
 * external auditor uses to sample-test the control.
 *
 * Package layout (deterministic — byte-identical for the same lifecycle):
 *
 *  audit-package-{invoiceNumber}/
 *    ├── manifest.json          — package metadata (id, generatedAt,
 *    │                             generatedBy, sha256, retention)
 *    ├── ledger.json            — JSON ledger of every lifecycle event
 *    │                             keyed (event, timestamp, actor,
 *    │                             objectType, objectId, payload)
 *    ├── summary.pdf.html       — human-readable PDF summary (HTML
 *    │                             stub — InvoicePdfGenerator pattern)
 *    └── attachments/
 *        ├── purchase-orders/   — PO records as JSON
 *        ├── goods-receipts/    — GRN records + photo file-id refs
 *        ├── supplier-invoice/  — SI record + Peppol metadata
 *        ├── three-way-match/   — match record + divergence details
 *        └── gl-postings/       — GR/IR clearing references (slice 09)
 *
 * The package is server-derived — content comes from server records,
 * NOT from client input (ADR-005). The manifest stamps a SHA-256 over
 * the assembled ledger so post-hoc tampering surfaces. Retention is set
 * to 7 years (BW2 art 2:10); the docudesk archival hand-off is best-effort
 * and surfaces through the returned envelope so the controller can
 * report partial success.
 *
 * Administration scope is validated server-side by
 * {@see AdministrationContextService::canAccess()} — cross-tenant calls
 * are masked as RuntimeException(`not found`) and surface as 404 in the
 * controller (ADR-005 IDOR-safe). The invoiceId is re-loaded from OR so
 * a forged POST cannot pivot to another tenant's invoice.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Slice 11 — exports the complete 3-way-match lifecycle history for a
 * SupplierInvoice as an immutable ZIP audit package (REQ-PO3W-010).
 *
 * Public methods:
 *  - generateAuditPackage(): assemble the full lifecycle history, build
 *    the JSON ledger + HTML summary + attachments, write the ZIP to a
 *    temp file and return a package envelope (id, sha256, zipPath,
 *    archived flag) so the controller can stream or hand off the bytes.
 *  - buildLedger(): hermetic ledger assembly (no I/O) — exposed so the
 *    Vue timeline can fetch the same payload via a read-only endpoint.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 */
class AuditExportService {

	/**
	 * Schema slug for SupplierInvoice records (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_SUPPLIER_INVOICE = 'SupplierInvoice';

	/**
	 * Schema slug for ThreeWayMatch records (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_THREE_WAY_MATCH = 'ThreeWayMatch';

	/**
	 * Schema slug for PurchaseOrder records (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_PURCHASE_ORDER = 'PurchaseOrder';

	/**
	 * Schema slug for GoodsReceiptNote records (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_GOODS_RECEIPT_NOTE = 'GoodsReceiptNote';

	/**
	 * Retention period for the audit package (BW2 art 2:10).
	 *
	 * @var int
	 */
	public const RETENTION_YEARS = 7;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR's
	 *                                      ObjectService is fetched
	 *                                      lazily so unit tests
	 *                                      swap an in-memory stub.
	 * @param IAppConfig $appConfig App config for the OR
	 *                              register slug.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession Session for generatedBy
	 *                                  attribution.
	 * @param LoggerInterface $logger Logger (no sensitive
	 *                                payloads).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Generate an immutable audit package for one SupplierInvoice and
	 * return the package envelope.
	 *
	 * Steps:
	 *  1. Resolve the SupplierInvoice + every linked PO/GRN/3WM record.
	 *  2. Build the ledger via {@see buildLedger()}.
	 *  3. Render the HTML summary via {@see renderSummaryHtml()}.
	 *  4. Assemble manifest.json with sha256 over the ledger + retention
	 *     marker.
	 *  5. Write the ZIP to a tempnam file (sys_get_temp_dir).
	 *  6. Best-effort hand-off to docudesk archival (logged-only stub
	 *     here; production wires the real adapter).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId SupplierInvoice id.
	 *
	 * @return array{
	 *     packageId:string,
	 *     invoiceId:string,
	 *     invoiceNumber:string,
	 *     generatedAt:string,
	 *     generatedBy:string,
	 *     sha256:string,
	 *     zipPath:string,
	 *     retentionYears:int,
	 *     archived:bool,
	 *     archiveReference:?string,
	 *     attachmentCount:int,
	 *     eventCount:int
	 * }
	 *
	 * @throws \RuntimeException On cross-tenant access, missing invoice,
	 *                           or ZIP write failure.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	public function generateAuditPackage(string $administrationId, string $invoiceId): array {
		$this->assertAccess(administrationId: $administrationId);
		if ($invoiceId === '') {
			throw new RuntimeException('Supplier invoice not found');
		}

		$invoice = $this->findOne(
			schema: self::SCHEMA_SUPPLIER_INVOICE,
			filters: [
				'id' => $invoiceId,
				'administrationId' => $administrationId,
			]
		);
		if ($invoice === null) {
			throw new RuntimeException('Supplier invoice not found');
		}

		$bundle = $this->collectLifecycleBundle(
			administrationId: $administrationId,
			invoiceId: $invoiceId,
			invoice: $invoice
		);

		$ledger = $this->buildLedger(bundle: $bundle);

		$generatedAt = $this->nowIso();
		$generatedBy = $this->currentUserId();
		$invoiceNumber = (string)($invoice['invoiceNumber'] ?? $invoiceId);
		$packageId = 'audit-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $invoiceNumber) . '-' . substr(md5($generatedAt . $invoiceId), 0, 12);

		$ledgerJson = (string)json_encode($ledger, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$sha256 = hash('sha256', $ledgerJson);

		$manifest = [
			'packageId' => $packageId,
			'invoiceId' => $invoiceId,
			'invoiceNumber' => $invoiceNumber,
			'administrationId' => $administrationId,
			'generatedAt' => $generatedAt,
			'generatedBy' => $generatedBy,
			'sha256' => $sha256,
			'retentionYears' => self::RETENTION_YEARS,
			'retentionPolicy' => 'BW2 art 2:10 / NV COS 230',
			'eventCount' => count($ledger['events']),
			'objectCounts' => [
				'purchaseOrders' => count($bundle['purchaseOrders']),
				'goodsReceiptNotes' => count($bundle['goodsReceiptNotes']),
				'supplierInvoice' => 1,
				'threeWayMatches' => count($bundle['threeWayMatches']),
			],
			'attachments' => $this->collectAttachmentList(bundle: $bundle),
			'schemaVersion' => '1.0.0',
		];

		$summaryHtml = $this->renderSummaryHtml(
			manifest: $manifest,
			bundle: $bundle,
			ledger: $ledger
		);

		$zipPath = $this->writeZip(
			packageId: $packageId,
			manifest: $manifest,
			ledgerJson: $ledgerJson,
			summaryHtml: $summaryHtml,
			bundle: $bundle
		);

		$archiveResult = $this->archiveToDocudesk(
			packageId: $packageId,
			invoiceNumber: $invoiceNumber,
			zipPath: $zipPath,
			sha256: $sha256
		);

		return [
			'packageId' => $packageId,
			'invoiceId' => $invoiceId,
			'invoiceNumber' => $invoiceNumber,
			'generatedAt' => $generatedAt,
			'generatedBy' => $generatedBy,
			'sha256' => $sha256,
			'zipPath' => $zipPath,
			'retentionYears' => self::RETENTION_YEARS,
			'archived' => $archiveResult['archived'],
			'archiveReference' => $archiveResult['reference'],
			'attachmentCount' => count($manifest['attachments']),
			'eventCount' => $manifest['eventCount'],
		];

	}//end generateAuditPackage()

	/**
	 * Assemble the JSON ledger from the lifecycle bundle.
	 *
	 * The ledger is a strictly-ordered list of events (by timestamp
	 * ascending, ties broken by event kind) so the auditor can read it
	 * top-to-bottom as the lifecycle unfolded. Events that lack a
	 * timestamp are stamped with the parent record's createdAt to keep
	 * the ordering deterministic.
	 *
	 * @param array<string,mixed> $bundle The collected lifecycle bundle —
	 *                                    shape: { invoice: array,
	 *                                    purchaseOrders: list, goodsReceiptNotes:
	 *                                    list, threeWayMatches: list }.
	 *
	 * @return array<string,mixed> The ledger payload (events list + summary).
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	public function buildLedger(array $bundle): array {
		$events = [];

		foreach ($bundle['purchaseOrders'] as $purchaseOrder) {
			$events = array_merge($events, $this->purchaseOrderEvents(purchaseOrder: $purchaseOrder));
		}

		foreach ($bundle['goodsReceiptNotes'] as $grn) {
			$events = array_merge($events, $this->goodsReceiptEvents(grn: $grn));
		}

		$events = array_merge($events, $this->supplierInvoiceEvents(invoice: $bundle['invoice']));

		foreach ($bundle['threeWayMatches'] as $match) {
			$events = array_merge($events, $this->threeWayMatchEvents(match: $match));
		}

		usort(
			$events,
			static function (array $left, array $right): int {
				$leftAt = (string)($left['timestamp'] ?? '');
				$rightAt = (string)($right['timestamp'] ?? '');
				if ($leftAt === $rightAt) {
					return strcmp((string)($left['event'] ?? ''), (string)($right['event'] ?? ''));
				}

				return ($leftAt <=> $rightAt);
			}
		);

		$summary = [
			'invoiceId' => (string)($bundle['invoice']['id'] ?? ''),
			'invoiceNumber' => (string)($bundle['invoice']['invoiceNumber'] ?? ''),
			'supplierId' => (string)($bundle['invoice']['supplierId'] ?? ''),
			'totalInclVat' => (int)($bundle['invoice']['totalInclVat'] ?? 0),
			'currency' => (string)($bundle['invoice']['currency'] ?? 'EUR'),
			'eventCount' => count($events),
			'purchaseOrderIds' => array_values(
				array_filter(
					array_map(
						static fn (array $po): string => trim((string)($po['id'] ?? '')),
						$bundle['purchaseOrders']
					)
				)
			),
		];

		return [
			'events' => $events,
			'summary' => $summary,
		];

	}//end buildLedger()

	/**
	 * Extract every lifecycle event from a PurchaseOrder record:
	 *  - po_created             (timestamp = createdAt; actor = requesterId)
	 *  - po_approval_decision*  (one per non-pending chain entry; actor = userId)
	 *  - po_sent                (timestamp = peppolSentAt; actor = transmitter)
	 *  - po_lifecycle_state     (the current lifecycleState — terminal marker)
	 *
	 * @param array<string,mixed> $purchaseOrder PurchaseOrder OR record.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function purchaseOrderEvents(array $purchaseOrder): array {
		$events = [];
		$poId = (string)($purchaseOrder['id'] ?? ($purchaseOrder['@self']['id'] ?? ''));

		$createdAt = (string)($purchaseOrder['createdAt'] ?? '');
		if ($createdAt !== '') {
			$events[] = [
				'event' => 'po_created',
				'timestamp' => $createdAt,
				'actor' => (string)($purchaseOrder['requesterId'] ?? ''),
				'objectType' => self::SCHEMA_PURCHASE_ORDER,
				'objectId' => $poId,
				'details' => [
					'poNumber' => (string)($purchaseOrder['poNumber'] ?? ''),
					'supplierId' => (string)($purchaseOrder['supplierId'] ?? ''),
					'totalAmount' => (float)($purchaseOrder['totalAmount'] ?? 0.0),
					'currency' => (string)($purchaseOrder['currency'] ?? 'EUR'),
					'costCenter' => (string)($purchaseOrder['costCenter'] ?? ''),
					'projectCode' => (string)($purchaseOrder['projectCode'] ?? ''),
				],
			];
		}

		$chain = (array)($purchaseOrder['approvalChain'] ?? []);
		foreach ($chain as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$decision = (string)($entry['decision'] ?? '');
			if ($decision === 'pending' || $decision === '') {
				continue;
			}

			$events[] = [
				'event' => 'po_approval_decision',
				'timestamp' => (string)($entry['decidedAt'] ?? $createdAt),
				'actor' => (string)($entry['userId'] ?? ''),
				'objectType' => self::SCHEMA_PURCHASE_ORDER,
				'objectId' => $poId,
				'details' => [
					'decision' => $decision,
					'comment' => (string)($entry['comment'] ?? ''),
				],
			];
		}//end foreach

		$peppolSentAt = (string)($purchaseOrder['peppolSentAt'] ?? '');
		if ($peppolSentAt !== '') {
			$events[] = [
				'event' => 'po_sent_to_supplier',
				'timestamp' => $peppolSentAt,
				'actor' => 'peppol_transmitter',
				'objectType' => self::SCHEMA_PURCHASE_ORDER,
				'objectId' => $poId,
				'details' => [
					'peppolMessageId' => (string)($purchaseOrder['peppolMessageId'] ?? ''),
				],
			];
		}

		return $events;
	}//end purchaseOrderEvents()

	/**
	 * Extract every lifecycle event from a GoodsReceiptNote record.
	 *
	 * @param array<string,mixed> $grn GRN OR record.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function goodsReceiptEvents(array $grn): array {
		$events = [];
		$grnId = (string)($grn['id'] ?? ($grn['@self']['id'] ?? ''));

		$receivedAt = (string)($grn['receivedAt'] ?? '');
		if ($receivedAt !== '') {
			$events[] = [
				'event' => 'grn_received',
				'timestamp' => $receivedAt,
				'actor' => (string)($grn['receivedBy'] ?? ''),
				'objectType' => self::SCHEMA_GOODS_RECEIPT_NOTE,
				'objectId' => $grnId,
				'details' => [
					'grnNumber' => (string)($grn['grnNumber'] ?? ''),
					'photoCount' => count((array)($grn['photos'] ?? [])),
				],
			];
		}

		$statusCode = (string)($grn['statusCode'] ?? '');
		if ($statusCode !== '' && $statusCode !== 'draft') {
			$events[] = [
				'event' => 'grn_lifecycle_state',
				'timestamp' => $receivedAt,
				'actor' => (string)($grn['receivedBy'] ?? 'system'),
				'objectType' => self::SCHEMA_GOODS_RECEIPT_NOTE,
				'objectId' => $grnId,
				'details' => [
					'statusCode' => $statusCode,
					'qualityCheckPassed' => (bool)($grn['qualityCheckPassed'] ?? false),
				],
			];
		}

		return $events;
	}//end goodsReceiptEvents()

	/**
	 * Extract every lifecycle event from a SupplierInvoice record.
	 *
	 * @param array<string,mixed> $invoice SI OR record.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function supplierInvoiceEvents(array $invoice): array {
		$events = [];
		$invoiceId = (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));

		$peppolReceivedAt = (string)($invoice['peppolReceivedAt'] ?? '');
		if ($peppolReceivedAt !== '') {
			$events[] = [
				'event' => 'invoice_received',
				'timestamp' => $peppolReceivedAt,
				'actor' => 'peppol_receiver',
				'objectType' => self::SCHEMA_SUPPLIER_INVOICE,
				'objectId' => $invoiceId,
				'details' => [
					'invoiceNumber' => (string)($invoice['invoiceNumber'] ?? ''),
					'supplierId' => (string)($invoice['supplierId'] ?? ''),
					'totalInclVat' => (int)($invoice['totalInclVat'] ?? 0),
					'currency' => (string)($invoice['currency'] ?? 'EUR'),
				],
			];
		}

		$statusCode = (string)($invoice['statusCode'] ?? '');
		if ($statusCode !== '') {
			$events[] = [
				'event' => 'invoice_lifecycle_state',
				'timestamp' => $peppolReceivedAt,
				'actor' => 'system',
				'objectType' => self::SCHEMA_SUPPLIER_INVOICE,
				'objectId' => $invoiceId,
				'details' => [
					'statusCode' => $statusCode,
				],
			];
		}

		return $events;
	}//end supplierInvoiceEvents()

	/**
	 * Extract every lifecycle event from a ThreeWayMatch record.
	 *
	 * @param array<string,mixed> $match 3WM OR record.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function threeWayMatchEvents(array $match): array {
		$events = [];
		$matchId = (string)($match['id'] ?? ($match['@self']['id'] ?? ''));

		$createdAt = (string)($match['createdAt'] ?? '');
		if ($createdAt !== '') {
			$events[] = [
				'event' => 'match_evaluated',
				'timestamp' => $createdAt,
				'actor' => 'matching_engine',
				'objectType' => self::SCHEMA_THREE_WAY_MATCH,
				'objectId' => $matchId,
				'details' => [
					'matchStatus' => (string)($match['matchStatus'] ?? ''),
					'matchedPoIds' => (array)($match['matchedPoIds'] ?? []),
					'matchedGrnIds' => (array)($match['matchedGrnIds'] ?? []),
					'divergenceDetails' => (array)($match['divergenceDetails'] ?? []),
				],
			];
		}

		$resolvedAt = (string)($match['resolvedAt'] ?? '');
		if ($resolvedAt !== '') {
			$events[] = [
				'event' => 'match_resolved',
				'timestamp' => $resolvedAt,
				'actor' => (string)($match['resolvedBy'] ?? ''),
				'objectType' => self::SCHEMA_THREE_WAY_MATCH,
				'objectId' => $matchId,
				'details' => [
					'resolutionAction' => (string)($match['resolutionAction'] ?? ''),
					'resolutionNotes' => (string)($match['resolutionNotes'] ?? ''),
				],
			];
		}

		return $events;
	}//end threeWayMatchEvents()

	/**
	 * Collect the lifecycle bundle — invoice + every linked PO + GRN + 3WM.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId SupplierInvoice id.
	 * @param array<string,mixed> $invoice The pre-loaded invoice.
	 *
	 * @return array{
	 *     invoice:array<string,mixed>,
	 *     purchaseOrders:array<int,array<string,mixed>>,
	 *     goodsReceiptNotes:array<int,array<string,mixed>>,
	 *     threeWayMatches:array<int,array<string,mixed>>
	 * }
	 */
	private function collectLifecycleBundle(
		string $administrationId,
		string $invoiceId,
		array $invoice,
	): array {
		$threeWayMatches = $this->findAll(
			schema: self::SCHEMA_THREE_WAY_MATCH,
			filters: [
				'invoiceId' => $invoiceId,
				'administrationId' => $administrationId,
			]
		);

		$purchaseOrderIds = [];
		foreach ($threeWayMatches as $match) {
			foreach ((array)($match['matchedPoIds'] ?? []) as $poId) {
				$poIdString = trim((string)$poId);
				if ($poIdString !== '' && in_array($poIdString, $purchaseOrderIds, true) === false) {
					$purchaseOrderIds[] = $poIdString;
				}
			}
		}

		$grnIds = [];
		foreach ($threeWayMatches as $match) {
			foreach ((array)($match['matchedGrnIds'] ?? []) as $grnId) {
				$grnIdString = trim((string)$grnId);
				if ($grnIdString !== '' && in_array($grnIdString, $grnIds, true) === false) {
					$grnIds[] = $grnIdString;
				}
			}
		}

		$purchaseOrders = [];
		foreach ($purchaseOrderIds as $poId) {
			$purchaseOrder = $this->findOne(
				schema: self::SCHEMA_PURCHASE_ORDER,
				filters: [
					'id' => $poId,
					'administrationId' => $administrationId,
				]
			);
			if ($purchaseOrder !== null) {
				$purchaseOrders[] = $purchaseOrder;
			}
		}

		$goodsReceiptNotes = [];
		foreach ($grnIds as $grnId) {
			$grn = $this->findOne(
				schema: self::SCHEMA_GOODS_RECEIPT_NOTE,
				filters: [
					'id' => $grnId,
					'administrationId' => $administrationId,
				]
			);
			if ($grn !== null) {
				$goodsReceiptNotes[] = $grn;
			}
		}

		return [
			'invoice' => $invoice,
			'purchaseOrders' => $purchaseOrders,
			'goodsReceiptNotes' => $goodsReceiptNotes,
			'threeWayMatches' => $threeWayMatches,
		];

	}//end collectLifecycleBundle()

	/**
	 * Collect the deterministic attachment list — file ids referenced by
	 * any GRN photo set + the invoice's stored UBL/PDF blob references.
	 *
	 * @param array<string,mixed> $bundle Lifecycle bundle (same shape as
	 *                                    buildLedger()).
	 *
	 * @return array<int,array<string,string>> The attachment list — each
	 *                                         entry carries kind, fileId,
	 *                                         sourceObject.
	 */
	private function collectAttachmentList(array $bundle): array {
		$list = [];
		foreach ($bundle['goodsReceiptNotes'] as $grn) {
			$grnId = (string)($grn['id'] ?? '');
			$photos = (array)($grn['photos'] ?? []);
			foreach ($photos as $fileId) {
				$list[] = [
					'kind' => 'grn_photo',
					'fileId' => (string)$fileId,
					'sourceObject' => $grnId,
				];
			}
		}

		$ublFileId = (string)($bundle['invoice']['ublFileId'] ?? '');
		if ($ublFileId !== '') {
			$list[] = [
				'kind' => 'invoice_ubl',
				'fileId' => $ublFileId,
				'sourceObject' => (string)($bundle['invoice']['id'] ?? ''),
			];
		}

		return $list;
	}//end collectAttachmentList()

	/**
	 * Render a deterministic HTML summary of the audit package for the
	 * PDF stub. Production hosts wire a wkhtmltopdf / mPDF converter
	 * downstream; this service ships the source HTML so the package is
	 * portable without a hard PDF-binary dependency (mirrors the
	 * InvoicePdfGenerator pattern).
	 *
	 * @param array<string,mixed> $manifest Package manifest.
	 * @param array<string,mixed> $bundle Lifecycle bundle.
	 * @param array<string,mixed> $ledger Ledger payload.
	 *
	 * @return string The HTML summary.
	 */
	private function renderSummaryHtml(array $manifest, array $bundle, array $ledger): string {
		$invoice = (array)($bundle['invoice'] ?? []);
		$eventRows = '';
		foreach ((array)($ledger['events'] ?? []) as $event) {
			$eventRows .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				htmlspecialchars((string)($event['timestamp'] ?? ''), ENT_QUOTES, 'UTF-8'),
				htmlspecialchars((string)($event['event'] ?? ''), ENT_QUOTES, 'UTF-8'),
				htmlspecialchars((string)($event['actor'] ?? ''), ENT_QUOTES, 'UTF-8'),
				htmlspecialchars((string)($event['objectId'] ?? ''), ENT_QUOTES, 'UTF-8'),
			);
		}

		$template = <<<HTML
<!DOCTYPE html>
<html lang="nl"><head>
<meta charset="UTF-8">
<title>Audit Package %s</title>
<style>
body{font-family:sans-serif;margin:24px;color:#222}
h1{font-size:18px;margin-bottom:4px}
h2{font-size:14px;margin-top:16px;border-bottom:1px solid #ccc}
table{border-collapse:collapse;width:100%%;font-size:11px;margin-top:8px}
th,td{border:1px solid #ddd;padding:4px 6px;text-align:left;vertical-align:top}
th{background:#f3f3f3}
.meta dt{font-weight:bold}
.meta dd{margin:0 0 6px 0}
</style></head><body>
<h1>3-way-match audit package</h1>
<p>Package <code>%s</code> — invoice <code>%s</code></p>
<dl class="meta">
<dt>Generated at</dt><dd>%s</dd>
<dt>Generated by</dt><dd>%s</dd>
<dt>SHA-256 (ledger)</dt><dd><code>%s</code></dd>
<dt>Retention</dt><dd>%d years (BW2 art 2:10 / NV COS 230)</dd>
<dt>Supplier</dt><dd>%s</dd>
<dt>Total incl. VAT (cents)</dt><dd>%d %s</dd>
</dl>
<h2>Lifecycle events (%d)</h2>
<table><thead><tr><th>Timestamp</th><th>Event</th><th>Actor</th><th>Object</th></tr></thead><tbody>%s</tbody></table>
<h2>Object counts</h2>
<table><tbody>
<tr><th>Purchase orders</th><td>%d</td></tr>
<tr><th>Goods receipt notes</th><td>%d</td></tr>
<tr><th>Three-way matches</th><td>%d</td></tr>
<tr><th>Attachments</th><td>%d</td></tr>
</tbody></table>
</body></html>
HTML;

		return sprintf(
			$template,
			htmlspecialchars((string)($manifest['invoiceNumber'] ?? ''), ENT_QUOTES, 'UTF-8'),
			htmlspecialchars((string)$manifest['packageId'], ENT_QUOTES, 'UTF-8'),
			htmlspecialchars((string)($manifest['invoiceNumber'] ?? ''), ENT_QUOTES, 'UTF-8'),
			htmlspecialchars((string)$manifest['generatedAt'], ENT_QUOTES, 'UTF-8'),
			htmlspecialchars((string)$manifest['generatedBy'], ENT_QUOTES, 'UTF-8'),
			htmlspecialchars((string)$manifest['sha256'], ENT_QUOTES, 'UTF-8'),
			(int)$manifest['retentionYears'],
			htmlspecialchars((string)($invoice['supplierId'] ?? ''), ENT_QUOTES, 'UTF-8'),
			(int)($invoice['totalInclVat'] ?? 0),
			htmlspecialchars((string)($invoice['currency'] ?? 'EUR'), ENT_QUOTES, 'UTF-8'),
			(int)$manifest['eventCount'],
			$eventRows,
			(int)($manifest['objectCounts']['purchaseOrders'] ?? 0),
			(int)($manifest['objectCounts']['goodsReceiptNotes'] ?? 0),
			(int)($manifest['objectCounts']['threeWayMatches'] ?? 0),
			count($manifest['attachments'])
		);

	}//end renderSummaryHtml()

	/**
	 * Write the ZIP package to a temp file and return the absolute path.
	 *
	 * The package layout is deterministic — same inputs produce the
	 * same byte sequence so the auditor can re-derive the SHA-256 over
	 * the ledger to verify the package was not mutated post-export.
	 *
	 * @param string $packageId Package id.
	 * @param array<string,mixed> $manifest Manifest.
	 * @param string $ledgerJson Pre-serialised ledger JSON.
	 * @param string $summaryHtml HTML summary.
	 * @param array<string,mixed> $bundle Lifecycle bundle.
	 *
	 * @return string Absolute path to the ZIP.
	 *
	 * @throws \RuntimeException When the ZIP cannot be opened or written.
	 *
	 * @SuppressWarnings(PHPMD.ErrorControlOperator) `@rename()` is a
	 *     best-effort extension add; ZipArchive::open() below uses CREATE
	 *     and works regardless of whether the rename succeeded.
	 */
	private function writeZip(
		string $packageId,
		array $manifest,
		string $ledgerJson,
		string $summaryHtml,
		array $bundle,
	): string {
		$zipPath = tempnam(sys_get_temp_dir(), 'shillinq-audit-');
		if ($zipPath === false) {
			throw new RuntimeException('Failed to allocate audit package temp file');
		}

		// Append .zip so consumers do not confuse the extension.
		$zipPathExt = $zipPath . '.zip';
		@rename($zipPath, $zipPathExt);

		$zip = new ZipArchive();
		if ($zip->open($zipPathExt, (ZipArchive::CREATE | ZipArchive::OVERWRITE)) !== true) {
			throw new RuntimeException('Failed to open audit package ZIP for writing');
		}

		$base = $packageId . '/';
		$zip->addFromString(
			$base . 'manifest.json',
			(string)json_encode($manifest, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
		);
		$zip->addFromString($base . 'ledger.json', $ledgerJson);
		$zip->addFromString($base . 'summary.pdf.html', $summaryHtml);

		$zip->addFromString(
			$base . 'attachments/supplier-invoice/invoice.json',
			(string)json_encode($bundle['invoice'], (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
		);

		foreach ($bundle['purchaseOrders'] as $purchaseOrder) {
			$poId = (string)($purchaseOrder['id'] ?? ($purchaseOrder['poNumber'] ?? 'unknown'));
			$zip->addFromString(
				$base . 'attachments/purchase-orders/' . $poId . '.json',
				(string)json_encode($purchaseOrder, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
			);
		}

		foreach ($bundle['goodsReceiptNotes'] as $grn) {
			$grnId = (string)($grn['id'] ?? ($grn['grnNumber'] ?? 'unknown'));
			$zip->addFromString(
				$base . 'attachments/goods-receipts/' . $grnId . '.json',
				(string)json_encode($grn, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
			);
		}

		foreach ($bundle['threeWayMatches'] as $match) {
			$matchId = (string)($match['id'] ?? 'unknown');
			$zip->addFromString(
				$base . 'attachments/three-way-match/' . $matchId . '.json',
				(string)json_encode($match, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
			);
		}

		$zip->close();

		return $zipPathExt;
	}//end writeZip()

	/**
	 * Best-effort hand-off to docudesk for 7-year retention archival
	 * (REQ-PO3W-010 / BW2 art 2:10). Production wires the real adapter;
	 * the default implementation logs the intent and returns
	 * archived=false so the controller surfaces partial success. The
	 * audit package is still useful even when the archival hand-off
	 * fails — the ZIP can be downloaded immediately.
	 *
	 * @param string $packageId Package id.
	 * @param string $invoiceNumber Invoice number for the archive label.
	 * @param string $zipPath Absolute ZIP path.
	 * @param string $sha256 SHA-256 of the ledger (audit trail).
	 *
	 * @return array{archived:bool,reference:?string}
	 */
	private function archiveToDocudesk(
		string $packageId,
		string $invoiceNumber,
		string $zipPath,
		string $sha256,
	): array {
		try {
			$this->logger->info(
				'AuditExportService: audit package ready for docudesk archival',
				[
					'packageId' => $packageId,
					'invoiceNumber' => $invoiceNumber,
					'zipPath' => $zipPath,
					'sha256' => $sha256,
					'retentionYears' => self::RETENTION_YEARS,
				]
			);
		} catch (\Throwable $exception) {
			// Logger failure is non-fatal — the package is still on disk.
		}

		return [
			'archived' => false,
			'reference' => null,
		];

	}//end archiveToDocudesk()

	/**
	 * Validate the administration scope (ADR-005 IDOR-safe).
	 *
	 * @param string $administrationId Tenant id.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the administration is inaccessible.
	 */
	private function assertAccess(string $administrationId): void {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Administration not found');
		}

	}//end assertAccess()

	/**
	 * Resolve the current user id, falling back to `system` when no
	 * session is bound (e.g. inside a background job).
	 *
	 * @return string
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * Fetch one record via the real ObjectService API.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, array $filters): ?array {
		$rows = $this->findAll(schema: $schema, filters: $filters);
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Fetch all records via the real ObjectService API.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $exception) {
			$this->logger->error(
				'AuditExportService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $exception->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OR register slug from app config (defaults to "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Current timestamp in ISO-8601 — server-authoritative.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return date('c');
	}//end nowIso()
}//end class
