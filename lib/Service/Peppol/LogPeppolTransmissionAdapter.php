<?php

/**
 * Log-only Peppol Transmission Adapter (generalised, default DI binding)
 *
 * Generalised from `PurchaseOrder\LogPeppolTransmissionAdapter` (REQ-EINV-004):
 * the same log-only, dev/CI-safe adapter now implements
 * {@see \OCA\Shillinq\Service\PurchaseOrder\PeppolTransmissionAdapterInterface}
 * (the thin alias of the shared {@see PeppolTransmissionPortInterface}), so a
 * single concrete class serves both the PO 3-way-match transmission path
 * (`submitOrder()`) and the AR e-invoicing path (`submit()`) without
 * duplicating the participant-lookup logic or the Log adapter itself
 * (design.md Trade-offs — generalise rather than fork).
 *
 * `lookupParticipant()` consults the OpenRegister `Vendor` schema (supplier
 * lookups, PO side) and the `CustomerMaster` schema (debtor lookups, AR side)
 * for a `peppolParticipantId` property, returning it verbatim when found and
 * `null` otherwise — the orchestration layer then falls back to PDF + email.
 *
 * `submit()` / `submitOrder()` log a single redacted line through the standard
 * logger (never the document body/XML — only its length) and fabricate a
 * Peppol-shaped URN (`urn:uuid:...`) so the orchestration code can still
 * record a transmission id deterministically. Production deployments swap
 * this binding for an HTTP-backed adapter that posts to the real access point.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Peppol
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Peppol;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\PurchaseOrder\PeppolTransmissionAdapterInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Log-only Peppol adapter (default DI binding for both PO and AR).
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 */
final class LogPeppolTransmissionAdapter implements PeppolTransmissionAdapterInterface {

	/**
	 * OpenRegister schemas searched (in order) for a `peppolParticipantId`
	 * property matching the requested party id. `Vendor` covers PO suppliers;
	 * `CustomerMaster` covers AR debtors.
	 *
	 * @var array<int,array{schema:string,idField:string}>
	 */
	private const PARTY_SCHEMAS = [
		['schema' => 'Vendor', 'idField' => 'id'],
		['schema' => 'CustomerMaster', 'idField' => 'customerId'],
	];

	/**
	 * OpenRegister's object service, injected per ADR-083/ADR-084.
	 *
	 * The party lookup is still wrapped in a try/catch so a missing schema
	 * (e.g. a greenfield deployment without `Vendor`/`CustomerMaster`) degrades
	 * to "no participant id" rather than failing the transmission — that
	 * tolerance lives in {@see lookupInSchema()}, not in the wiring.
	 *
	 * @var ObjectServiceInterface
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * App config (resolves the register slug).
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Logger sink.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service,
	 *                                             injected per ADR-083.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface|null $logger Optional logger (defaults to NullLogger).
	 *
	 * @return void
	 */
	public function __construct(
		ObjectServiceInterface $objectService,
		IAppConfig $appConfig,
		?LoggerInterface $logger = null,
	) {
		$this->objectService = $objectService;
		$this->appConfig = $appConfig;
		$this->logger = ($logger ?? new NullLogger());

	}//end __construct()

	/**
	 * Resolve a party's Peppol participant id from the Vendor / CustomerMaster
	 * schemas.
	 *
	 * Returns `null` when neither schema is present, the party has no row, or
	 * the row does not carry a non-empty `peppolParticipantId`. Any thrown
	 * lookup error is treated as "not registered" — the caller falls back to
	 * PDF + email rather than failing the transmission outright.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $partyId The party id to look up (PurchaseOrder.supplierId
	 *                        or ARInvoice.customerId).
	 *
	 * @return string|null The Peppol participant id, or null when not registered.
	 *
	 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 *
	 * @inheritDoc
	 */
	public function lookupParticipant(string $administrationId, string $partyId): ?string {
		if ($administrationId === '' || $partyId === '') {
			return null;
		}

		foreach (self::PARTY_SCHEMAS as $target) {
			$participantId = $this->lookupInSchema(
				administrationId: $administrationId,
				partyId: $partyId,
				schema: $target['schema'],
				idField: $target['idField']
			);
			if ($participantId !== null) {
				return $participantId;
			}
		}

		return null;
	}//end lookupParticipant()

