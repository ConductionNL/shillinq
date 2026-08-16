<?php

/**
 * Minimal OCA\OpenRegister\AppHost\IMetricsProvider stub for unit tests.
 *
 * Mirrors the real OpenRegister AppHost interface so the unit suite can
 * typehint and assert the provider contract WITHOUT a full OpenRegister
 * install. When run inside a deployed NC tree with OpenRegister enabled,
 * base.php provides the real interface and this stub is shadowed.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

use OCA\OpenRegister\AppHost\Observability\MetricSample;

/**
 * Stub for OCA\OpenRegister\AppHost\IMetricsProvider.
 */
interface IMetricsProvider {

	/**
	 * Produce the provider's metric samples.
	 *
	 * @return MetricSample[] The provider's samples.
	 */
	public function metrics(): array;
}//end interface
