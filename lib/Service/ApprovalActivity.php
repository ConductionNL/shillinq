<?php

/**
 * One approval-lifecycle Activity event, ready to publish.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The fields ApprovalActivityEmitter publishes for one lifecycle transition.
 *
 * @spec openspec/specs/bookkeeping-rekenkamer-audit-pack/spec.md
 */
final class ApprovalActivity {

	/**
	 * Construct one event.
	 *
	 * @param string              $event      Event subject id (REQ-RAP-006 table).
	 * @param string              $objectType OR schema slug.
	 * @param string              $objectId   Object UUID.
	 * @param string              $actorUid   Actor UID ('' = current session).
	 * @param string              $summary    1-line human summary.
	 * @param array<string,mixed> $parameters Subject parameters.
	 */
	public function __construct(
		public readonly string $event,
		public readonly string $objectType,
		public readonly string $objectId,
		public readonly string $actorUid,
		public readonly string $summary,
		public readonly array $parameters,
	) {
	}//end __construct()
}//end class
