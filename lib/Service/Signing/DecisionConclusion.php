<?php

/**
 * Decision Conclusion
 *
 * A decidesk approved or rejected outcome for a finance object: the outcome
 * and decision id, the accounting consequence and the Activity object type.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Signing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-delegation-via-events/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Signing;

/**
 * One concluded governance decision, as decidesk reported it.
 *
 * @spec openspec/changes/shillinq-delegation-via-events/tasks.md
 */
final class DecisionConclusion {

	/**
	 * Called with the updated object on 'approved' to fire the GL consequence.
	 *
	 * @var callable|null
	 */
	public $consequence = null;

	/**
	 * Finance schema slug used as the Activity object type; empty skips the emit.
	 *
	 * @var string
	 */
	public string $subjectSchema = '';

	/**
	 * Constructor.
	 *
	 * @param string $outcome     'approved' | 'rejected'.
	 * @param string $decisionRef The decidesk Decision id.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly string $decisionRef,
	) {

	}//end __construct()
}//end class
