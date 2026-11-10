<?php

/**
 * ObjectFindOptions stub mirroring OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions (openregister #9116).
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions used by shillinq tests.
 */
final class ObjectFindOptions {
	/**
	 * Constructor.
	 *
	 * @param RegisterInterface|string|int|null $register Register id, uuid or slug.
	 * @param SchemaInterface|string|int|null   $schema   Schema id, uuid or slug.
	 * @param array|null                        $extend   Relations to expand.
	 * @param bool                              $files    Include file metadata.
	 * @param bool                              $render   Render the entity.
	 * @param bool                              $audit    Write a read audit entry.
	 */
	public function __construct(
		public readonly RegisterInterface|string|int|null $register=null,
		public readonly SchemaInterface|string|int|null $schema=null,
		public readonly ?array $extend=[],
		public readonly bool $files=false,
		public readonly bool $render=true,
		public readonly bool $audit=true,
	) {
	}//end __construct()
}//end class
