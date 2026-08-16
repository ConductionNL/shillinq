<?php

/**
 * Administration Audit Trail Service
 *
 * Administratie-scoped audit-trail aggregation (REQ-MA-009 / Task 22). Two
 * query shapes ship here:
 *
 *  - `queryForAdministration()` — single-administratie audit trail. The
 *    user must have a valid AdministrationMembership for the
 *    administration; non-membership is reported as null (the caller masks
 *    as a 404 per REQ-MA-001).
 *
 *  - `queryAcrossAccessibleAdministrations()` — cross-administratie
 *    aggregation for holding-controllers: pulls the audit trail from each
 *    administratie the caller has access to (via
 *    `AdministrationContextService::accessibleAdministrationIds`), tags
 *    each row with its `administrationId`, and merges into a single
 *    result list sorted by timestamp. No administratie the user has no
 *    membership for is ever touched.
 *
 * The actual auditTrail records are stored alongside each entity by
 * OpenRegister; this service is a read-side aggregator. The schema
 * iterated over is configurable so the caller can scope the cross-tenant
 * roll-up to one entity type (e.g. only GLTransaction audit rows) without
 * pulling the entire register.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aggregates audit-trail rows across the user's accessible administrations
 * (REQ-MA-009 / REQ-MA-001).
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-22
 */
class AdministrationAuditTrailService {
	/**
	 * Default page size when the caller does not specify one.
	 */
	private const DEFAULT_PAGE_SIZE = 100;

	/**
	 * Default per-administratie result cap to keep cross-tenant queries
	 * bounded; the caller raises explicitly if needed.
	 */
	private const DEFAULT_PER_ADMINISTRATION_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched
	 *                                      lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param AdministrationContextService $context Multi-tenant RBAC resolver.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Query the audit trail for a single administration (REQ-MA-009).
	 *
	 * Returns null when the caller has no AdministrationMembership for the
	 * target administration — the caller MUST mask this as a 404 (never 403)
	 * to avoid leaking tenant existence (REQ-MA-001).
	 *
	 * @param string $administrationId The administration to query.
	 * @param string $schema The schema to pull audit rows from.
	 * @param int $limit Per-call result cap (defaults to 100).
	 *
	 * @return array<int,array<string,mixed>>|null
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-22
	 */
	public function queryForAdministration(
		string $administrationId,
		string $schema,
		int $limit = self::DEFAULT_PAGE_SIZE,
	): ?array {
		if ($administrationId === '' || $schema === '') {
			return null;
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return null;
		}

		$rows = $this->fetchByAdministration(
			administrationId: $administrationId,
			schema: $schema,
			limit: $limit
		);

		return $this->sortByTimestampDesc(rows: $this->tagWithAdministration(rows: $rows, administrationId: $administrationId));
	}//end queryForAdministration()

	/**
	 * Aggregate the audit trail across every administration the caller may access
	 * (REQ-MA-009).
	 *
	 * Each row in the result carries an explicit `administrationId` tag so the
	 * caller (e.g. a holding-controller dashboard) can render or filter per
	 * administratie. The aggregation respects the user's membership boundary —
	 * no row from an administratie outside `accessibleAdministrationIds()` is
	 * ever included (REQ-MA-001).
	 *
	 * @param string $schema The schema to pull audit rows from.
	 * @param int $perAdministrationLimit Per-administratie row cap (defaults to 500).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-22
	 */
	public function queryAcrossAccessibleAdministrations(
		string $schema,
		int $perAdministrationLimit = self::DEFAULT_PER_ADMINISTRATION_LIMIT,
	): array {
		if ($schema === '') {
			return [];
		}

		$aggregated = [];
		foreach ($this->context->accessibleAdministrationIds() as $administrationId) {
			$rows = $this->fetchByAdministration(
				administrationId: $administrationId,
				schema: $schema,
				limit: $perAdministrationLimit
			);
			$aggregated = array_merge(
				$aggregated,
				$this->tagWithAdministration(rows: $rows, administrationId: $administrationId)
			);
		}

		return $this->sortByTimestampDesc(rows: $aggregated);
	}//end queryAcrossAccessibleAdministrations()

	/**
	 * Tag a list of audit rows with the administration they were pulled from.
	 *
	 * Pure helper — exposed for unit testing. Any row already carrying an
	 * `administrationId` value is left as-is so we never overwrite a
	 * server-authoritative tag.
	 *
	 * @param array<int,array<string,mixed>> $rows Audit rows.
	 * @param string $administrationId The owning administration.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-22
	 */
	public function tagWithAdministration(array $rows, string $administrationId): array {
		$tagged = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			if (array_key_exists('administrationId', $row) === false || ((string)$row['administrationId']) === '') {
				$row['administrationId'] = $administrationId;
			}

			$tagged[] = $row;
		}

		return $tagged;
	}//end tagWithAdministration()

	/**
	 * Sort audit rows by timestamp (newest first) for the aggregated view.
	 *
	 * Picks `auditTrailUpdatedAt` first, then `updatedAt`, then `createdAt`;
	 * rows without any timestamp slot sort to the end (stable order). Pure
	 * helper exposed for unit testing.
	 *
	 * @param array<int,array<string,mixed>> $rows Audit rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function sortByTimestampDesc(array $rows): array {
		$candidateKeys = ['auditTrailUpdatedAt', 'updatedAt', 'createdAt'];

		usort(
			$rows,
			static function ($left, $right) use ($candidateKeys): int {
				$leftStamp = '';
				$rightStamp = '';
				foreach ($candidateKeys as $key) {
					if ($leftStamp === '' && is_array($left) === true) {
						$leftStamp = (string)($left[$key] ?? '');
					}

					if ($rightStamp === '' && is_array($right) === true) {
						$rightStamp = (string)($right[$key] ?? '');
					}
				}

				if ($leftStamp === '' && $rightStamp === '') {
					return 0;
				}

				if ($leftStamp === '') {
					return 1;
				}

				if ($rightStamp === '') {
					return -1;
				}

				return strcmp($rightStamp, $leftStamp);
			}
		);

		return $rows;
	}//end sortByTimestampDesc()

	/**
	 * Fetch audit rows for a single administratie (storage call).
	 *
	 * @param string $administrationId The administration id.
	 * @param string $schema The schema slug.
	 * @param int $limit Result cap.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchByAdministration(string $administrationId, string $schema, int $limit): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$entities = $objectService
				->setRegister($this->resolveRegister())
				->setSchema($schema)
				->findAll(
					[
						'filters' => ['administrationId' => $administrationId],
						'limit' => $limit,
					]
				);
		} catch (Throwable $e) {
			$this->logger->error(
				'AdministrationAuditTrailService: failed to fetch audit rows',
				[
					'administrationId' => $administrationId,
					'schema' => $schema,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}//end try

		$rows = [];
		foreach ($entities as $entity) {
			if (is_array($entity) === true) {
				$rows[] = $entity;
				continue;
			}

			if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
				$data = $entity->getObject();
				if (is_array($data) === true) {
					$rows[] = $data;
				}
			}
		}

		return $rows;
	}//end fetchByAdministration()

	/**
	 * Resolve the configured register slug, defaulting to `shillinq`.
	 *
	 * @return string
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
