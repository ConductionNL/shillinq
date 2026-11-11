<?php

/**
 * Subject Reference
 *
 * The finance object a concluded cross-app outcome (signing request,
 * governance decision) points at: its schema and its id.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

/**
 * Schema and id of a finance object named by a concluded outcome.
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
final class SubjectRef {

	/**
	 * Hold the schema and id.
	 *
	 * @param string $schema The finance schema slug.
	 * @param string $id     The finance object id.
	 */
	public function __construct(
		public readonly string $schema,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
