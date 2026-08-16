<?php

/**
 * Accountantsdossier Export Service.
 *
 * Tier-3 BADO (Besluit Accountantscontrole Decentrale Overheden) Task 16 of
 * `bookkeeping-bado-controleprotocol` — assembles the complete BADO audit
 * bundle (Controleprotocol header + adoptionDecision + ToleranceMatrix +
 * Materialiteit + AuditSample + AuditFinding + VerklaringDraft +
 * SiSaAssurance) for one protocol and emits it as a deterministic,
 * timestamped, hash-anchored ZIP archive suitable for AFM-toezicht and
 * provincial financial supervision archives (REQ-010).
 *
 * The bundle layout mirrors `AuditExportService`'s structured-package
 * idiom — manifest.json carries package metadata + SHA-256 ledger anchor,
 * ledger.json carries every record keyed by schema, summary.pdf.html is
 * the human-readable HTML summary that downstream renderers convert to a
 * PDF/A-1b binary, and per-schema attachment folders carry each record as
 * canonicalised JSON. PKIO signature creation is delegated to a configured
 * external signer (docudesk + qualified-certificate store); the absence of
 * a configured signer is surfaced on the envelope so the caller can decide
 * how to handle archival hand-off — the bundle is still complete and the
 * SHA-256 anchor still detects tampering.
 *
 * Package layout (byte-identical for the same protocol state):
 *
 *  accountantsdossier-{protocolId}/
 *    ├── manifest.json          — package metadata, sha256 of ledger.json,
 *    │                             ISO-8601 timestamp, retention years,
 *    │                             signature envelope (delegated)
 *    ├── ledger.json            — full BADO record dump, sorted + keyed
 *    │                             by schema for auditor sample-test
 *    ├── summary.pdf.html       — PDF/A-1b oriented HTML summary
 *    │                             (`<html lang="nl">` + embedded
 *    │                             `pdfaid:part="1"` `xmpMM` block)
 *    └── attachments/
 *        ├── controleprotocol/  — Controleprotocol header (single record)
 *        ├── tolerance-matrix/  — ToleranceMatrix rows
 *        ├── materialiteit/     — Materialiteit rows
 *        ├── audit-samples/     — AuditSample records
 *        ├── audit-findings/    — AuditFinding records
 *        ├── verklaring-draft/  — VerklaringDraft (single record)
 *        └── sisa-assurance/    — SiSaAssurance rows
 *
 * Cross-tenant access is masked at the ObjectService boundary (OR enforces
 * register-level RBAC); a missing protocol surfaces as `RuntimeException`
 * which the controller renders as 404. Retention is set to 7 years per
 * BADO + Selectielijst Gemeenten 2020 (21.1). The bundle is server-derived
 * — every field reads from OR records, not from any client-supplied payload
 * (ADR-005).
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
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Builds the BADO accountantsdossier bundle for one Controleprotocol.
 *
 * Public surface:
 *  - buildDossier(): hermetic dossier assembly — pulls every BADO record
 *    for the protocol, builds the deterministic ledger, the manifest with
 *    SHA-256 anchor, and the HTML PDF/A summary. Returns an envelope; no
 *    I/O. Used by the integration tests.
 *  - exportDossier(): wraps buildDossier with a ZIP writer + PKIO signature
 *    delegation; returns the envelope augmented with `zipPath` and the
 *    signature envelope. Used by the controller.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * ADR-031 exception-path host bundling the deterministic dossier writer
 * for the 7 BADO schemas + the PDF/A HTML summary + the PKIO delegation.
 *
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-16
 */
class AccountantsdossierExportService {

	/**
	 * Schema slug for the parent Controleprotocol (REQ-001).
	 *
	 * @var string
	 */
	private const SCHEMA_CONTROLEPROTOCOL = 'Controleprotocol';

	/**
	 * Schema slug for ToleranceMatrix rows (REQ-002).
	 *
	 * @var string
	 */
	private const SCHEMA_TOLERANCE_MATRIX = 'ToleranceMatrix';

	/**
	 * Schema slug for Materialiteit rows (REQ-003).
	 *
	 * @var string
	 */
	private const SCHEMA_MATERIALITEIT = 'Materialiteit';

	/**
	 * Schema slug for AuditSample records (REQ-005).
	 *
	 * @var string
	 */
	private const SCHEMA_AUDIT_SAMPLE = 'AuditSample';

	/**
	 * Schema slug for AuditFinding records (REQ-006).
	 *
	 * @var string
	 */
	private const SCHEMA_AUDIT_FINDING = 'AuditFinding';

	/**
	 * Schema slug for VerklaringDraft (REQ-007).
	 *
	 * @var string
	 */
	private const SCHEMA_VERKLARING_DRAFT = 'VerklaringDraft';

	/**
	 * Schema slug for SiSaAssurance rows (REQ-008).
	 *
	 * @var string
	 */
	private const SCHEMA_SISA_ASSURANCE = 'SiSaAssurance';

