<?php

/**
 * ObjectSelection stub mirroring OCA\OpenRegister\Kernel\Contracts\ObjectSelection (openregister #9103).
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\ObjectSelection used by shillinq tests.
 */
final class ObjectSelection {
	/**
	 * Constructor.
	 *
	 * @param array|null  $ids  Object ids or uuids to restrict to.
	 * @param string|null $uses Only objects that use this object.
	 */
	public function __construct(
		public readonly ?array $ids=null,
		public readonly ?string $uses=null,
	) {
	}//end __construct()
}//end class
