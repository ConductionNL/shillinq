<?php

/**
 * ICP Filing Service
 *
 * Tier-3 intra-community supplies (ICP) filing write-side operations: correction
 * filings (REQ-ICP-008), the Belastingdienst inspection-bundle export (REQ-ICP-010)
 * and the pending-VIES-outage scan that drives the daily revalidation job
 * (REQ-ICP-009). The read-side ledger / reconciliation / periodicity computation
 * lives in IcpService; this service owns the IcpOpgaaf write path and the
 * audit-trail bundle so each service keeps a single responsibility.
 *
 * All reads / writes are scoped to a single administration (REQ-ICP-001): callers
 * pass the administrationId resolved from the authenticated user's context, never a
 * client-supplied trust boundary. The OpenRegister ObjectService enforces the
 * multitenancy / RBAC boundary on every find / save.
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
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use RuntimeException;
use ZipArchive;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Owns the ICP filing write path: corrections, audit-export bundles, outage scan.
 *
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 */
class IcpFilingService {
	/**
	 * Construct the service with OpenRegister's ObjectService injected
	 * (ADR-083 rule 1).
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param IcpCalculator $calculator Pure-logic ICP helper.
	 * @param IcpService $icp Read-side ICP service (period-window supplies).
	 * @param ViesService $vies VIES validation / evidence-reuse helper.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IcpCalculator $calculator,
		private readonly IcpService $icp,
		private readonly ViesService $vies,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Create a correction ICP-opgaaf for an already-submitted period (REQ-ICP-008).
	 *
	 * Materialises a new `IcpOpgaaf` with `type: correction`, `correctsPeriod` set to
	 * the period being corrected, and the supplied corrective lines (positive or
	 * negative amounts). For each corrective line the original, contemporaneous
	 * `ViesValidation` evidence is re-attached (looked up by buyer VAT-ID) rather than
	 * re-querying VIES, preserving the good-faith defence (Implementing Regulation
	 * 282/2011, Article 18). The new opgaaf starts in `draft` so the reconciliation
	 * gate (REQ-ICP-004) and lifecycle (REQ-ICP-005) still apply before submission.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $correctsPeriod The period being corrected (YYYY-Qn / YYYY-MM).
	 * @param array<int,array<string,mixed>> $correctiveLines Lines: {buyerVatId, supplyType, amountExclVat}.
	 * @param string $reason Free-text correction reason.
	 *
	 * @return array<string,mixed> The draft correction opgaaf plus `evidence` and a `saved` flag.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function createCorrection(
		string $administrationId,
		string $correctsPeriod,
		array $correctiveLines,
		string $reason,
	): array {
		$lines = $this->calculator->aggregateLines(supplies: $correctiveLines);
		$totals = $this->calculator->totals(lines: $lines);
		$evidence = [];
		foreach ($lines as $line) {
			$buyer = $line['buyerVatId'];
			if ($buyer === '') {
				continue;
			}

			$prior = $this->vies->findRecentValid(administrationId: $administrationId, vatId: $buyer);
			if ($prior !== null) {
				$evidence[] = [
					'buyerVatId' => $buyer,
					'requestId' => (string)($prior['requestId'] ?? ''),
					'valid' => (bool)($prior['valid'] ?? false),
				];
			}
		}//end foreach

		$return = [
			'administrationId' => $administrationId,
			'type' => 'correction',
			'status' => 'draft',
			'correctsPeriod' => $correctsPeriod,
			'correctionReason' => $reason,
			'lines' => $lines,
			'total' => $totals['total'],
			'totalGoods' => $totals['totalGoods'],
			'totalServices' => $totals['totalServices'],
			'totalTriangulation' => $totals['totalTriangulation'],
		];

		$saved = $this->saveReturn(return: $return);

		return ($return + ['evidence' => $evidence, 'saved' => $saved]);
	}//end createCorrection()

	/**
	 * Produce the Belastingdienst inspection bundle for a period (REQ-ICP-010).
	 *
	 * Assembles a ZIP containing the archived XBRL payload, a `kenmerk.txt` with the
	 * Belastingdienst reference, and a CSV of all underlying supplies with their VIES
	 * request IDs (the good-faith audit trail). Source-invoice PDFs are attached when
	 * the OpenRegister file-attachment surface is available; that fetch is left to the
	 * caller because it requires a live instance (documented deferral in tasks). The
	 * ZIP is written to a temp path and the path + manifest are returned.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-ICP-001).
	 * @param string $period Filing period (YYYY-Qn / YYYY-MM).
	 *
	 * @return array{period:string,zipPath:string,supplyCount:int,manifest:array<int,string>,reference:string}
	 *
	 * @throws RuntimeException When the ZIP cannot be created.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function exportForInspection(string $administrationId, string $period): array {
		$supplies = $this->icp->suppliesInPeriod(administrationId: $administrationId, period: $period);
		$return = $this->findReturn(administrationId: $administrationId, period: $period);
		$xbrl = (string)($return['xmlPayload'] ?? '');
		$reference = (string)($return['taxAuthorityReference'] ?? '');
		$requestIds = $this->requestIdMap(administrationId: $administrationId, supplies: $supplies);
		$csv = $this->calculator->buildSuppliesCsv(supplies: $supplies, requestIds: $requestIds);

		$zipPath = (string)tempnam(sys_get_temp_dir(), 'icp_audit_');
		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
			throw new RuntimeException('Unable to create ICP inspection bundle.');
		}

		$manifest = [];
		if ($xbrl !== '') {
			$zip->addFromString('opgaaf.xbrl', $xbrl);
			$manifest[] = 'opgaaf.xbrl';
		}

		$zip->addFromString('kenmerk.txt', 'Belastingdienst kenmerk: ' . $reference . "\nperiod: " . $period . "\n");
		$manifest[] = 'kenmerk.txt';
		$zip->addFromString('supplies.csv', $csv);
		$manifest[] = 'supplies.csv';
		$zip->addFromString(
			'manifest.txt',
			"ICP inspection bundle\nperiod: " . $period . "\nsupplies: " . count($supplies) . "\ncontents: " . implode(', ', $manifest) . "\n"
		);
		$manifest[] = 'manifest.txt';
		$zip->close();

		return [
			'period' => $period,
			'zipPath' => $zipPath,
			'supplyCount' => count($supplies),
			'manifest' => $manifest,
			'reference' => $reference,
		];

	}//end exportForInspection()

	/**
	 * List ViesValidation outages pending a definitive VIES answer (REQ-ICP-009).
	 *
	 * Drives the daily revalidation job: returns the buyer VAT-IDs that are pending a
	 * definitive VIES answer, together with the age (in days) of the pending outage
	 * evidence so the caller can escalate past the 14-day threshold.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $now ISO-8601 "now" override for deterministic tests.
	 *
	 * @return array<int,array{vatId:string,viesValidationId:string,ageDays:int,escalate:bool}>
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function pendingOutages(string $administrationId, string $now = ''): array {
		$nowTs = time();
		if ($now !== '') {
			$parsed = strtotime($now);
			if ($parsed !== false) {
				$nowTs = $parsed;
			}
		}

		$validations = $this->objectService
			->setRegister($this->register())
			->setSchema('ViesValidation')
			->findAll(['filters' => ['administrationId' => $administrationId, 'outage' => true]]);

		$pending = [];
		foreach ($validations as $validation) {
			$stamp = strtotime((string)($validation['validationTimestamp'] ?? ''));
			$ageDays = 0;
			if ($stamp !== false) {
				$ageDays = (int)floor((($nowTs - $stamp) / (24 * 60 * 60)));
			}

			$pending[] = [
				'vatId' => (string)($validation['vatId'] ?? ''),
				'viesValidationId' => (string)($validation['@self']['id'] ?? ($validation['id'] ?? '')),
				'ageDays' => $ageDays,
				'escalate' => ($ageDays > 14),
			];
		}//end foreach

		return $pending;
	}//end pendingOutages()

	/**
	 * Build a map of viesValidationId => VIES requestId scoped to the given supplies.
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<int,array<string,mixed>> $supplies IcpSupply records.
	 *
	 * @return array<string,string> Map of validation id to VIES request id.
	 */
	private function requestIdMap(string $administrationId, array $supplies): array {
		// Collect the validation ids actually referenced by the period's supplies so
		// the map is scoped to the bundle (REQ-ICP-010) rather than the whole admin.
		$referenced = [];
		foreach ($supplies as $supply) {
			$vid = (string)($supply['viesValidationId'] ?? '');
			if ($vid !== '') {
				$referenced[$vid] = true;
			}
		}

		$validations = $this->objectService
			->setRegister($this->register())
			->setSchema('ViesValidation')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$map = [];
		foreach ($validations as $validation) {
			$id = (string)($validation['@self']['id'] ?? ($validation['id'] ?? ''));
			if ($id === '' || (isset($referenced[$id]) === false && $referenced !== [])) {
				continue;
			}

			$map[$id] = (string)($validation['requestId'] ?? '');
		}

		return $map;
	}//end requestIdMap()

	/**
	 * Find the IcpOpgaaf record for an administration / period.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $period Filing period.
	 *
	 * @return array<string,mixed> The opgaaf record, or [] when none exists.
	 */
	private function findReturn(string $administrationId, string $period): array {
		$opgaven = $this->objectService
			->setRegister($this->register())
			->setSchema('IcpOpgaaf')
			->findAll(['filters' => ['administrationId' => $administrationId, 'period' => $period]]);

		foreach ($opgaven as $return) {
			return $return;
		}

		return [];
	}//end findOpgaaf()

	/**
	 * Persist an IcpOpgaaf record (correction filing) via the real ObjectService API.
	 *
	 * @param array<string,mixed> $return The opgaaf to save.
	 *
	 * @return bool True when the save succeeded.
	 */
	private function saveReturn(array $return): bool {
		try {
			$this->objectService->saveObject(
				object: $return,
				register: $this->register(),
				schema: 'IcpOpgaaf',
			);

			return true;
		} catch (\Throwable $e) {
			return false;
		}

	}//end saveOpgaaf()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
