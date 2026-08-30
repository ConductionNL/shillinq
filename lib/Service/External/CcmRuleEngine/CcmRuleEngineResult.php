<?php

/**
 * Result value-object returned by a CcmRuleEngineAdapter call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\CcmRuleEngine
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

namespace OCA\Shillinq\Service\External\CcmRuleEngine;

/**
 * Result of a CCM rule compilation / evaluation attempt.
 *
 * `status` is one of `COMPILED`, `EVALUATED`, `RULE_FIRED`,
 * `RULE_NOT_FIRED`, `DEFERRED`, `COMPILE_ERROR`, `EVAL_ERROR`. The
 * compile / evaluate codes track the OpenRegister rule-engine state
 * machine 1:1; `DEFERRED` is the dormant default so callers can persist
 * a non-null evaluation reference even when no outbound call took place.
 *
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 */
final class CcmRuleEngineResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $status Engine-side state.
	 * @param string $evaluationId Engine-side opaque id of
	 *                             the evaluation
	 *                             (synthetic for dormant).
	 * @param bool $fired TRUE if the rule fired
	 *                    (only meaningful on
	 *                    EVALUATED / RULE_FIRED
	 *                    / RULE_NOT_FIRED).
	 * @param array<string,mixed> $diagnostics Engine-emitted diagnostics
	 *                                         (operator path, captured
	 *                                         field values, fail-soft
	 *                                         note) — the structure
	 *                                         the local CcmRuleEngine
	 *                                         already returns from its
	 *                                         AST walker.
	 * @param bool $dormant TRUE when the adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific extras
	 *                                    (engine version, AST
	 *                                    cache key, latency).
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $evaluationId,
		public readonly bool $fired,
		public readonly array $diagnostics,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
