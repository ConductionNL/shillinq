<?php

/**
 * Test stub for OCA\OpenRegister\Service\Lifecycle\TransitionMove.
 *
 * Mirrors the constructor of the real value object in OpenRegister
 * (lib/Service/Lifecycle/TransitionMove.php).
 *
 * @category Test
 * @package  OCA\OpenRegister
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

namespace OCA\OpenRegister\Service\Lifecycle;

final class TransitionMove {
	public function __construct(
		public readonly string $action,
		public readonly string $from,
		public readonly string $to,
	) {
	}
}
