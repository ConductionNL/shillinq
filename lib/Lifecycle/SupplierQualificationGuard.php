<?php

/**
 * Supplier Qualification Guard
 *
 * Blocks a PurchaseOrder to a supplier that is not qualified. A generic,
 * jurisdiction-neutral procurement-governance control abstracted from the
 * retired purchaseq `supplier-onboarding-vragenlijst` slug (purchaseq#5): a
 * supplier must hold a `qualified` SupplierQualification with every required
 * document provided and unexpired before its first PurchaseOrder, when the
 * policy `require_supplier_qualification_for_po` is enabled (REQ-PG-002).
 *
 * Reads via OpenRegister's ObjectService (ADR-022); re-implements no business
 * logic that lives elsewhere (ADR-031). Fail-closed: any unresolved check
 * denies the PurchaseOrder (CWE-863).
 *
 * @category Guard
 * @package  OCA\Shillinq\Lifecycle
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

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Precondition for PurchaseOrder creation: is the supplier qualified?
 *
 * @spec openspec/specs/procurement-governance/spec.md
 */
class SupplierQualificationGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Assert the supplier is qualified for a PurchaseOrder, or throw (REQ-PG-002).
	 *
	 * Fail-closed: throws when the supplier has no `qualified` record, when a
	 * required document is missing/expired, or when the check itself cannot be
	 * resolved.
	 *
	 * @param string $administrationId Administration (tenant) scope.
	 * @param string $supplierId Supplier reference.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the supplier is not qualified for a PurchaseOrder.
	 *
	 * @spec openspec/specs/procurement-governance/spec.md
	 */
	public function assertQualifiedForPo(string $administrationId, string $supplierId): void {
		if ($this->isQualifiedForPo(administrationId: $administrationId, supplierId: $supplierId) === false) {
			$this->logger->info(
				'SupplierQualificationGuard: supplier not qualified — blocking purchase order',
				['administrationId' => $administrationId, 'supplierId' => $supplierId]
			);
			throw new RuntimeException('Supplier is not qualified for a purchase order.');
		}

	}//end assertQualifiedForPo()

	/**
	 * Is the supplier qualified for a PurchaseOrder (REQ-PG-002)?
	 *
	 * True only when a `qualified` SupplierQualification exists for the supplier
	 * and every required document is provided and unexpired. Fail-closed: returns
	 * false on any exception.
	 *
	 * @param string $administrationId Administration (tenant) scope.
	 * @param string $supplierId Supplier reference.
	 *
	 * @return bool True when the supplier may receive a PurchaseOrder.
	 *
	 * @spec openspec/specs/procurement-governance/spec.md
	 */
	public function isQualifiedForPo(string $administrationId, string $supplierId): bool {
		try {
			$record = $this->findOne(
				schema: 'SupplierQualification',
				filters: [
					'administrationId' => $administrationId,
					'supplierId' => $supplierId,
				]
			);

			if ($record === null) {
				return false;
			}

			if ((string)($record['statusCode'] ?? '') !== 'qualified') {
				return false;
			}

			return $this->documentsValid(record: $record);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SupplierQualificationGuard: qualification check failed — denying (fail-closed)',
				['supplierId' => $supplierId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isQualifiedForPo()

	/**
	 * Are all required documents provided and unexpired?
	 *
	 * @param array<string,mixed> $record The SupplierQualification record.
	 *
	 * @return bool True when every required document is valid.
	 */
	private function documentsValid(array $record): bool {
		$documents = ($record['requiredDocuments'] ?? []);
		if (is_array($documents) === false) {
			return false;
		}

		$today = date('Y-m-d');
		foreach ($documents as $document) {
			if (is_array($document) === false) {
				return false;
			}

			if (($document['provided'] ?? false) !== true) {
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
	 * Fetch one record via the real ObjectService API (findAll then first).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, array $filters): ?array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->findAll(['filters' => $filters]);

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
