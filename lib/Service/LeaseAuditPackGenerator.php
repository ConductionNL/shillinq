<?php

/**
 * Lease Audit Pack Generator (skeleton)
 *
 * One-click audit-pack export skeleton for an IFRS 16 lease — packages the
 * lease-contract, full LeasePaymentSchedule, IBR derivation evidence, and every
 * LeaseReassessmentEvent (with before/after snapshots) into a single ZIP for
 * the auditor. The full implementation depends on the docudesk PDF pipeline
 * (Phase 2) so this class ships as the audit-shaped skeleton: it gathers the
 * data via the real OpenRegister ObjectService (`findAll`), builds the index
 * manifest, and returns a deterministic ZIP path the operator can wire to a
 * docudesk renderer when the pipeline lands.
 *
 * Reads are administration-scoped (ADR-005 IDOR safety); the operator who
 * triggered the export is captured on the index manifest for ADR-022 audit
 * trail.
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
 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Skeleton audit-pack builder for an IFRS 16 lease.
 *
 * The `generate` entry point gathers every artefact an auditor needs to walk a
 * lease end-to-end and returns an index manifest describing the pack. Wiring
 * the index to the docudesk PDF pipeline + the ZIP writer is the Phase 2
 * follow-up; the skeleton already enforces the administration scope, builds
 * the deterministic file layout, and stamps the operator + generated-at
 * timestamp on the index.
 *
 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
 */
class LeaseAuditPackGenerator {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LeasePaymentScheduleService $scheduleService Schedule rows for the pack.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LeasePaymentScheduleService $scheduleService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Build the audit-pack index for a lease (task 13.1 skeleton).
	 *
	 * Reads the LeaseContract, its LeasePaymentSchedule rows, its
	 * LeaseReassessmentEvent records and the IBR-derivation evidence FK list,
	 * then returns the index manifest the docudesk Phase-2 pipeline will turn
	 * into a real ZIP. Returns null when the lease is out of scope (ADR-005).
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $operatorId Operator who triggered the export (audit trail).
	 *
	 * @return array<string,mixed>|null The audit-pack index manifest, or null when out of scope.
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	public function generate(string $leaseContractId, string $administrationId, string $operatorId): ?array {
		$lease = $this->fetchLease(leaseContractId: $leaseContractId, administrationId: $administrationId);
		if ($lease === null) {
			return null;
		}

		$sourceLease = (string)($lease['@self']['slug'] ?? ($lease['leaseNumber'] ?? ''));
		$events = $this->fetchEvents(sourceLease: $sourceLease, administrationId: $administrationId);
		$schedule = $this->scheduleService->buildSchedule(
			leaseContractId: $leaseContractId,
			administrationId: $administrationId,
		);

		$ibrEvidence = $this->extractIbrEvidence(lease: $lease);

		return [
			'lease' => $lease,
			'leaseContractId' => $leaseContractId,
			'administrationId' => $administrationId,
			'operatorId' => $operatorId,
			'generatedAt' => date('c'),
			'sourceLease' => $sourceLease,
			'paymentSchedule' => $schedule,
			'reassessmentEvents' => $events,
			'ibrEvidence' => $ibrEvidence,
			'contents' => $this->buildContentsIndex(
				sourceLease: $sourceLease,
				scheduleRowCount: count($schedule),
				eventCount: count($events),
				ibrEvidenceCount: count($ibrEvidence),
			),
			'downloadPath' => $this->buildDownloadPath(sourceLease: $sourceLease),
			'status' => 'pending-pdf-pipeline',
		];

	}//end generate()

