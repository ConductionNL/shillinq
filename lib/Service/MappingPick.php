<?php

/**
 * Mapping Pick
 *
 * The running choice while {@see ConsolidationMappingService::pickMostRecent()}
 * walks the candidate ConsolidationMappings.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;

/**
 * The best applicable mapping seen so far.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-19
 */
class MappingPick {

	/**
	 * Reference date: mappings valid from later than this do not apply.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $asOf;

	/**
	 * The best mapping so far, or null.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $best = null;

	/**
	 * The best mapping's validFrom, or null while it is undated (or none).
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $bestStamp = null;
}//end class
