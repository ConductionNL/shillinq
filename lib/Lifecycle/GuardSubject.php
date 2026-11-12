<?php

/**
 * Guard Subject
 *
 * The object a lifecycle guard checks: its OpenRegister schema and its id.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * Schema and id of the object under transition.
 *
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 */
final class GuardSubject {

	/**
	 * Hold the schema and the id.
	 *
	 * @param string $schema The OpenRegister schema slug.
	 * @param string $id     The object id ('' when the engine supplied none).
	 */
	public function __construct(
		public readonly string $schema,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
