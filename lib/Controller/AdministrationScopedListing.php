<?php

/**
 * Administration-scoped listing
 *
 * One OpenRegister query per administration the caller is a member of,
 * merged into a `{data, total}` list answer.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Container\ContainerInterface;

/**
 * Listing scoped to the caller's administrations (ADR-005 / REQ-MA-001).
 *
 * Requires the using class to hold `$container` and `$context`.
 *
 * @property ContainerInterface $container
 * @property AdministrationContextService $context
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 */
trait AdministrationScopedListing {

	/**
	 * The rows matching the filter in every administration the caller is a
	 * member of, one query per administration, in membership order. A caller
	 * with no memberships gets an empty scope and therefore no rows.
	 *
	 * @param string               $schema The schema slug in the shillinq register.
	 * @param array<string, mixed> $filter The filter, without the administration term.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	private function scopedRows(string $schema, array $filter): array {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$rows = [];
		foreach ($this->context->accessibleAdministrationIds() as $administrationId) {
			$found = $objectService
				->setRegister(register: 'shillinq')
				->setSchema(schema: $schema)
				->findAll(['filters' => $filter + ['administrationId' => $administrationId]]);
			$rows = array_merge($rows, $found);
		}

		return $rows;
	}//end scopedRows()

	/**
	 * The 200 list answer.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return JSONResponse
	 */
	private static function listed(array $rows): JSONResponse {
		return new JSONResponse(['data' => $rows, 'total' => count($rows)], Http::STATUS_OK);
	}//end listed()
}//end trait
