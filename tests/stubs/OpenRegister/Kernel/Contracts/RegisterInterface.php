<?php

/**
 * Marker for test doubles that stand in for an OpenRegister register row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

if (interface_exists(__NAMESPACE__ . '\\RegisterInterface', false) === false) {
	/**
	 * The real interface declares the row's accessors; the doubles only need the type.
	 */
	interface RegisterInterface {
	}
}