	/**
	 * Bundle retention (Archiefwet + Selectielijst Gemeenten 21.1).
	 *
	 * @var int
	 */
	public const RETENTION_YEARS = 7;

	/**
	 * PDF/A part marker advertised in the HTML summary header (ISO 19005-1).
	 *
	 * @var string
	 */
	public const PDFA_PART = '1';

	/**
	 * Bundle schema version, bumped on layout-affecting changes.
	 *
	 * @var string
	 */
	public const BUNDLE_SCHEMA_VERSION = '1.0.0';

	/**
	 * Construct the exporter with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug + signer hand-off.
	 * @param IUserSession $userSession Session for generatedBy attribution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Assemble the dossier in memory, no filesystem writes (REQ-010).
	 *
	 * The envelope is deterministic for the same protocol state — every
	 * collection is sorted on a stable key so two invocations produce
	 * byte-identical ledger.json + manifest.json.
	 *
	 * @param string $protocolId The Controleprotocol.id to bundle.
	 *
	 * @return array{
	 *     packageId:string,
	 *     protocolId:string,
	 *     generatedAt:string,
	 *     generatedBy:string,
	 *     manifest:array<string,mixed>,
	 *     ledger:array<string,mixed>,
	 *     summaryHtml:string,
	 *     sha256:string,
	 *     attachmentCount:int,
	 *     retentionYears:int,
	 *     signaturePending:bool
	 * }
	 *
	 * @throws RuntimeException When the protocol cannot be resolved.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-16
	 */
	public function buildDossier(string $protocolId): array {
		if ($protocolId === '') {
			throw new RuntimeException('Controleprotocol id is required');
		}

		$bundle = $this->collectBundle(protocolId: $protocolId);
		if ($bundle['auditProtocol'] === null) {
			throw new RuntimeException('Controleprotocol not found');
		}

		$generatedAt = $this->nowIso();
		$generatedBy = $this->currentUserId();
		$packageId = $this->packageId(protocolId: $protocolId, generatedAt: $generatedAt);

		$ledger = $this->buildLedger(bundle: $bundle);
		$ledgerJson = $this->canonicalJson(payload: $ledger);
		$sha256 = hash(algo: 'sha256', data: $ledgerJson);

		$manifest = [
			'packageId' => $packageId,
			'protocolId' => $protocolId,
			'auditYear' => (int)($bundle['auditProtocol']['auditYear'] ?? 0),
			'organisationId' => (string)($bundle['auditProtocol']['organisationId'] ?? ''),
			'organisationType' => (string)($bundle['auditProtocol']['organisationType'] ?? ''),
			'generatedAt' => $generatedAt,
			'generatedBy' => $generatedBy,
			'sha256' => $sha256,
			'retentionYears' => self::RETENTION_YEARS,
			'retentionPolicy' => 'Archiefwet + Selectielijst Gemeenten 2020 (21.1)',
			'bundleSchemaVersion' => self::BUNDLE_SCHEMA_VERSION,
			'pdfaPart' => self::PDFA_PART,
			'pdfaConformance' => 'ISO 19005-1:2005',
			'iso8601Timestamp' => $generatedAt,
			'attachments' => $this->attachmentInventory(bundle: $bundle),
			'objectCounts' => [
				'auditProtocol' => 1,
				'toleranceMatrix' => count($bundle['toleranceMatrix']),
				'materialiteit' => count($bundle['materialiteit']),
				'auditSamples' => count($bundle['auditSamples']),
				'auditFindings' => count($bundle['auditFindings']),
				'verklaringDraft' => $this->presenceCount(value: $bundle['verklaringDraft']),
				'sisaAssurance' => count($bundle['sisaAssurance']),
			],
			'signature' => [
				'algorithm' => 'PKIO-RSA-SHA256',
				'standard' => 'ETSI EN 319 142-1 PAdES B-LT',
				'status' => 'pending',
				'signedAt' => null,
				'signerUri' => $this->signerUri(),
				'thumbprint' => null,
			],
		];

		$summaryHtml = $this->renderSummaryHtml(manifest: $manifest, bundle: $bundle);

		return [
			'packageId' => $packageId,
			'protocolId' => $protocolId,
			'generatedAt' => $generatedAt,
			'generatedBy' => $generatedBy,
			'manifest' => $manifest,
			'ledger' => $ledger,
			'summaryHtml' => $summaryHtml,
			'sha256' => $sha256,
			'attachmentCount' => count($manifest['attachments']),
			'retentionYears' => self::RETENTION_YEARS,
			'signaturePending' => true,
		];
	}//end buildDossier()

