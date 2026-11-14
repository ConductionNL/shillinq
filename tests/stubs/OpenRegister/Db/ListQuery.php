<?php

/**
 * Minimal ListQuery stub mirroring OCA\OpenRegister\Db\ListQuery on openregister main (lib/Db/ListQuery.php).
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for OCA\OpenRegister\Db\ListQuery used by shillinq tests.
 */
final class ListQuery {
	/**
	 * Constructor.
	 *
	 * @param int                  $limit   Page size.
	 * @param int|null             $offset  Offset.
	 * @param array<string, mixed> $filters Column filters.
	 * @param array<string, mixed> $sort    Sort per column.
	 * @param string|null          $search  Search term.
	 */
	public function __construct(
		public readonly int $limit=20,
		public readonly ?int $offset=null,
		public readonly array $filters=[],
		public readonly array $sort=[],
		public readonly ?string $search=null,
	) {
	}//end __construct()
}//end class
