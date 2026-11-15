<?php

/**
 * Record Query
 *
 * One exact-match lookup in the shillinq register: the property filters and
 * an optional limit (0 = no explicit limit).
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
 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * Filters and limit of one exact-match register lookup.
 *
 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
 */
final class RecordQuery {

	/**
	 * Hold the filters and the limit.
	 *
	 * @param array<string, mixed> $filters Exact-match filters.
	 * @param int                  $limit   Maximum records to return (0 = no explicit limit).
	 */
	public function __construct(
		public readonly array $filters,
		public readonly int $limit = 0,
	) {
	}//end __construct()

	/**
	 * The findAll() configuration: the filters, plus the limit when one is set.
	 *
	 * @return array<string, mixed> The query.
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
	 */
	public function toConfig(): array {
		$query = ['filters' => $this->filters];
		if ($this->limit > 0) {
			$query['limit'] = $this->limit;
		}

		return $query;
	}//end toConfig()
}//end class
