<?php

/**
 * Shillinq MCP payment-run tools
 *
 * @category Mcp
 * @package  OCA\Shillinq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Mcp;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRunExportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRunReconciliationServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `shillinq.payment_run.export_sepa|reconcile`: the service paths behind
 * `PaymentRunController::export` and `reconcile`, with the same refusals. The
 * run must belong to the administration the call names.
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */
class McpPaymentRunTools {

	/**
	 * The masked answer for a foreign or missing run, as the controller words it.
	 */
	public const NOT_FOUND = 'Not found';

	/**
	 * Constructor.
	 *
	 * @param McpAdministrationGate                    $gate           The authorisation ladder.
	 * @param PaymentRunExportServiceInterface         $export         Builds the SEPA credit-transfer file.
	 * @param PaymentRunReconciliationServiceInterface $reconciliation Matches a bank statement against the run.
	 * @param ObjectServiceInterface                   $objectService  Loads the run.
	 * @param LoggerInterface                          $logger         Logs an unexpected failure.
	 */
	public function __construct(
		private readonly McpAdministrationGate $gate,
		private readonly PaymentRunExportServiceInterface $export,
		private readonly PaymentRunReconciliationServiceInterface $reconciliation,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Export an approved payment run as a SEPA file for the bank (`PaymentRunController::export`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, paymentRunId.
	 *
	 * @return array<string, mixed> The export result, or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function exportSepa(array $args): array {
		return $this->onRun(
			action: 'export',
			args: $args,
			conflict: 'not-approved',
			run: fn (array $paymentRun): array => $this->export->export(paymentRun: $paymentRun)
		);
	}//end exportSepa()

	/**
	 * Reconcile an exported payment run against a bank statement (`PaymentRunController::reconcile`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, paymentRunId, contents.
	 *
	 * @return array<string, mixed> The reconciliation result, or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function reconcile(array $args): array {
		$contents = (string)$args['contents'];
		if (trim($contents) === '') {
			return McpResult::error(code: 'invalid_arguments', message: 'Empty statement — nothing to reconcile');
		}

		return $this->onRun(
			action: 'reconcile',
			args: $args,
			conflict: 'not-exported',
			run: fn (array $paymentRun): array => $this->reconciliation->reconcile(paymentRun: $paymentRun, contents: $contents)
		);
	}//end reconcile()

	/**
	 * Load the run behind the gate and run the service on it, mapping its
	 * refusal as the controller does: the conflict error is a conflict, any
	 * other error unprocessable.
	 *
	 * @param string               $action   The verb, for the log.
	 * @param array<string, mixed> $args     The validated arguments.
	 * @param string               $conflict The service error that is a conflict.
	 * @param callable             $run      (paymentRun) => the service result.
	 *
	 * @return array<string, mixed>
	 */
	private function onRun(string $action, array $args, string $conflict, callable $run): array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		$paymentRunId = (string)$args['paymentRunId'];
		try {
			$paymentRun = ObjectIdentifier::findOne(
				scoped: $this->objectService->setRegister('shillinq')->setSchema('PaymentRun'),
				id: $paymentRunId
			);
			$refusal = $this->gate->recordRefusal(administrationId: $administrationId, record: $paymentRun, notFound: self::NOT_FOUND);
			if ($refusal !== null) {
				return $refusal;
			}

			$result = $run($paymentRun);
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq MCP: payment run ' . $action . ' failed',
				['paymentRunId' => $args['paymentRunId'], 'exception' => $e->getMessage()]
			);
			return McpResult::error(code: 'failed', message: 'Internal error');
		}

		if (isset($result['error']) === false) {
			return $result;
		}

		$code = 'invalid_arguments';
		if ($result['error'] === $conflict) {
			$code = 'conflict';
		}

		return McpResult::error(code: $code, message: (string)($result['message'] ?? $result['error']));
	}//end onRun()
}//end class