	/**
	 * Submit an already-stored document to the Peppol network (shared port).
	 *
	 * @param string $participantId The recipient Peppol participant identifier.
	 * @param string $documentType Peppol document-type identifier (e.g. `ubl-invoice-2.1`).
	 * @param string $payloadFileUri Docudesk/Files FK URI of the stored document.
	 *
	 * @return string The transmission identifier.
	 *
	 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 *
	 * @inheritDoc
	 */
	public function submit(string $participantId, string $documentType, string $payloadFileUri): string {
		$this->logger->info(
			'shillinq.peppol.submit',
			[
				'participantId' => $participantId,
				'documentType' => $documentType,
				'payloadFileUri' => $payloadFileUri,
			]
		);

		return $this->fabricateUrn(seed: $participantId . ':' . $documentType . ':' . $payloadFileUri);
	}//end submit()

	/**
	 * Submit a UBL 2.1 Order to the Peppol network (PO-specific alias surface).
	 *
	 * Preserves the exact PO call-site contract (raw XML in, URN out) — never
	 * logs the document body, only its length.
	 *
	 * @param string $participantId The recipient Peppol participant identifier.
	 * @param string $ublOrderXml The UBL order XML payload.
	 *
	 * @return string The transmission identifier.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
	 *
	 * @inheritDoc
	 */
	public function submitOrder(string $participantId, string $ublOrderXml): string {
		// Length only — never log the UBL body (may contain supplier PII).
		$this->logger->info(
			'shillinq.peppol.submit',
			[
				'participantId' => $participantId,
				'ublLength' => strlen($ublOrderXml),
			]
		);

		return $this->fabricateUrn(seed: $participantId . ':' . $ublOrderXml);
	}//end submitOrder()

	/**
	 * Deterministic URN derived from a seed string so the dev adapter remains
	 * reproducible across reruns (helps golden-file / snapshot tests).
	 *
	 * @param string $seed Value to hash.
	 *
	 * @return string A `urn:uuid:...`-shaped identifier.
	 */
	private function fabricateUrn(string $seed): string {
		$hash = substr(hash('sha256', $seed), 0, 32);

		return sprintf(
			'urn:uuid:%s-%s-%s-%s-%s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			substr($hash, 12, 4),
			substr($hash, 16, 4),
			substr($hash, 20, 12)
		);

	}//end fabricateUrn()

	/**
	 * Look up a `peppolParticipantId` on one OR schema, filtered by administration
	 * + party id.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $partyId Party id to match.
	 * @param string $schema OR schema slug to search.
	 * @param string $idField The row field that carries the party id
	 *                        (`id` for Vendor, `customerId` for CustomerMaster).
	 *
	 * @return string|null
	 */
	private function lookupInSchema(string $administrationId, string $partyId, string $schema, string $idField): ?string {
		try {
			$register = $this->register();
			$rows = $this->objectService
				->setRegister($register)
				->setSchema($schema)
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							$idField => $partyId,
						],
					]
				);
		} catch (\Throwable $e) {
			$this->logger->info(
				'shillinq.peppol.lookup.skipped',
				[
					'schema' => $schema,
					'reason' => 'schema_unavailable',
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$participantId = trim((string)($row['peppolParticipantId'] ?? ''));
			if ($participantId !== '') {
				return $participantId;
			}
		}

		return null;
	}//end lookupInSchema()

	/**
	 * Resolve the OpenRegister register slug from app config (defaults to "shillinq").
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
