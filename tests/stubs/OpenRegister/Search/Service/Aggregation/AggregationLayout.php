<?php

/**
 * Minimal unit-test stub of OpenRegister's AggregationLayout value object.
 *
 * Mirrors the real class (lib/Search/Service/Aggregation/AggregationLayout.php):
 * the grouping, date-bucket, metric-list and running-total part of an
 * aggregation request, passed to `AggregationQuery::create(layout: ...)`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

/**
 * Stub AggregationLayout — the layout slots of an aggregation request.
 */
final class AggregationLayout {
	/**
	 * Lay out a request.
	 *
	 * @param array<string,mixed>|null $groupBy Optional grouping spec.
	 * @param array<string,mixed>|null $dateBucket Optional date-bucket spec.
	 * @param array<int,array<string,mixed>>|null $metrics Optional multi-metric list.
	 * @param bool $cumulative Optional running-total flag.
	 */
	public function __construct(
		public readonly ?array $groupBy = null,
		public readonly ?array $dateBucket = null,
		public readonly ?array $metrics = null,
		public readonly bool $cumulative = false,
	) {
	}//end __construct()
}//end class
