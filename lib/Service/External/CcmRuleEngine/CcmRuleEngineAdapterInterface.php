<?php

/**
 * CCM rule-engine cross-app delegation port.
 *
 * Shillinq's `bookkeeping-ccm-rule-engine` capability ships a local
 * deterministic DSL compiler + AST evaluator (`lib/Service/CcmRuleEngine.php`)
 * because the OpenRegister platform does not yet expose a native
 * rule-expression engine — that local engine is the ADR-031 exception
 * the capability tolerates, with the explicit note that "when
 * OpenRegister gains a native rule-expression engine this service is
 * replaced by that declaration and deleted" (see CcmRuleEngine.php
 * docblock).
 *
 * This adapter port is the seam through which the swap will happen:
 * once the OpenRegister rule-engine endpoint (or a third-party engine
 * such as Drools / OpenA3) is bound via openconnector, the production
 * binding delegates compileRule + evaluate to that endpoint, and the
 * local `CcmRuleEngine` is dropped from the dependency graph without
 * touching the CCM domain (FindingService, scheduled materialisation
 * workflows, audit-committee report assembly).
 *
 * Until that binding is configured, the default binding is dormant: it
 * logs the compile / evaluate intent and returns a synthetic DEFERRED
 * outcome. The surrounding lifecycle (finding triage, async sweep,
 * SoD materialisation) stays observable because the LOCAL
 * `CcmRuleEngine` is unaffected — the adapter port is an OVERLAY: real
 * sync/async evaluation continues to go through the local engine for
 * v1, and a tenant that flips on the openconnector binding gets the
 * cross-app evaluator as an additive path.
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
 * CCM rule-engine cross-app delegation port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is
 * set; a dormant adapter records the intent (logger, audit trail) and
 * returns a synthetic DEFERRED outcome so the surrounding orchestration
 * can advance without contacting the cross-app engine.
 *
 * Activation steps for a real binding:
 *  1. Bind the OpenRegister rule-engine endpoint (or third-party
 *     evaluator) as an openconnector source — slug
 *     `ccm-rule-engine`, pointing at the engine's compile + evaluate
 *     URLs (e.g. `/api/rule-engine/compile`,
 *     `/api/rule-engine/evaluate`). Store the per-tenant API key.
 *  2. Override the CcmRuleEngineAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *  3. Optionally retire the local
 *     `OCA\Shillinq\Service\CcmRuleEngine` once the cross-app engine
 *     is at parity (DSL grammar, AST cache, latency SLA per REQ-CCM-002:
 *     ≤100ms p95 sync).
 *
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 */
interface CcmRuleEngineAdapterInterface {
	/**
	 * Compile a CCM rule DSL document into an engine-side AST handle.
	 *
	 * The handle is opaque to the caller — the engine MAY return a
	 * URN, a cache key, or the AST JSON itself. The caller stores it
	 * on the `CcmRule.compiledAstHandle` field for later reuse by
	 * evaluate().
	 *
	 * @param string $ruleCode CcmRule.ruleCode (audit
	 *                         correlation key).
	 * @param array<string,mixed> $ruleLogic Rule DSL document — the
	 *                                       same JSON shape the local
	 *                                       CcmRuleEngine accepts.
	 *
	 * @return CcmRuleEngineResult The compile outcome (status +
	 *                             evaluationId carries the AST handle).
	 */
	public function compileRule(string $ruleCode, array $ruleLogic): CcmRuleEngineResult;

	/**
	 * Evaluate a previously-compiled rule against a transaction
	 * context.
	 *
	 * @param string $astHandle Engine-side AST handle
	 *                          returned by compileRule().
	 * @param array<string,mixed> $transactionContext Transaction shape — the
	 *                                                journal-entry /
	 *                                                user-function-assignment
	 *                                                envelope the local
	 *                                                CcmRuleEngine already
	 *                                                accepts.
	 *
	 * @return CcmRuleEngineResult The evaluation outcome (status +
	 *                             fired flag + diagnostics).
	 */
	public function evaluate(string $astHandle, array $transactionContext): CcmRuleEngineResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * the cross-app engine.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
