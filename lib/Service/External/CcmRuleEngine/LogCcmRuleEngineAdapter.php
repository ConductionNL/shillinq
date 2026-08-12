<?php

/**
 * Dormant default CCM rule-engine adapter.
 *
 * Records the would-be compile / evaluate intent to the structured
 * logger and returns a synthetic DEFERRED result so the surrounding
 * orchestration (FindingService::createFinding, AsyncRuleSweepJob,
 * SoDMatrixMaterialisationJob) stays observable until the OpenRegister
 * native rule engine (or third-party evaluator) is wired in via
 * `Application::register()`. Mirrors the
 * `LogMolliePaymentAdapter` / `LogDigipoortSbrAdapter` dormant-default
 * pattern used across the Shillinq external surface.
 *
 * Note on overlay semantics: the local
 * `OCA\Shillinq\Service\CcmRuleEngine` is unaffected by this dormant
 * adapter — it continues to compile + evaluate rules in-process for
 * v1. The adapter port is the SWAP-OUT seam for the future cross-app
 * engine; the dormant default exists so the DI graph is complete the
 * moment a tenant enables the openconnector binding.
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

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed CCM rule-engine adapter.
 *
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 */
class LogCcmRuleEngineAdapter implements CcmRuleEngineAdapterInterface {
	/**
	 * Construct the log-backed CCM rule-engine adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the compile intent + synthesise a DEFERRED result.
	 *
	 * The rule DSL is logged in full because it is an audit artefact
	 * (per REQ-CCM-002 every rule change is auditable). Sensitive
	 * transaction-context values do not flow through compile().
	 *
	 * @param string $ruleCode CcmRule audit correlation key.
	 * @param array<string,mixed> $ruleLogic Rule DSL document.
	 *
	 * @return CcmRuleEngineResult The dispatch outcome.
	 */
	public function compileRule(string $ruleCode, array $ruleLogic): CcmRuleEngineResult {
		$astHandle = 'ccm_ast_log_' . bin2hex(random_bytes(7));
		$this->logger->info(
			'Shillinq CCM compileRule deferred (no outbound rule-engine connector bound)',
			[
				'astHandle' => $astHandle,
				'ruleCode' => $ruleCode,
				'ruleLogic' => $ruleLogic,
			]
		);

		return new CcmRuleEngineResult(
			status: 'DEFERRED',
			evaluationId: $astHandle,
			fired: false,
			diagnostics: [
				'note' => 'compile deferred; local CcmRuleEngine continues to compile in-process for v1.',
			],
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `ccm-rule-engine` (OpenRegister native rule-engine or '
					. 'third-party evaluator) and override CcmRuleEngineAdapterInterface in Application::register() '
					. 'to enable cross-app compile/evaluate.',
			],
		);
	}//end compileRule()

	/**
	 * Log the evaluate intent + synthesise a stub RULE_NOT_FIRED
	 * result.
	 *
	 * Per REQ-CCM-002 fail-soft semantics: every unexpected outcome
	 * defaults to "rule does not fire" so a deferred adapter NEVER
	 * raises a false finding. The local CcmRuleEngine implements
	 * the same fail-soft contract.
	 *
	 * @param string $astHandle AST handle from compileRule.
	 * @param array<string,mixed> $transactionContext Transaction shape.
	 *
	 * @return CcmRuleEngineResult Stubbed evaluation record.
	 */
	public function evaluate(string $astHandle, array $transactionContext): CcmRuleEngineResult {
		$evaluationId = 'ccm_eval_log_' . bin2hex(random_bytes(7));

		// Transaction context may carry vendor / posting / approver
		// identities — they go through unredacted because the audit
		// trail needs them to correlate the dormant evaluation back
		// to a Shillinq record once the live binding is provisioned.
		$this->logger->info(
			'Shillinq CCM evaluate deferred (no outbound rule-engine connector bound)',
			[
				'evaluationId' => $evaluationId,
				'astHandle' => $astHandle,
				'transactionContext' => $transactionContext,
			]
		);

		return new CcmRuleEngineResult(
			status: 'DEFERRED',
			evaluationId: $evaluationId,
			fired: false,
			diagnostics: [
				'failSoft' => true,
				'note' => 'evaluate deferred; rule treated as not-fired per fail-soft contract (REQ-CCM-002).',
			],
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `ccm-rule-engine` to enable cross-app evaluation. '
					. 'Until then, the local OCA\\Shillinq\\Service\\CcmRuleEngine carries v1 sync/async evaluation.',
			],
		);
	}//end evaluate()

	/**
	 * Report whether this adapter is dormant (logs only, no outbound connector).
	 *
	 * @inheritDoc
	 *
	 * @return bool Always true for the dormant log adapter.
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
