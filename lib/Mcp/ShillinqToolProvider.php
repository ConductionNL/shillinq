<?php

/**
 * Shillinq MCP tool provider
 *
 * The hand-written action tools: one per user action that traverses a
 * shillinq service. The coarse read tools stay derived from the schemas.
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

use OCA\OpenRegister\Mcp\IMcpToolProvider;

/**
 * Dispatcher and catalogue only (the decidiq reference shape): it owns the
 * descriptors, validates the arguments, routes to the handler and audits
 * every invocation. Authorisation runs in the handler, through
 * McpAdministrationGate, before any service.
 *
 * Every write declares `scope` and `reach`, which hermiq's default-deny grant
 * matrix and approval gate read; every read declares `readOnlyHint`. The
 * descriptors are advisory: the gate and the service decide.
 *
 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
 */
class ShillinqToolProvider implements IMcpToolProvider {

	/**
	 * The app id every tool id starts with.
	 */
	public const APP_ID = 'shillinq';

	/**
	 * An id as the controllers accept one.
	 */
	private const ID_PATTERN = '^[A-Za-z0-9_.\-]{1,64}$';

	/**
	 * The catalogue.
	 *
	 * @var list<array<string, mixed>>
	 */
	private const TOOLS = [
		[
			'id' => 'shillinq.purchase_order.decide',
			'name' => 'Decide on a purchase order',
			'description' => 'Approve, reject or delegate a purchase order that waits for your approval, as the '
				. 'signed-in approver. Use it when the user asks to approve or reject a specific purchase order; '
				. 'find the order first with shillinq.PurchaseOrder.search. The decision binds the administration '
				. 'and is recorded in the approval chain; it cannot be taken back with this tool.',
			'scope' => 'update',
			'reach' => 'instance',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The administration the order belongs to.'],
					'purchaseOrderId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The purchase order.'],
					'decision' => ['type' => 'string', 'enum' => ['approved', 'rejected', 'delegated'], 'description' => 'The decision.'],
					'comment' => ['type' => ['string', 'null'], 'maxLength' => 2000, 'description' => 'An optional comment for the approval chain.'],
				],
				'required' => ['administrationId', 'purchaseOrderId', 'decision'],
			],
		],
		[
			'id' => 'shillinq.period_close.status',
			'name' => 'Read the close status of a period',
			'description' => 'Read an accounting period of an administration with its close status and the readiness flags the '
				. 'close assistant raises (open items, unposted entries). Use it before starting or finishing a close, '
				. 'or when the user asks whether a month or quarter can be closed. Changes nothing.',
			'scope' => 'read',
			'readOnlyHint' => true,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The administration the period belongs to.'],
					'periodId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The accounting period.'],
				],
				'required' => ['administrationId', 'periodId'],
			],
		],
		[
			'id' => 'shillinq.period_close.start',
			'name' => 'Start closing a period',
			'description' => 'Move an open accounting period into closing, as the signed-in user, so the close checklist can be '
				. 'worked through. Use it when the user asks to begin the month-end or quarter-end close; read the '
				. 'period first with shillinq.period_close.status. Bookings into the period are then restricted.',
			'scope' => 'update',
			'reach' => 'instance',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The administration the period belongs to.'],
					'periodId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The accounting period.'],
				],
				'required' => ['administrationId', 'periodId'],
			],
		],
		[
			'id' => 'shillinq.period_close.close',
			'name' => 'Close a period',
			'description' => 'Close an accounting period of an administration, as the signed-in user, so no further bookings land '
				. 'in it. Use it only when the user explicitly asks to close the period and the close status shows it '
				. 'is ready. Undoing it needs shillinq.period_close.reopen with a reason.',
			'scope' => 'update',
			'reach' => 'instance',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The administration the period belongs to.'],
					'periodId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The accounting period.'],
				],
				'required' => ['administrationId', 'periodId'],
			],
		],
		[
			'id' => 'shillinq.period_close.reopen',
			'name' => 'Reopen a closed period',
			'description' => 'Reopen a closed accounting period of an administration, as the signed-in user, recording why. Use it '
				. 'only when the user explicitly asks to reopen a closed period to correct it; the reason is kept with '
				. 'the period. The service refuses a period that is locked for audit.',
			'scope' => 'update',
			'reach' => 'instance',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The administration the period belongs to.'],
					'periodId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The accounting period.'],
					'closeReason' => ['type' => ['string', 'null'], 'maxLength' => 2000, 'description' => 'Why the period is reopened.'],
				],
				'required' => ['administrationId', 'periodId'],
			],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param McpArgumentValidator  $validator      Checks arguments before anything runs.
	 * @param McpAdministrationGate $gate           Names the principal for the audit record.
	 * @param McpInvocationAudit    $audit          Records every invocation.
	 * @param McpPurchaseOrderTools $purchaseOrders The purchase-order handlers.
	 * @param McpPeriodCloseTools   $periodClose    The period-close handlers.
	 */
	public function __construct(
		private readonly McpArgumentValidator $validator,
		private readonly McpAdministrationGate $gate,
		private readonly McpInvocationAudit $audit,
		private readonly McpPurchaseOrderTools $purchaseOrders,
		private readonly McpPeriodCloseTools $periodClose,
	) {
	}//end __construct()

	/**
	 * The app id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public function getAppId(): string {
		return self::APP_ID;
	}//end getAppId()

	/**
	 * The whole catalogue, whoever asks: authorisation is per invocation.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public function getTools(): array {
		return self::TOOLS;
	}//end getTools()

	/**
	 * Run a tool: validate, dispatch, audit. Never throws to the caller.
	 *
	 * @param string               $toolId    The tool id.
	 * @param array<string, mixed> $arguments The decoded arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		$result = $this->run(toolId: $toolId, arguments: $arguments);
		$this->audit->record(
			toolId: $toolId,
			userId: $this->gate->userId(),
			administrationId: $this->administrationOf(arguments: $arguments),
			result: $result
		);

		return $result;
	}//end invokeTool()

	/**
	 * Validate and dispatch.
	 *
	 * @param string               $toolId    The tool id.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function run(string $toolId, array $arguments): array {
		$descriptor = $this->descriptor(toolId: $toolId);
		if ($descriptor === null) {
			return McpResult::error(
				code: 'unknown_tool',
				message: "Unknown tool id '{$toolId}'. Available tools: " . implode(', ', array_column(self::TOOLS, 'id')) . '.'
			);
		}

		$problems = $this->validator->problems(schema: $descriptor['inputSchema'], arguments: $arguments);
		if ($problems !== []) {
			return McpResult::error(code: 'invalid_arguments', message: implode('; ', $problems) . '.');
		}

		return match ($toolId) {
			'shillinq.purchase_order.decide' => $this->purchaseOrders->decide(args: $arguments),
			'shillinq.period_close.status' => $this->periodClose->status(args: $arguments),
			'shillinq.period_close.start' => $this->periodClose->start(args: $arguments),
			'shillinq.period_close.close' => $this->periodClose->close(args: $arguments),
			'shillinq.period_close.reopen' => $this->periodClose->reopen(args: $arguments),
			default => McpResult::error(code: 'unknown_tool', message: "Tool '{$toolId}' has no handler."),
		};
	}//end run()

	/**
	 * The descriptor of a tool id, or null.
	 *
	 * @param string $toolId The tool id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function descriptor(string $toolId): ?array {
		foreach (self::TOOLS as $tool) {
			if ($tool['id'] === $toolId) {
				return $tool;
			}
		}

		return null;
	}//end descriptor()

	/**
	 * The administration a call names, for the audit record.
	 *
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return string
	 */
	private function administrationOf(array $arguments): string {
		$administrationId = ($arguments['administrationId'] ?? '');
		if (is_string($administrationId) === false) {
			return '';
		}

		return mb_substr($administrationId, 0, 64);
	}//end administrationOf()
}//end class
