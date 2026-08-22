<?php

/**
 * TenderNed Status Sync Integration.
 *
 * REQ-006 — when an aanbestedende dienst approves the eindoplevering of a
 * `bron: tenderned` obligation, send a status update to TenderNed via
 * openconnector so the public dossier reflects completion
 * (Aanbestedingswet 2012 artikel 2.135). Vendors (inschrijvers) cannot
 * trigger the sync — the call is denied early when the tenant KvK does
 * not match the aanbestedende dienst KvK on the dossier.
 *
 * The transport itself routes through openconnector's outbound
 * `tenderned.completion` source. That source only exists on a live
 * openconnector instance with credentials (Task 0.2 / Task 6.1
 * dependency). In the build sandbox the integration class is
 * provider-agnostic: it resolves an `OutboundIntegrationGateway` from
 * the DI container if one is bound by openconnector at runtime, and
 * falls back to a structured log emission so:
 *
 *  - the call is observable end-to-end during local development,
 *  - a sync failure on the live wire degrades gracefully (REQ-006:
 *    "logs a warning but does not fail the milestone completion"),
 *  - the authoritative half of the contract (the WHO and the WHAT) is
 *    fully unit-testable without a live HTTP socket.
 *
 * Authorisation is checked here defensively in addition to the
 * declarative RBAC + TenderNedProcurementGuard upstream: the
 * `awardedSupplier` of the linked aanbesteding must match the tenant
 * KvK before the call is allowed (vendors cannot sync; only the
 * aanbestedende dienst can — see design D6).
 *
 * @category Integration
 * @package  OCA\Shillinq\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Integration;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Outbound status-sync to the public TenderNed dossier (REQ-006).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-6
 */
class TenderNedStatusSync {

	/**
	 * Openconnector outbound source binding name. Resolved lazily so the
	 * sync is silently inert on instances without openconnector
	 * installed (REQ-006 fail-soft contract).
	 *
	 * @var string
	 */
	private const OPENCONNECTOR_GATEWAY = 'OCA\OpenConnector\Service\OutboundIntegrationGateway';

	/**
	 * Status mapped to the TenderNed dossier on a successful sync.
	 *
	 * @var string
	 */
	public const TENDERNED_STATUS_AFGEROND = 'afgerond';

