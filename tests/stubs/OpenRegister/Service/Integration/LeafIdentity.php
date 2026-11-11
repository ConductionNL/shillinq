<?php

/**
 * Test stub for OpenRegister's LeafIdentity.
 *
 * @category Stub
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

final class LeafIdentity {
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $icon,
		public readonly ?string $group = null,
	) {
	}
}
