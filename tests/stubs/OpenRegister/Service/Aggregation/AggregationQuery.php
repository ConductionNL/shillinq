<?php

/**
 * Minimal unit-test stub of OpenRegister's AggregationQuery value object.
 *
 * Mirrors the public `create()` factory + readonly slots that
 * `SpendAnalyticsService` depends on. The real class lives in OpenRegister
 * (lib/Service/Aggregation/AggregationQuery.php) and is present at runtime
 * inside a deployed Nextcloud tree; this stub only exists so the leaf's unit
 * tests can build the query object without the whole engine on the classpath.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Stub AggregationQuery — value object with the create() factory used by the leaf.
 */
class AggregationQuery {
	/**
	 * Private constructor — use the static factory.
	 *
	 * @param string $metric Aggregation metric.
	 * @param string|null $field Numeric field (null for count).
	 * @param array<string,mixed> $filter Filter map.
	 * @param array<string,mixed>|null $groupBy Optional single-field groupBy spec {field: <name>}.
	 */
	private function __construct(
		public readonly string $metric,
		public readonly ?string $field,
		public readonly array $filter,
		public readonly ?array $groupBy,
	) {
	}//end __construct()

	/**
	 * Factory mirroring OR's real signature (dateBucket omitted — unused here).
	 *
	 * @param string $metric One of count/sum/avg/min/max.
	 * @param string|null $field Field for non-count metrics.
	 * @param array<string,mixed> $filter Filter map.
	 * @param array<string,mixed>|null $groupBy Optional {field: <name>}.
	 *
	 * @return self
	 */
	public static function create(
		string $metric,
		?string $field = null,
		array $filter = [],
		?array $groupBy = null,
	): self {
		return new self(metric: $metric, field: $field, filter: $filter, groupBy: $groupBy);
	}//end create()

	/**
	 * Single groupBy field or null — the ONLY grouping shape OR honours.
	 *
	 * @return string|null
	 */
	public function getGroupByField(): ?string {
		if ($this->groupBy === null) {
			return null;
		}

		$field = ($this->groupBy['field'] ?? null);
		if (is_string($field) === true) {
			return $field;
		}

		return null;
	}//end getGroupByField()
}//end class