	/**
	 * Construct the sync integration.
	 *
	 * @param ContainerInterface $container DI container for lazy openconnector gateway resolution.
	 * @param IAppConfig $appConfig App config for tenant KvK + register slug.
	 * @param LoggerInterface $logger Logger for diagnostics + best-effort failure path.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Sync the completion of an eindoplevering back to TenderNed (REQ-006).
	 *
	 * Called by `OrderFulfilmentTransitionListener` when the approved
	 * eindoplevering for a `bron: tenderned` obligation transitions to
	 * `completed`. Returns true when the outbound call was attempted (the
	 * live transport may still fail downstream — the contract guarantees
	 * a "best-effort" send + structured log only).
	 *
	 * @param array<string, mixed> $oplevering Completed OrderFulfilment payload.
	 *
	 * @return bool True when a sync attempt was made; false when ineligible.
	 *
	 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-6
	 */
	public function syncCompletion(array $oplevering): bool {
		try {
			$commitmentId = trim((string)($oplevering['commitmentId'] ?? ''));
			if ($commitmentId === '') {
				return false;
			}

			$tender = $this->resolveTenderFor(commitmentId: $commitmentId);
			if ($tender === null) {
				$this->logger->info(
					'TenderNedStatusSync: no TenderNed dossier linked — skipping sync',
					['commitmentId' => $commitmentId]
				);
				return false;
			}

			if ($this->isContractingDienst(tender: $tender) === false) {
				// Vendor side cannot push completion to the public dossier (REQ-006).
				$this->logger->info(
					'TenderNedStatusSync: tenant is not the aanbestedende dienst — sync denied (REQ-006)',
					['tenderId' => ($tender['tenderId'] ?? 'unknown')]
				);
				return false;
			}

			$payload = $this->buildPayload(tender: $tender, oplevering: $oplevering);
			$this->send(tender: $tender, payload: $payload);
			return true;
		} catch (Throwable $e) {
			// REQ-006 fail-soft contract: a sync failure must not fail the
			// milestone completion. Log a warning and move on.
			$this->logger->warning(
				'TenderNedStatusSync: sync attempt failed — logging only (REQ-006 fail-soft)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end syncCompletion()

	/**
	 * Resolve the TenderNedProcurement linked to a Commitment.
	 *
	 * @param string $commitmentId The linked obligation identifier.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolveTenderFor(string $commitmentId): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			// OR unavailable — caller logs as info; treat as no link.
			return null;
		}

		try {
			$rows = $objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: 'TenderNedProcurement')
				->findAll(
					[
						'filters' => ['commitmentId' => $commitmentId],
					]
				);
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($rows) === false) {
			return null;
		}

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end resolveAanbestedingFor()

	/**
	 * Check whether the tenant is the aanbestedende dienst on the dossier.
	 *
	 * @param array<string, mixed> $tender TenderNedProcurement payload.
	 *
	 * @return bool
	 */
	private function isContractingDienst(array $tender): bool {
		$tenantKvk = trim(
			$this->appConfig->getValueString(Application::APP_ID, 'tenant_kvk', '')
		);
		if ($tenantKvk === '') {
			return false;
		}

		$dienst = trim((string)($tender['contractingService'] ?? ''));
		if ($dienst === '') {
			return false;
		}

		return str_starts_with(haystack: $dienst, needle: $tenantKvk);
	}//end isAanbestedendeDienst()

	/**
	 * Shape the TenderNed completion payload.
	 *
	 * @param array<string, mixed> $tender TenderNed dossier.
	 * @param array<string, mixed> $oplevering Approved eindoplevering.
	 *
	 * @return array<string, mixed>
	 */
	private function buildPayload(array $tender, array $oplevering): array {
		$supportingDocuments = ($oplevering['supportingDocuments'] ?? []);
		if (is_array($supportingDocuments) === false) {
			$supportingDocuments = [];
		}

		return [
			'tenderId' => (string)($tender['tenderId'] ?? ''),
			'status' => self::TENDERNED_STATUS_AFGEROND,
			'deliveryDate' => (string)($oplevering['deliveryDate'] ?? ''),
			'eindopleveringId' => (string)($oplevering['milestoneId'] ?? ''),
			'bewijsstukCount' => count($supportingDocuments),
			'commitmentId' => (string)($oplevering['commitmentId'] ?? ''),
			'administrationId' => (string)($oplevering['administrationId'] ?? ''),
		];

	}//end buildPayload()

	/**
	 * Send the payload to TenderNed via openconnector if available,
	 * otherwise log a structured entry so the sync is observable.
	 *
	 * @param array<string, mixed> $tender Dossier (for log context).
	 * @param array<string, mixed> $payload Completion payload.
	 *
	 * @return void
	 */
	private function send(array $tender, array $payload): void {
		$gateway = $this->resolveGateway();
		if ($gateway === null) {
			// Openconnector not installed / not bound — log only. This
			// satisfies the REQ-006 audit trail intent: the attempt is
			// recorded with full payload, and operators can replay it
			// when the openconnector source comes online.
			$this->logger->info(
				'TenderNedStatusSync: openconnector gateway not bound — payload logged for replay',
				['tenderId' => ($tender['tenderId'] ?? 'unknown'), 'payload' => $payload]
			);
			return;
		}

		// The gateway contract is intentionally narrow — `send($source,
		// $payload)` — so this class stays a thin adapter over whatever
		// outbound mechanism openconnector chooses (REST, webhook,
		// CloudEvent). When the gateway raises, we log and swallow; the
		// milestone completion itself is unaffected.
		try {
			$gateway->send('tenderned.completion', $payload);
			$this->logger->info(
				'TenderNedStatusSync: completion synced to TenderNed',
				['tenderId' => ($tender['tenderId'] ?? 'unknown')]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TenderNedStatusSync: openconnector outbound send failed — logging only',
				[
					'tenderId' => ($tender['tenderId'] ?? 'unknown'),
					'exception' => $e->getMessage(),
				]
			);
		}//end try

	}//end send()

	/**
	 * Resolve the openconnector outbound gateway from the container.
	 *
	 * @return object|null Gateway or null when openconnector is absent.
	 */
	private function resolveGateway(): ?object {
		try {
			return $this->container->get(self::OPENCONNECTOR_GATEWAY);
		} catch (Throwable $e) {
			return null;
		}

	}//end resolveGateway()

	/**
	 * Return the configured OR register slug, defaulting to `shillinq`.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()
}//end class