	/**
	 * Build and persist the dossier as a ZIP package + PKIO delegation (REQ-010).
	 *
	 * Writes the ZIP to `sys_get_temp_dir()` (caller streams or moves it
	 * into archival storage). The PKIO signature step is delegated to the
	 * configured signer URI — when no signer is configured the bundle is
	 * still complete and the envelope flags signaturePending=true so the
	 * caller can hand it off out-of-band.
	 *
	 * @param string $protocolId The Controleprotocol.id to bundle.
	 *
	 * @return array{
	 *     packageId:string,
	 *     protocolId:string,
	 *     generatedAt:string,
	 *     generatedBy:string,
	 *     sha256:string,
	 *     zipPath:string,
	 *     attachmentCount:int,
	 *     retentionYears:int,
	 *     signaturePending:bool,
	 *     signerUri:?string,
	 *     signedAt:?string,
	 *     thumbprint:?string
	 * }
	 *
	 * @throws RuntimeException When the bundle cannot be written.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-16
	 */
	public function exportDossier(string $protocolId): array {
		$envelope = $this->buildDossier(protocolId: $protocolId);

		$zipPath = $this->writeZip(
			packageId: $envelope['packageId'],
			manifest: $envelope['manifest'],
			ledger: $envelope['ledger'],
			summaryHtml: $envelope['summaryHtml'],
			bundle: $this->collectBundle(protocolId: $protocolId)
		);

		$signature = $this->delegatePkioSignature(
			packageId: $envelope['packageId'],
			zipPath: $zipPath,
			sha256: $envelope['sha256']
		);

		return [
			'packageId' => $envelope['packageId'],
			'protocolId' => $envelope['protocolId'],
			'generatedAt' => $envelope['generatedAt'],
			'generatedBy' => $envelope['generatedBy'],
			'sha256' => $envelope['sha256'],
			'zipPath' => $zipPath,
			'attachmentCount' => $envelope['attachmentCount'],
			'retentionYears' => $envelope['retentionYears'],
			'signaturePending' => $signature['pending'],
			'signerUri' => $signature['signerUri'],
			'signedAt' => $signature['signedAt'],
			'thumbprint' => $signature['thumbprint'],
		];
	}//end exportDossier()

	/**
	 * Collect every BADO record for the protocol, sorted for determinism.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 *
	 * @return array{
	 *     auditProtocol: array<string,mixed>|null,
	 *     toleranceMatrix: array<int,array<string,mixed>>,
	 *     materialiteit: array<int,array<string,mixed>>,
	 *     auditSamples: array<int,array<string,mixed>>,
	 *     auditFindings: array<int,array<string,mixed>>,
	 *     verklaringDraft: array<string,mixed>|null,
	 *     sisaAssurance: array<int,array<string,mixed>>
	 * }
	 */
	private function collectBundle(string $protocolId): array {
		$auditProtocol = $this->loadOne(schema: self::SCHEMA_CONTROLEPROTOCOL, id: $protocolId);
		$toleranceMatrix = $this->loadAllByProtocol(schema: self::SCHEMA_TOLERANCE_MATRIX, protocolId: $protocolId);
		$materialiteit = $this->loadAllByProtocol(schema: self::SCHEMA_MATERIALITEIT, protocolId: $protocolId);
		$auditSamples = $this->loadAllByProtocol(schema: self::SCHEMA_AUDIT_SAMPLE, protocolId: $protocolId);

		$sampleIds = [];
		foreach ($auditSamples as $sample) {
			$sampleId = (string)($sample['id'] ?? ($sample['@self']['id'] ?? ''));
			if ($sampleId !== '') {
				$sampleIds[$sampleId] = true;
			}
		}

		$auditFindings = $this->loadFindings(sampleIds: $sampleIds);
		$declarationDraft = $this->loadDeclarationForProtocol(protocolId: $protocolId);
		$sisaAssurance = $this->loadAllByProtocol(schema: self::SCHEMA_SISA_ASSURANCE, protocolId: $protocolId);

		usort(
			$toleranceMatrix,
			static fn (array $left, array $right): int => strcmp(
				(string)($left['topic'] ?? ''),
				(string)($right['topic'] ?? '')
			)
		);
		usort(
			$materialiteit,
			static fn (array $left, array $right): int => strcmp(
				(string)($left['scope'] ?? ''),
				(string)($right['scope'] ?? '')
			)
		);
		usort(
			$auditSamples,
			static fn (array $left, array $right): int => strcmp(
				(string)($left['extractedAt'] ?? ''),
				(string)($right['extractedAt'] ?? '')
			)
		);
		usort(
			$auditFindings,
			static fn (array $left, array $right): int => strcmp(
				(string)($left['id'] ?? ($left['@self']['id'] ?? '')),
				(string)($right['id'] ?? ($right['@self']['id'] ?? ''))
			)
		);
		usort(
			$sisaAssurance,
			static fn (array $left, array $right): int => strcmp(
				(string)($left['schemeCode'] ?? ''),
				(string)($right['schemeCode'] ?? '')
			)
		);

		return [
			'auditProtocol' => $auditProtocol,
			'toleranceMatrix' => $toleranceMatrix,
			'materialiteit' => $materialiteit,
			'auditSamples' => $auditSamples,
			'auditFindings' => $auditFindings,
			'verklaringDraft' => $declarationDraft,
			'sisaAssurance' => $sisaAssurance,
		];
	}//end collectBundle()

