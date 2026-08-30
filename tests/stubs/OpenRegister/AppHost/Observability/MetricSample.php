<?php

/**
 * Minimal OCA\OpenRegister\AppHost\Observability\MetricSample stub for unit tests.
 *
 * Mirrors the real OpenRegister AppHost value object (name/type/help/samples
 * plus the single() convenience factory) so the unit suite can build and
 * assert provider samples WITHOUT a full OpenRegister install. When run inside
 * a deployed NC tree with OpenRegister enabled, base.php provides the real
 * class and this stub is shadowed.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

/**
 * Stub for OCA\OpenRegister\AppHost\Observability\MetricSample.
 */
final class MetricSample {

	/**
	 * Constructor.
	 *
	 * @param string $name Metric name (without `{app}_` prefix).
	 * @param string $type Prometheus type (gauge|counter).
	 * @param string $help HELP text.
	 * @param array<int, array{labels: array<string,string>, value: float|int}> $samples Labelled samples.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly string $help,
		public readonly array $samples,
	) {
	}//end __construct()

	/**
	 * Convenience factory for a single unlabelled sample.
	 *
	 * @param string $name Metric name.
	 * @param string $type Prometheus type.
	 * @param string $help HELP text.
	 * @param float|int $value Sample value.
	 * @param array<string, string> $labels Optional labels.
	 *
	 * @return self
	 */
	public static function single(string $name, string $type, string $help, float|int $value, array $labels = []): self {
		return new self(
			name: $name,
			type: $type,
			help: $help,
			samples: [['labels' => $labels, 'value' => $value]]
		);
	}//end single()
}//end class
