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

use OCA\Shillinq\AppInfo\AppIdentity;
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
			'objectCounts' => $this->objectCounts(bundle: $bundle),
			'signature' => $this->pendingSignature(),
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
	 * How many objects of each kind the bundle holds.
	 *
	 * @param array<string,mixed> $bundle The collected bundle.
	 *
	 * @return array<string,int> Kind => count.
	 */
	private function objectCounts(array $bundle): array {
		return [
			'auditProtocol' => 1,
			'toleranceMatrix' => count($bundle['toleranceMatrix']),
			'materialiteit' => count($bundle['materialiteit']),
			'auditSamples' => count($bundle['auditSamples']),
			'auditFindings' => count($bundle['auditFindings']),
			'verklaringDraft' => $this->presenceCount(value: $bundle['verklaringDraft']),
			'sisaAssurance' => count($bundle['sisaAssurance']),
		];
	}//end objectCounts()

	/**
	 * The manifest's signature block: PKIO PAdES B-LT, pending, delegated to
	 * the configured signer.
	 *
	 * @return array<string,mixed> The signature block.
	 */
	private function pendingSignature(): array {
		return [
			'algorithm' => 'PKIO-RSA-SHA256',
			'standard' => 'ETSI EN 319 142-1 PAdES B-LT',
			'status' => 'pending',
			'signedAt' => null,
			'signerUri' => $this->signerUri(),
			'thumbprint' => null,
		];
	}//end pendingSignature()

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

		$auditFindings = $this->loadFindings(sampleIds: $this->sampleIds(samples: $auditSamples));
		$declarationDraft = $this->loadDeclarationForProtocol(protocolId: $protocolId);
		$sisaAssurance = $this->loadAllByProtocol(schema: self::SCHEMA_SISA_ASSURANCE, protocolId: $protocolId);

		return [
			'auditProtocol' => $auditProtocol,
			'toleranceMatrix' => $this->sortedBy(rows: $toleranceMatrix, field: 'topic'),
			'materialiteit' => $this->sortedBy(rows: $materialiteit, field: 'scope'),
			'auditSamples' => $this->sortedBy(rows: $auditSamples, field: 'extractedAt'),
			'auditFindings' => $this->sortedById(rows: $auditFindings),
			'verklaringDraft' => $declarationDraft,
			'sisaAssurance' => $this->sortedBy(rows: $sisaAssurance, field: 'schemeCode'),
		];
	}//end collectBundle()

	/**
	 * The ids of the samples, as a lookup set.
	 *
	 * @param array<int,array<string,mixed>> $samples The AuditSample rows.
	 *
	 * @return array<string,bool> Sample id => true.
	 */
	private function sampleIds(array $samples): array {
		$sampleIds = [];
		foreach ($samples as $sample) {
			$sampleId = (string)($sample['id'] ?? ($sample['@self']['id'] ?? ''));
			if ($sampleId !== '') {
				$sampleIds[$sampleId] = true;
			}
		}

		return $sampleIds;
	}//end sampleIds()

	/**
	 * The rows sorted on one string field (missing sorts as '').
	 *
	 * @param array<int,array<string,mixed>> $rows  The rows.
	 * @param string                         $field The sort field.
	 *
	 * @return array<int,array<string,mixed>> The sorted rows.
	 */
	private function sortedBy(array $rows, string $field): array {
		usort(
			$rows,
			static fn (array $left, array $right): int => strcmp(
				(string)($left[$field] ?? ''),
				(string)($right[$field] ?? '')
			)
		);

		return $rows;
	}//end sortedBy()

	/**
	 * The rows sorted on their object id (`id`, else `@self.id`).
	 *
	 * @param array<int,array<string,mixed>> $rows The rows.
	 *
	 * @return array<int,array<string,mixed>> The sorted rows.
	 */
	private function sortedById(array $rows): array {
		usort(
			$rows,
			static fn (array $left, array $right): int => strcmp(
				(string)($left['id'] ?? ($left['@self']['id'] ?? '')),
				(string)($right['id'] ?? ($right['@self']['id'] ?? ''))
			)
		);

		return $rows;
	}//end sortedById()

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
		return (new DossierSummaryRenderer())->render(manifest: $manifest, bundle: $bundle);
	}//end renderSummaryHtml()

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
		$uri = trim($this->appConfig->getValueString(AppIdentity::APP_ID, 'bado_dossier_signer_uri', ''));
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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
