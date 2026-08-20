<?php

/**
 * Supplier Qualification Service
 *
 * Generic supplier onboarding/qualification, abstracted from the retired
 * purchaseq `supplier-onboarding-vragenlijst` slug (purchaseq#5) as a
 * jurisdiction-neutral control. Registers a supplier (rejecting a duplicate on
 * taxId or IBAN, REQ-PG-003) and qualifies it once every required document is
 * provided and unexpired (REQ-PG-002). Persists via OpenRegister's ObjectService
 * (ADR-022); money-free onboarding record.
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
 * @spec openspec/specs/procurement-governance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * OpenRegister-backed supplier qualification service.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @spec openspec/specs/procurement-governance/spec.md
 */
class SupplierQualificationService {
	/**
	 * Construct the service with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param AdministrationContextService $administrationContext Tenant access/identity resolver.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Register a supplier for qualification, rejecting a duplicate on taxId or
	 * IBAN (REQ-PG-003).
	 *
	 * @param string $administrationId Administration (tenant) scope.
	 * @param array<string,mixed> $payload supplierId, supplierName, taxId, iban, requiredDocuments[].
	 *
	 * @return array<string,mixed> The persisted SupplierQualification (statusCode=draft).
	 *
	 * @throws RuntimeException On missing access, missing fields, or a duplicate taxId/IBAN.
	 *
	 * @spec openspec/specs/procurement-governance/spec.md
	 */
	public function registerSupplier(string $administrationId, array $payload): array {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			// Mask as not-found per ADR-005 (avoid disclosing other tenants).
			throw new RuntimeException('Administration not found');
		}

		$supplierId = trim((string)($payload['supplierId'] ?? ''));
		if ($supplierId === '') {
			throw new RuntimeException('supplierId is required');
		}

		$supplierName = trim((string)($payload['supplierName'] ?? ''));
		if ($supplierName === '') {
			throw new RuntimeException('supplierName is required');
		}

		$taxId = trim((string)($payload['taxId'] ?? ''));
		$iban = trim((string)($payload['iban'] ?? ''));

		$this->assertNoDuplicate(administrationId: $administrationId, taxId: $taxId, iban: $iban);

		$record = [
			'administrationId' => $administrationId,
			'supplierId' => $supplierId,
			'supplierName' => $supplierName,
			'taxId' => $taxId,
			'iban' => $iban,
			'requiredDocuments' => $this->normaliseDocuments(rawDocuments: (array)($payload['requiredDocuments'] ?? [])),
			'statusCode' => 'draft',
		];

		return $this->saveObject(schema: 'SupplierQualification', object: $record);
	}//end registerSupplier()

	/**
	 * Qualify a draft supplier once every required document is provided and
	 * unexpired (REQ-PG-002).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $supplierId Supplier reference.
	 * @param string $approverId User qualifying the supplier.
	 *
	 * @return array<string,mixed> The updated SupplierQualification (statusCode=qualified).
	 *
	 * @throws RuntimeException When the supplier is missing, or a required document is missing/expired.
	 *
	 * @spec openspec/specs/procurement-governance/spec.md
	 */
	public function qualify(string $administrationId, string $supplierId, string $approverId): array {
		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Administration not found');
		}

		$record = $this->findOne(
			schema: 'SupplierQualification',
			filters: ['administrationId' => $administrationId, 'supplierId' => $supplierId]
		);
		if ($record === null) {
			throw new RuntimeException('Supplier qualification not found');
		}

		if ($this->documentsValid(record: $record) === false) {
			throw new RuntimeException('Cannot qualify — a required document is missing or expired.');
		}

		$record['statusCode'] = 'qualified';
		$record['qualifiedAt'] = date('c');
		$record['qualifiedBy'] = $approverId;

		return $this->saveObject(schema: 'SupplierQualification', object: $record);
	}//end qualify()

	/**
	 * Reject registration when a SupplierQualification with the same taxId or
	 * IBAN already exists in the administration (REQ-PG-003).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $taxId Tax/VAT id (skipped when empty).
	 * @param string $iban IBAN (skipped when empty).
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a duplicate exists.
	 */
	private function assertNoDuplicate(string $administrationId, string $taxId, string $iban): void {
		if ($taxId !== '') {
			$dup = $this->findOne(
				schema: 'SupplierQualification',
				filters: ['administrationId' => $administrationId, 'taxId' => $taxId]
			);
			if ($dup !== null) {
				throw new RuntimeException('A supplier with this tax ID already exists.');
			}
		}

		if ($iban !== '') {
			$dup = $this->findOne(
				schema: 'SupplierQualification',
				filters: ['administrationId' => $administrationId, 'iban' => $iban]
			);
			if ($dup !== null) {
				throw new RuntimeException('A supplier with this IBAN already exists.');
			}
		}

	}//end assertNoDuplicate()

	/**
	 * Are all required documents provided and unexpired?
	 *
	 * @param array<string,mixed> $record The SupplierQualification record.
	 *
	 * @return bool
	 */
	private function documentsValid(array $record): bool {
		$documents = ($record['requiredDocuments'] ?? []);
		if (is_array($documents) === false) {
			return false;
		}

		$today = date('Y-m-d');
		foreach ($documents as $document) {
			if (is_array($document) === false || ($document['provided'] ?? false) !== true) {
				return false;
			}

			$expiresAt = trim((string)($document['expiresAt'] ?? ''));
			if ($expiresAt !== '' && $expiresAt < $today) {
				return false;
			}
		}

		return true;
	}//end documentsValid()

	/**
	 * Normalise raw required-document entries.
	 *
	 * @param array<int,mixed> $rawDocuments Raw document entries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function normaliseDocuments(array $rawDocuments): array {
		$documents = [];
		foreach ($rawDocuments as $raw) {
			if (is_array($raw) === false) {
				continue;
			}

			$documents[] = [
				'documentType' => trim((string)($raw['documentType'] ?? '')),
				'provided' => (bool)($raw['provided'] ?? false),
				'expiresAt' => trim((string)($raw['expiresAt'] ?? '')),
			];
		}

		return $documents;
	}//end normaliseDocuments()

	/**
	 * Persist an object via the real ObjectService API.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object Object payload.
	 *
	 * @return array<string,mixed>
	 */
	private function saveObject(string $schema, array $object): array {
		try {
			$result = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->saveObject($object);

			// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
			// is_array() arm here was unreachable by type and this helper returned
			// the INPUT on every save — silently discarding the id/uuid the store
			// had just generated, which callers then read back as empty.
			return (array)$result->jsonSerialize();
		} catch (\Throwable $e) {
			$this->logger->error(
				'SupplierQualificationService: failed to persist object',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist ' . $schema);
		}

	}//end saveObject()

	/**
	 * Fetch one record via the real ObjectService API (findAll then first).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, array $filters): ?array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SupplierQualificationService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return null;
		}

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findOne()

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