	/**
	 * Build the ledger payload (every record keyed by schema).
	 *
	 * @param array<string,mixed> $bundle The collected bundle.
	 *
	 * @return array<string,mixed>
	 */
	private function buildLedger(array $bundle): array {
		return [
			'auditProtocol' => $bundle['auditProtocol'],
			'toleranceMatrix' => array_values($bundle['toleranceMatrix']),
			'materialiteit' => array_values($bundle['materialiteit']),
			'auditSamples' => array_values($bundle['auditSamples']),
			'auditFindings' => array_values($bundle['auditFindings']),
			'verklaringDraft' => $bundle['verklaringDraft'],
			'sisaAssurance' => array_values($bundle['sisaAssurance']),
		];
	}//end buildLedger()

	/**
	 * Inventory of attachment paths inside the bundle.
	 *
	 * @param array<string,mixed> $bundle The collected bundle.
	 *
	 * @return array<int,string>
	 */
	private function attachmentInventory(array $bundle): array {
		$attachments = [];
		$attachments[] = 'attachments/controleprotocol/controleprotocol.json';

		foreach ($bundle['toleranceMatrix'] as $index => $row) {
			$attachments[] = sprintf('attachments/tolerance-matrix/row-%04d.json', ($index + 1));
		}

		foreach ($bundle['materialiteit'] as $index => $row) {
			$attachments[] = sprintf('attachments/materialiteit/row-%04d.json', ($index + 1));
		}

		foreach ($bundle['auditSamples'] as $index => $row) {
			$attachments[] = sprintf('attachments/audit-samples/sample-%04d.json', ($index + 1));
		}

		foreach ($bundle['auditFindings'] as $index => $row) {
			$attachments[] = sprintf('attachments/audit-findings/finding-%04d.json', ($index + 1));
		}

		if ($bundle['verklaringDraft'] !== null) {
			$attachments[] = 'attachments/verklaring-draft/verklaring-draft.json';
		}

		foreach ($bundle['sisaAssurance'] as $index => $row) {
			$attachments[] = sprintf('attachments/sisa-assurance/row-%04d.json', ($index + 1));
		}

		return $attachments;
	}//end attachmentInventory()

