<?php

/**
 * Requisition Conversion Guard
 *
 * ADR-031 lifecycle guard for the Requisition `convertToPO` transition
 * (approved -> converted). Single-method guard, mirroring
 * AansluitingResolutionGuard's precedent: the declarative
 * x-openregister-lifecycle transition references this guard for defence in
 * depth (so a direct call to the generic OR transition endpoint cannot flip
 * a non-approved Requisition to converted), while the actual PO
 * materialisation — a genuine cross-schema write with an id written back —
 * is performed imperatively by RequisitionConversionService.
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
 * @spec openspec/specs/purchase-requisition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Precondition guard for the Requisition `convertToPO` transition (REQ-REQ-005).
 *
 * Fail-closed: any error or non-approved status denies the conversion (CWE-863).
 *
 * @spec openspec/specs/purchase-requisition/spec.md
 */
class RequisitionConversionGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Precondition for the `convertToPO` transition: is the requisition
	 * approved (REQ-REQ-005)?
	 *
	 * Fail-closed: returns false on any exception or when the requisition is
	 * missing or not in status 'approved'.
	 *
	 * @param string $requisitionId The requisition identifier (lifecycle-engine call parity).
	 * @param array<string,mixed>|null $object The Requisition object being transitioned.
	 *
	 * @return bool True when the requisition may be converted.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function canConvert(string $requisitionId, ?array $object = null): bool {
		try {
			$requisition = ($object ?? $this->findOne(schema: 'Requisition', filters: ['id' => $requisitionId]));
			if ($requisition === null) {
				return false;
			}

			return (string)($requisition['statusCode'] ?? '') === 'approved';
		} catch (\Throwable $e) {
			$this->logger->error(
				'RequisitionConversionGuard: canConvert failed — denying conversion (fail-closed)',
				['requisitionId' => $requisitionId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canConvert()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
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

	/**
	 * Find a single record by exact-match filters in the configured register.
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $filters Exact-match filters.
	 *
	 * @return array<string, mixed>|null First matching record, or null.
	 */
	private function findOne(string $schema, array $filters): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$result = $objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: $schema)
				->findAll(['filters' => $filters, 'limit' => 1]);

			if (is_array($result) === false || count($result) === 0) {
				return null;
			}

			return reset($result);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'RequisitionConversionGuard: schema lookup unavailable — treating as absent',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end findOne()
}//end class