	/**
	 * Build the deterministic contents index for the ZIP.
	 *
	 * Mirrors the auditor-friendly layout: index.md, lease-contract.pdf,
	 * schedule.csv, ibr-evidence/, reassessments/, disclosure.csv.
	 *
	 * @param string $sourceLease The lease slug/id used in filenames.
	 * @param int $scheduleRowCount Count of schedule rows packed.
	 * @param int $eventCount Count of reassessment events packed.
	 * @param int $ibrEvidenceCount Count of IBR-evidence FKs.
	 *
	 * @return array<int,array<string,mixed>> The contents index.
	 */
	private function buildContentsIndex(
		string $sourceLease,
		int $scheduleRowCount,
		int $eventCount,
		int $ibrEvidenceCount,
	): array {
		$index = [
			['path' => 'index.md', 'type' => 'markdown', 'description' => 'Audit-pack index and walkthrough'],
			['path' => 'lease-contract.pdf', 'type' => 'pdf-deferred', 'description' => 'Lease contract (Phase 2: docudesk PDF)'],
			[
				'path' => sprintf('schedule/%s-schedule.csv', $sourceLease),
				'type' => 'csv',
				'description' => 'Full lease-payment-schedule rows',
				'rowCount' => $scheduleRowCount,
			],
			[
				'path' => 'disclosure/disclosure.csv',
				'type' => 'csv',
				'description' => 'IFRS 16 disclosure table CSV for the period',
			],
		];

		if ($ibrEvidenceCount > 0) {
			$index[] = [
				'path' => 'ibr-evidence/',
				'type' => 'directory',
				'description' => 'IBR derivation evidence (docudesk FKs)',
				'fileCount' => $ibrEvidenceCount,
			];
		}

		if ($eventCount > 0) {
			$index[] = [
				'path' => 'reassessments/',
				'type' => 'directory',
				'description' => 'Every reassessment event with before/after snapshots',
				'fileCount' => $eventCount,
			];
		}

		return $index;
	}//end buildContentsIndex()

	/**
	 * Resolve the deterministic ZIP download path the Phase-2 pipeline will write.
	 *
	 * Includes today's date so a re-export overwrites the same-day pack, but
	 * a next-day export lands at a new path (auditors can carry both).
	 *
	 * @param string $sourceLease The lease slug/id.
	 *
	 * @return string The path under the user's shillinq audit-export folder.
	 */
	private function buildDownloadPath(string $sourceLease): string {
		return sprintf('/shillinq/audit-packs/%s/%s.zip', date('Y-m-d'), $sourceLease);
	}//end buildDownloadPath()

	/**
	 * Extract the IBR-derivation evidence FK list from the lease.
	 *
	 * The lease schema carries an `ibrEvidenceDocuments` array of docudesk FKs
	 * captured at IBR sign-off; absence yields an empty list.
	 *
	 * @param array<string,mixed> $lease The LeaseContract array.
	 *
	 * @return array<int,string> The FK list.
	 */
	private function extractIbrEvidence(array $lease): array {
		$evidence = ($lease['ibrEvidenceDocuments'] ?? []);
		if (is_array($evidence) === false) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					static function ($item): string {
						if (is_string($item) === true) {
							return $item;
						}

						return (string)($item['id'] ?? '');
					},
					$evidence
				),
				static fn (string $id): bool => $id !== ''
			)
		);

	}//end extractIbrEvidence()

	/**
	 * Fetch the LeaseContract administration-scoped (ADR-005).
	 *
	 * @param string $leaseContractId Lease id or slug.
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,mixed>|null The lease, or null when out of scope.
	 */
	private function fetchLease(string $leaseContractId, string $administrationId): ?array {
		try {
			$matches = $this->objectService()
				->setRegister($this->register())
				->setSchema('LeaseContract')
				->findAll(['filters' => ['administrationId' => $administrationId]]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'LeaseAuditPackGenerator: failed to read lease',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);

			return null;
		}

		foreach ($matches as $lease) {
			if (is_array($lease) === false) {
				continue;
			}

			$id = (string)($lease['id'] ?? ($lease['@self']['id'] ?? ''));
			$slug = (string)($lease['@self']['slug'] ?? '');
			if ($id === $leaseContractId || $slug === $leaseContractId) {
				return $lease;
			}
		}

		return null;
	}//end fetchLease()

	/**
	 * Fetch every reassessment event for this lease administration-scoped.
	 *
	 * @param string $sourceLease The source-lease FK.
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array<string,mixed>> The events (oldest first).
	 */
	private function fetchEvents(string $sourceLease, string $administrationId): array {
		try {
			$events = $this->objectService()
				->setRegister($this->register())
				->setSchema('LeaseReassessmentEvent')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'sourceLease' => $sourceLease,
						],
					]
				);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'LeaseAuditPackGenerator: failed to read reassessment events',
				['sourceLease' => $sourceLease, 'exception' => $e->getMessage()]
			);

			return [];
		}

		if (is_array($events) === true) {
			return array_values($events);
		}

		return [];
	}//end fetchEvents()

	/**
	 * Resolve OpenRegister's ObjectService lazily.
	 *
	 * @return object The ObjectService instance.
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

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