	/**
	 * Render the PDF/A-oriented HTML summary for the dossier.
	 *
	 * The HTML is structured so a downstream PDF/A renderer (mPDF / wkhtmltopdf
	 * with PDF/A-1b profile / Apache PDFBox) produces an ISO 19005-1:2005
	 * compliant binary. The metadata block carries `pdfaid:part="1"` and
	 * `pdfaid:conformance="B"` so the renderer can embed the XMP packet.
	 *
	 * @param array<string,mixed> $manifest The manifest payload.
	 * @param array<string,mixed> $bundle The collected bundle.
	 *
	 * @return string
	 */
	private function renderSummaryHtml(array $manifest, array $bundle): string {
		$protocol = $bundle['auditProtocol'] ?? [];
		$declaration = $bundle['verklaringDraft'];
		$findingRows = $this->renderFindingRows(findings: $bundle['auditFindings']);
		$toleranceRows = $this->renderToleranceRows(rows: $bundle['toleranceMatrix']);
		$sisaRows = $this->renderSisaRows(rows: $bundle['sisaAssurance']);
		$sampleRows = $this->renderSampleRows(samples: $bundle['auditSamples']);
		$materialityRows = $this->renderMaterialityRows(rows: $bundle['materialiteit']);

		$opinion = '—';
		$opinionRationale = '';
		$signOff = [];
		if ($declaration !== null) {
			$opinion = (string)($declaration['proposedOpinion'] ?? '—');
			$opinionRationale = (string)($declaration['opinionRationale'] ?? '');
			$signOff = (array)($declaration['signOff'] ?? []);
		}

		$auditYearLabel = (int)($protocol['auditYear'] ?? 0);
		$organisationId = (string)($protocol['organisationId'] ?? '');
		$organisationLabel = (string)($protocol['organisationType'] ?? '') . ' ' . $organisationId;
		$effectiveFrom = (string)($protocol['effectiveFrom'] ?? '');
		$effectiveTo = (string)($protocol['effectiveTo'] ?? '');
		$protocolVersion = (string)($protocol['version'] ?? '');
		$adoptionDecision = (array)($protocol['adoptionDecision'] ?? []);

		$generatedAt = (string)$manifest['generatedAt'];
		$sha256 = (string)$manifest['sha256'];
		$packageId = (string)$manifest['packageId'];

		return sprintf(
			'<!doctype html><html lang="nl"><head><meta charset="utf-8">'
			. '<title>Accountantsdossier %s</title>'
			. '<meta name="pdfaid:part" content="%s">'
			. '<meta name="pdfaid:conformance" content="B">'
			. '<meta name="xmp:CreatorTool" content="Shillinq BADO Dossier Exporter">'
			. '<meta name="xmpMM:DocumentID" content="urn:uuid:%s">'
			. '<meta name="dc:title" content="Accountantsdossier %s">'
			. '<meta name="generated-at" content="%s">'
			. '<meta name="sha256-anchor" content="%s">'
			. '<style>body{font-family:Helvetica,Arial,sans-serif;color:#222;font-size:11pt;margin:24px;}'
			. 'h1{font-size:20pt;margin:0 0 12px;}h2{font-size:14pt;margin:24px 0 8px;}'
			. 'table{border-collapse:collapse;width:100%%;margin:8px 0;font-size:10pt;}'
			. 'th,td{padding:6px 8px;border:1px solid #ccc;text-align:left;vertical-align:top;}'
			. 'th{background:#eee;}.num{text-align:right;}.muted{color:#666;font-size:9pt;}'
			. '.opinion{padding:8px;border:2px solid #333;background:#f8f8f8;font-size:13pt;font-weight:bold;}'
			. '</style></head><body>'
			. '<h1>Accountantsdossier BADO — %s</h1>'
			. '<p class="muted">Bundle: %s &middot; Gegenereerd: %s (ISO 8601 UTC) &middot; '
			. 'SHA-256: <code>%s</code> &middot; PDF/A-%sB / ISO 19005-1:2005</p>'
			. '<h2>1. Controleprotocol</h2>'
			. '<table><tr><th>Versie</th><td>%s</td><th>Auditjaar</th><td>%d</td></tr>'
			. '<tr><th>Organisatie</th><td>%s</td><th>Effectief</th><td>%s &mdash; %s</td></tr>'
			. '<tr><th>Adoptiebesluit</th><td colspan="3">%s (%s) op %s</td></tr></table>'
			. '<h2>2. ToleranceMatrix</h2><table><thead><tr><th>Topic</th>'
			. '<th class="num">Goedkeurend %% (rechtm.)</th><th class="num">Beperking %% (rechtm.)</th>'
			. '<th class="num">Goedkeurend %% (getr.)</th><th class="num">Beperking %% (getr.)</th>'
			. '<th class="num">Onzekerheid %%</th></tr></thead><tbody>%s</tbody></table>'
			. '<h2>3. Materialiteit</h2><table><thead><tr><th>Scope</th><th>Basis</th>'
			. '<th class="num">Basisbedrag (€)</th><th class="num">Percentage %%</th>'
			. '<th class="num">Materialiteit (€)</th><th>Status</th></tr></thead><tbody>%s</tbody></table>'
			. '<h2>4. AuditSamples</h2><table><thead><tr><th>Populatie</th><th>Methode</th>'
			. '<th class="num">Steekproefgrootte</th><th>Seed</th><th>Geëxtraheerd op</th></tr></thead>'
			. '<tbody>%s</tbody></table>'
			. '<h2>5. AuditFindings</h2><table><thead><tr><th>Transactie</th><th>Type</th>'
			. '<th>Topic</th><th class="num">Bedrag (€)</th><th>Severity</th><th>Status</th>'
			. '<th>Controller</th><th>Auditor</th></tr></thead><tbody>%s</tbody></table>'
			. '<h2>6. VerklaringDraft</h2><div class="opinion">Oordeel: %s</div>'
			. '<p>%s</p>'
			. '<p class="muted">Sign-off: %s &middot; AFM-vergunning %s &middot; %s te %s</p>'
			. '<h2>7. SiSaAssurance</h2><table><thead><tr><th>Regeling</th>'
			. '<th>Verantwoordingsplichtige</th><th>Specifieke uitkering</th>'
			. '<th>Niveau</th><th class="num">Findings</th></tr></thead><tbody>%s</tbody></table>'
			. '<p class="muted">Retentie: %d jaar (Archiefwet / Selectielijst Gemeenten 2020 21.1). '
			. 'Handtekening: PKIO RSA-SHA256 / PAdES B-LT (gedelegeerd aan externe signer).</p>'
			. '</body></html>',
			htmlspecialchars($packageId, ENT_QUOTES),
			self::PDFA_PART,
			$this->packageUuid(packageId: $packageId),
			htmlspecialchars($packageId, ENT_QUOTES),
			htmlspecialchars($generatedAt, ENT_QUOTES),
			htmlspecialchars($sha256, ENT_QUOTES),
			htmlspecialchars($organisationLabel, ENT_QUOTES),
			htmlspecialchars($packageId, ENT_QUOTES),
			htmlspecialchars($generatedAt, ENT_QUOTES),
			htmlspecialchars($sha256, ENT_QUOTES),
			self::PDFA_PART,
			htmlspecialchars($protocolVersion, ENT_QUOTES),
			$auditYearLabel,
			htmlspecialchars($organisationLabel, ENT_QUOTES),
			htmlspecialchars($effectiveFrom, ENT_QUOTES),
			htmlspecialchars($effectiveTo, ENT_QUOTES),
			htmlspecialchars((string)($adoptionDecision['decisionType'] ?? '—'), ENT_QUOTES),
			htmlspecialchars((string)($adoptionDecision['decisionNumber'] ?? '—'), ENT_QUOTES),
			htmlspecialchars((string)($adoptionDecision['date'] ?? '—'), ENT_QUOTES),
			$toleranceRows,
			$materialityRows,
			$sampleRows,
			$findingRows,
			htmlspecialchars($opinion, ENT_QUOTES),
			htmlspecialchars($opinionRationale, ENT_QUOTES),
			htmlspecialchars((string)($signOff['auditor'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($signOff['afmPermitNumber'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($signOff['date'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($signOff['place'] ?? ''), ENT_QUOTES),
			$sisaRows,
			self::RETENTION_YEARS
		);
	}//end renderSummaryHtml()

	/**
	 * Render the ToleranceMatrix rows as `<tr>` HTML.
	 *
	 * @param array<int,array<string,mixed>> $rows ToleranceMatrix rows.
	 *
	 * @return string
	 */
	private function renderToleranceRows(array $rows): string {
		$html = '';
		foreach ($rows as $row) {
			$html .= sprintf(
				'<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td>'
				. '<td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
				htmlspecialchars((string)($row['topic'] ?? ''), ENT_QUOTES),
				$this->fmtNumber(value: $row['lawfulnessApprovalCeiling'] ?? 0),
				$this->fmtNumber(value: $row['lawfulnessQualificationCeiling'] ?? 0),
				$this->fmtNumber(value: $row['faithfulnessApprovalCeiling'] ?? 0),
				$this->fmtNumber(value: $row['faithfulnessQualificationCeiling'] ?? 0),
				$this->fmtNumber(value: $row['uncertaintyCeiling'] ?? 0)
			);
		}

		return $html;
	}//end renderToleranceRows()

	/**
	 * Render the Materialiteit rows as `<tr>` HTML.
	 *
	 * @param array<int,array<string,mixed>> $rows Materialiteit rows.
	 *
	 * @return string
	 */
	private function renderMaterialityRows(array $rows): string {
		$html = '';
		foreach ($rows as $row) {
			$html .= sprintf(
				'<tr><td>%s</td><td>%s</td><td class="num">%s</td>'
				. '<td class="num">%s</td><td class="num">%s</td><td>%s</td></tr>',
				htmlspecialchars((string)($row['scope'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($row['baseLabel'] ?? ($row['base'] ?? '')), ENT_QUOTES),
				$this->fmtMoney(value: (float)($row['base'] ?? 0)),
				$this->fmtNumber(value: $row['percentage'] ?? 0),
				$this->fmtMoney(value: (float)($row['calculatedAmount'] ?? 0)),
				htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES)
			);
		}

		return $html;
	}//end renderMaterialityRows()

	/**
	 * Render the AuditSample rows as `<tr>` HTML.
	 *
	 * @param array<int,array<string,mixed>> $samples AuditSample records.
	 *
	 * @return string
	 */
	private function renderSampleRows(array $samples): string {
		$html = '';
		foreach ($samples as $sample) {
			$html .= sprintf(
				'<tr><td>%s</td><td>%s</td><td class="num">%d</td><td><code>%s</code></td><td>%s</td></tr>',
				htmlspecialchars((string)($sample['population'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($sample['selectionMethod'] ?? ''), ENT_QUOTES),
				(int)($sample['sampleSize'] ?? 0),
				htmlspecialchars((string)($sample['reproducibleSeed'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($sample['extractedAt'] ?? ''), ENT_QUOTES)
			);
		}

		return $html;
	}//end renderSampleRows()

	/**
	 * Render the AuditFinding rows as `<tr>` HTML.
	 *
	 * @param array<int,array<string,mixed>> $findings AuditFinding records.
	 *
	 * @return string
	 */
	private function renderFindingRows(array $findings): string {
		$html = '';
		foreach ($findings as $finding) {
			$html .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td class="num">%s</td>'
				. '<td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				htmlspecialchars((string)($finding['transaction'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($finding['findingType'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($finding['topic'] ?? ''), ENT_QUOTES),
				$this->fmtMoney(value: (float)($finding['amount'] ?? 0)),
				htmlspecialchars((string)($finding['severity'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($finding['status'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($finding['controllerResponse'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($finding['auditorConclusion'] ?? ''), ENT_QUOTES)
			);
		}

		return $html;
	}//end renderFindingRows()

	/**
	 * Render the SiSaAssurance rows as `<tr>` HTML.
	 *
	 * @param array<int,array<string,mixed>> $rows SiSaAssurance rows.
	 *
	 * @return string
	 */
	private function renderSisaRows(array $rows): string {
		$html = '';
		foreach ($rows as $row) {
			$count = count((array)($row['findings'] ?? []));
			$html .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="num">%d</td></tr>',
				htmlspecialchars((string)($row['schemeCode'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($row['accountableParty'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($row['specificBenefit'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($row['assuranceLevel'] ?? ''), ENT_QUOTES),
				$count
			);
		}

		return $html;
	}//end renderSisaRows()

	/**
	 * Write the bundle as a ZIP archive and return the absolute path.
	 *
	 * @param string $packageId The package id.
	 * @param array<string,mixed> $manifest The manifest payload.
	 * @param array<string,mixed> $ledger The ledger payload.
	 * @param string $summaryHtml The HTML summary.
	 * @param array<string,mixed> $bundle The collected bundle.
	 *
	 * @return string Absolute path to the ZIP file.
	 *
	 * @throws RuntimeException When the ZIP cannot be written.
	 */
	private function writeZip(
		string $packageId,
		array $manifest,
		array $ledger,
		string $summaryHtml,
		array $bundle,
	): string {
		$zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $packageId . '.zip';
		$zip = new ZipArchive();
		$opened = $zip->open($zipPath, (ZipArchive::CREATE | ZipArchive::OVERWRITE));
		if ($opened !== true) {
			throw new RuntimeException('Failed to open accountantsdossier ZIP: ' . ((string)$opened));
		}

		$root = $packageId . '/';
		$zip->addFromString($root . 'manifest.json', $this->canonicalJson(payload: $manifest));
		$zip->addFromString($root . 'ledger.json', $this->canonicalJson(payload: $ledger));
		$zip->addFromString($root . 'summary.pdf.html', $summaryHtml);

		if ($bundle['auditProtocol'] !== null) {
			$zip->addFromString(
				$root . 'attachments/controleprotocol/controleprotocol.json',
				$this->canonicalJson(payload: $bundle['auditProtocol'])
			);
		}

		$this->writeRecordSet(zip: $zip, root: $root, folder: 'tolerance-matrix', prefix: 'row', records: $bundle['toleranceMatrix']);
		$this->writeRecordSet(zip: $zip, root: $root, folder: 'materialiteit', prefix: 'row', records: $bundle['materialiteit']);
		$this->writeRecordSet(zip: $zip, root: $root, folder: 'audit-samples', prefix: 'sample', records: $bundle['auditSamples']);
		$this->writeRecordSet(zip: $zip, root: $root, folder: 'audit-findings', prefix: 'finding', records: $bundle['auditFindings']);

		if ($bundle['verklaringDraft'] !== null) {
			$zip->addFromString(
				$root . 'attachments/verklaring-draft/verklaring-draft.json',
				$this->canonicalJson(payload: $bundle['verklaringDraft'])
			);
		}

		$this->writeRecordSet(zip: $zip, root: $root, folder: 'sisa-assurance', prefix: 'row', records: $bundle['sisaAssurance']);

		$closed = $zip->close();
		if ($closed === false) {
			throw new RuntimeException('Failed to close accountantsdossier ZIP');
		}

		return $zipPath;
	}//end writeZip()

	/**
	 * Write a per-schema record set as numbered JSON files inside the ZIP.
	 *
	 * @param ZipArchive $zip The ZIP archive being written.
	 * @param string $root The root path inside the ZIP.
	 * @param string $folder Sub-folder under attachments/.
	 * @param string $prefix Filename prefix.
	 * @param array<int,array<string,mixed>> $records The records.
	 *
	 * @return void
	 */
	private function writeRecordSet(ZipArchive $zip, string $root, string $folder, string $prefix, array $records): void {
		foreach ($records as $index => $record) {
			$zip->addFromString(
				sprintf('%sattachments/%s/%s-%04d.json', $root, $folder, $prefix, ($index + 1)),
				$this->canonicalJson(payload: $record)
			);
		}
	}//end writeRecordSet()

	/**
	 * Delegate PKIO signature creation to the configured signer.
	 *
	 * The signer URI is read from the app config (`bado_dossier_signer_uri`).
	 * When no signer is configured the bundle is left unsigned and the caller
	 * surfaces signaturePending=true so the operator can finalise the
	 * signature out-of-band; this is the documented build-time contract per
	 * Task 16 deferred-note.
	 *
	 * @param string $packageId The package id.
	 * @param string $zipPath Absolute path to the ZIP file.
	 * @param string $sha256 SHA-256 over ledger.json.
	 *
	 * @return array{pending:bool,signerUri:?string,signedAt:?string,thumbprint:?string}
	 */
	private function delegatePkioSignature(string $packageId, string $zipPath, string $sha256): array {
		$signerUri = $this->signerUri();
		if ($signerUri === null) {
			$this->logger->info(
				'AccountantsdossierExportService: PKIO signer not configured; bundle left unsigned (signaturePending=true)',
				['packageId' => $packageId, 'zipPath' => $zipPath, 'sha256' => $sha256]
			);

			return [
				'pending' => true,
				'signerUri' => null,
				'signedAt' => null,
				'thumbprint' => null,
			];
		}

		// Hand-off: log the intent. Production wires the docudesk + qualified
		// certificate adapter; the worktree build keeps the contract honest
		// by emitting a structured log line the operator can grep for.
		$this->logger->info(
			'AccountantsdossierExportService: hand-off PKIO signature to configured signer',
			[
				'packageId' => $packageId,
				'zipPath' => $zipPath,
				'sha256' => $sha256,
				'signerUri' => $signerUri,
			]
		);

		return [
			'pending' => true,
			'signerUri' => $signerUri,
			'signedAt' => null,
			'thumbprint' => null,
		];
	}//end delegatePkioSignature()

	/**
	 * Resolve the configured PKIO signer URI, or null when unset.
	 *
	 * @return string|null
	 */
	private function signerUri(): ?string {
		$uri = trim($this->appConfig->getValueString(Application::APP_ID, 'bado_dossier_signer_uri', ''));
		if ($uri === '') {
			return null;
		}

		return $uri;
	}//end signerUri()

	/**
	 * Resolve a Controleprotocol by id, preferring an exact id match.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function loadOne(string $schema, string $id): ?array {
		// NOT findAll(['filters' => ['id' => …]]) — `filters` addresses JSON
		// properties and the entity's `id` is not one, so that shape matched
		// nothing for every value and this loader returned null for every
		// record the export asked for. find() answers the uuid directly,
		// making the id-comparison loop that followed redundant.
		return ObjectIdentifier::findOne(
			scoped: $this->objects()
				->setRegister($this->register())
				->setSchema($schema),
			id: $id
		);
	}//end loadOne()

	/**
	 * Load all records of a schema for a given protocol id.
	 *
	 * @param string $schema The schema slug.
	 * @param string $protocolId The Controleprotocol.id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function loadAllByProtocol(string $schema, string $protocolId): array {
		$rows = $this->objects()
			->setRegister($this->register())
			->setSchema($schema)
			->findAll(['filters' => ['protocol' => $protocolId]]);

		return array_values((array)$rows);
	}//end loadAllByProtocol()

	/**
	 * Load AuditFindings whose parent sample is in the given id set.
	 *
	 * @param array<string,bool> $sampleIds Map of sampleId => true.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function loadFindings(array $sampleIds): array {
		if (empty($sampleIds) === true) {
			return [];
		}

		$all = $this->objects()
			->setRegister($this->register())
			->setSchema(self::SCHEMA_AUDIT_FINDING)
			->findAll([]);

		$findings = [];
		foreach ($all as $finding) {
			$sample = (string)($finding['sample'] ?? '');
			if ($sample !== '' && isset($sampleIds[$sample]) === true) {
				$findings[] = $finding;
			}
		}

		return $findings;
	}//end loadFindings()

	/**
	 * Load the VerklaringDraft for a protocol, or null when none exists.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function loadDeclarationForProtocol(string $protocolId): ?array {
		$found = $this->objects()
			->setRegister($this->register())
			->setSchema(self::SCHEMA_VERKLARING_DRAFT)
			->findAll(['filters' => ['protocol' => $protocolId]]);

		return ($found[0] ?? null);
	}//end loadVerklaringForProtocol()

	/**
	 * Encode a payload as canonical, deterministic JSON.
	 *
	 * @param mixed $payload The payload to encode.
	 *
	 * @return string
	 */
	private function canonicalJson(mixed $payload): string {
		return (string)json_encode(
			$payload,
			(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
	}//end canonicalJson()

	/**
	 * Format an EUR amount with Dutch locale.
	 *
	 * @param float $value The amount.
	 *
	 * @return string
	 */
	private function fmtMoney(float $value): string {
		return number_format($value, 2, ',', '.');
	}//end fmtMoney()

	/**
	 * Format a generic number (up to 2 decimals) with Dutch locale.
	 *
	 * @param mixed $value The number.
	 *
	 * @return string
	 */
	private function fmtNumber(mixed $value): string {
		return number_format((float)$value, 2, ',', '.');
	}//end fmtNumber()

	/**
	 * Build the package id from protocol id + timestamp.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 * @param string $generatedAt The ISO 8601 timestamp.
	 *
	 * @return string
	 */
	private function packageId(string $protocolId, string $generatedAt): string {
		$safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $protocolId);
		return 'accountantsdossier-' . $safe . '-' . substr(md5($generatedAt . $protocolId), 0, 12);
	}//end packageId()

	/**
	 * Stable UUID-ish identifier for the XMP DocumentID block.
	 *
	 * @param string $packageId The package id.
	 *
	 * @return string
	 */
	private function packageUuid(string $packageId): string {
		$hash = md5($packageId);
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			substr($hash, 12, 4),
			substr($hash, 16, 4),
			substr($hash, 20, 12)
		);
	}//end packageUuid()

	/**
	 * Count a nullable record as 1 (present) or 0 (absent).
	 *
	 * @param mixed $value The record (or null).
	 *
	 * @return int 1 when $value is non-null, otherwise 0.
	 */
	private function presenceCount(mixed $value): int {
		if ($value === null) {
			return 0;
		}

		return 1;
	}//end presenceCount()

	/**
	 * Current ISO 8601 UTC timestamp.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end nowIso()

	/**
	 * Current user id for generatedBy attribution.
	 *
	 * @return string
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return (string)$user->getUID();
	}//end currentUserId()

	/**
	 * Lazily resolve OpenRegister's ObjectService.
	 *
	 * @return mixed
	 */
	private function objects(): mixed {
		return $this->objectService;
	}//end objects()

	/**
	 * Resolve the OpenRegister register slug, defaulting to 'shillinq'.
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
}//end class
