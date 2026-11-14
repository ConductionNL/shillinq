<?php

/**
 * Shillinq MCP purchase-order tools
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

use OCA\Shillinq\Service\PurchaseOrderApprovalService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * `shillinq.purchase_order.decide`: the path behind
 * PurchaseOrderApprovalController::decide(), with the same refusals. The
 * approver is the signed-in user, stamped by the service; nothing in the
 * arguments names a user.
 *
 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
 */
class McpPurchaseOrderTools {

	/**
	 * The masked answer for a foreign or missing purchase order.
	 */
	public const NOT_FOUND = 'Purchase order not found';

	/**
	 * Constructor.
	 *
	 * @param McpAdministrationGate        $gate      The authorisation ladder.
	 * @param PurchaseOrderApprovalService $approvals Records the decision.
	 * @param LoggerInterface              $logger    Logs an unexpected failure.
	 */
	public function __construct(
		private readonly McpAdministrationGate $gate,
		private readonly PurchaseOrderApprovalService $approvals,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record an approval decision on a purchase order.
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, purchaseOrderId, decision, comment.
	 *
	 * @return array<string, mixed> The updated purchase order, or a structured error.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public function decide(array $args): array {
		$refusal = $this->gate->refusal(administrationId: (string)$args['administrationId'], notFound: self::NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		$comment = ($args['comment'] ?? null);
		if (is_string($comment) === false) {
			$comment = null;
		}

		try {
			return [
				'purchaseOrder' => $this->approvals->recordApprovalDecision(
					administrationId: (string)$args['administrationId'],
					purchaseOrderId: (string)$args['purchaseOrderId'],
					decision: (string)$args['decision'],
					comment: $comment
				),
			];
		} catch (RuntimeException $e) {
			return $this->refusedBy(exception: $e);
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq MCP: failed to record approval decision',
				['administrationId' => $args['administrationId'], 'purchaseOrderId' => $args['purchaseOrderId'], 'exception' => $e->getMessage()]
			);
			return McpResult::error(code: 'failed', message: 'Could not record approval decision');
		}
	}//end decide()

	/**
	 * The service's refusal as the controller maps it: not found, conflict, or invalid.
	 *
	 * @param RuntimeException $exception The refusal.
	 *
	 * @return array<string, mixed>
	 */
	private function refusedBy(RuntimeException $exception): array {
		$message = $exception->getMessage();
		if (str_contains($message, 'not found') === true) {
			return McpResult::error(code: 'not_found', message: $message);
		}

		if (str_contains($message, 'not pending') === true || str_contains($message, 'fully signed') === true) {
			return McpResult::error(code: 'conflict', message: $message);
		}

		return McpResult::error(code: 'invalid_arguments', message: $message);
	}//end refusedBy()
}//end class
