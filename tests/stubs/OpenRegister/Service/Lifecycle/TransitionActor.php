<?php

/**
 * Test stub for OCA\OpenRegister\Service\Lifecycle\TransitionActor.
 *
 * Mirrors the constructor of the real value object in OpenRegister
 * (lib/Service/Lifecycle/TransitionActor.php).
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

final class TransitionActor {
	public function __construct(
		public readonly ?string $userId = null,
		public readonly bool $automatic = false,
	) {
	}
}
