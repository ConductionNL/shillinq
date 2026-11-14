<?php

/**
 * Shillinq MCP requisition tools
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

use OCA\Shillinq\Kernel\Contracts\RequisitionConversionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RequisitionServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * `shillinq.requisition.create|submit|decide|convert`: the service paths
 * behind `RequisitionController::create`, `submit`, `approve` / `reject` and
 * `convert`, with the same refusals. The requester, approver or rejector is
 * the signed-in user; nothing in the arguments names a user.
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */
class McpRequisitionTools {

	/**
	 * The masked answer for an administration the caller may not reach on create, as the controller words it.
	 */
	public const ADMINISTRATION_NOT_FOUND = 'Administration not found';

	/**
	 * The masked answer for a foreign or missing requisition, as the controller words it.
	 */
	public const NOT_FOUND = 'Requisition not found';

	/**
	 * Constructor.
	 *
	 * @param McpAdministrationGate                 $gate         The authorisation ladder.
	 * @param RequisitionServiceInterface           $requisitions The requisition lifecycle.
	 * @param RequisitionConversionServiceInterface $conversion   Turns an approved requisition into a purchase order.
	 * @param LoggerInterface                       $logger       Logs a refusal or an unexpected failure.
	 */
	public function __construct(
		private readonly McpAdministrationGate $gate,
		private readonly RequisitionServiceInterface $requisitions,
		private readonly RequisitionConversionServiceInterface $conversion,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a draft requisition with its lines (`RequisitionController::create`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, programme, financialYear,
	 *                                   neededByDate, justification, kind, preferredSupplierId?, lines.
	 *
	 * @return array<string, mixed> `{requisition}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function create(array $args): array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::ADMINISTRATION_NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		$payload = [
			'programme' => trim((string)$args['programme']),
			'financialYear' => (int)$args['financialYear'],
			'neededByDate' => trim((string)$args['neededByDate']),
			'justification' => trim((string)$args['justification']),
			'kind' => trim((string)$args['kind']),
			'preferredSupplierId' => trim((string)($args['preferredSupplierId'] ?? '')),
			'lines' => (array)$args['lines'],
		];
		try {
			return ['requisition' => $this->requisitions->createRequisition(administrationId: $administrationId, payload: $payload)];
		} catch (RuntimeException $e) {
			$this->logger->error('Shillinq MCP: requisition create rejected', ['administrationId' => $administrationId, 'exception' => $e->getMessage()]);
			return McpResult::error(code: 'invalid_arguments', message: 'Requisition could not be created; check the required fields');
		} catch (Throwable $e) {
			return $this->failed(action: 'create', args: $args, exception: $e);
		}
	}//end create()

	/**
	 * Submit a draft requisition for approval (`RequisitionController::submit`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, requisitionId.
	 *
	 * @return array<string, mixed> `{requisition}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function submit(array $args): array {
		return $this->act(
			action: 'submit',
			args: $args,
			run: fn (string $administrationId, string $requisitionId): array => $this->requisitions->submitRequisition(
				administrationId: $administrationId,
				requisitionId: $requisitionId
			)
		);
	}//end submit()

	/**
	 * Approve or reject a submitted requisition, as the signed-in user
	 * (`RequisitionController::approve` / `reject`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, requisitionId, decision, reason?.
	 *
	 * @return array<string, mixed> `{requisition}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function decide(array $args): array {
		if ($args['decision'] === 'approved') {
			return $this->act(
				action: 'approve',
				args: $args,
				run: fn (string $administrationId, string $requisitionId): array => $this->requisitions->approveRequisition(
					administrationId: $administrationId,
					requisitionId: $requisitionId,
					approverId: $this->gate->userId()
				)
			);
		}

		return $this->act(
			action: 'reject',
			args: $args,
			run: fn (string $administrationId, string $requisitionId): array => $this->requisitions->rejectRequisition(
				administrationId: $administrationId,
				requisitionId: $requisitionId,
				rejectorId: $this->gate->userId(),
				reason: trim((string)($args['reason'] ?? ''))
			)
		);
	}//end decide()

	/**
	 * Convert an approved requisition into a purchase order (`RequisitionController::convert`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, requisitionId.
	 *
	 * @return array<string, mixed> `{conversion}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function convert(array $args): array {
		$result = $this->act(
			action: 'convert',
			args: $args,
			run: fn (string $administrationId, string $requisitionId): array => $this->conversion->convertToPurchaseOrder(
				administrationId: $administrationId,
				requisitionId: $requisitionId
			)
		);
		if (McpResult::isError($result) === true) {
			return $result;
		}

		return ['conversion' => $result['requisition']];
	}//end convert()

	/**
	 * Run one action on a requisition of an accessible administration.
	 *
	 * @param string               $action The controller verb: submit, approve, reject or convert.
	 * @param array<string, mixed> $args   The validated arguments.
	 * @param callable             $run    (administrationId, requisitionId) => the result.
	 *
	 * @return array<string, mixed> `{requisition}` or a structured refusal.
	 */
	private function act(string $action, array $args, callable $run): array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return ['requisition' => $run($administrationId, (string)$args['requisitionId'])];
		} catch (RuntimeException $e) {
			$this->logger->error(
				'Shillinq MCP: requisition ' . $action . ' rejected',
				['requisitionId' => $args['requisitionId'], 'exception' => $e->getMessage()]
			);
			if (str_contains($e->getMessage(), 'not found') === true) {
				return McpResult::error(code: 'not_found', message: self::NOT_FOUND);
			}

			return McpResult::error(code: 'conflict', message: 'Requisition is not in a state that allows this action');
		} catch (Throwable $e) {
			return $this->failed(action: $action, args: $args, exception: $e);
		}
	}//end act()

	/**
	 * Log an unexpected failure and answer a generic one, as the controller's 500 does.
	 *
	 * @param string               $action    The controller verb.
	 * @param array<string, mixed> $args      The arguments.
	 * @param Throwable            $exception The failure.
	 *
	 * @return array<string, mixed> The structured failure.
	 */
	private function failed(string $action, array $args, Throwable $exception): array {
		$this->logger->error(
			'Shillinq MCP: failed to ' . $action . ' requisition',
			['administrationId' => $args['administrationId'], 'requisitionId' => ($args['requisitionId'] ?? null), 'exception' => $exception->getMessage()]
		);

		return McpResult::error(code: 'failed', message: 'Could not ' . $action . ' requisition');
	}//end failed()
}//end class
