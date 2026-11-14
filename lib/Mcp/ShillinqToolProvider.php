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
	 * The catalogue file: every tool's id, name, description, scope, hints and
	 * input schema. Data, not code, so a new tool is a new entry there.
	 */
	private const CATALOGUE = __DIR__ . '/tool-catalogue.json';

	/**
	 * The catalogue once read, per request.
	 *
	 * @var list<array<string, mixed>>|null
	 */
	private static ?array $tools = null;

	/**
	 * Constructor.
	 *
	 * @param McpArgumentValidator  $validator      Checks arguments before anything runs.
	 * @param McpAdministrationGate $gate           Names the principal for the audit record.
	 * @param McpInvocationAudit    $audit          Records every invocation.
	 * @param McpPurchaseOrderTools $purchaseOrders The purchase-order handlers.
	 * @param McpPeriodCloseTools   $periodClose    The period-close handlers.
	 * @param McpVatReturnTools     $vatReturns     The VAT-return handlers.
	 * @param McpDunningTools       $dunning        The dunning handlers.
	 * @param McpRequisitionTools   $requisitions   The requisition handlers.
	 * @param McpPaymentRunTools    $paymentRuns    The payment-run handlers.
	 * @param McpBankTools          $bank           The bank-statement and reconciliation handlers.
	 * @param McpInvoiceTools       $invoices       The invoicing handlers.
	 */
	public function __construct(
		private readonly McpArgumentValidator $validator,
		private readonly McpAdministrationGate $gate,
		private readonly McpInvocationAudit $audit,
		private readonly McpPurchaseOrderTools $purchaseOrders,
		private readonly McpPeriodCloseTools $periodClose,
		private readonly McpVatReturnTools $vatReturns,
		private readonly McpDunningTools $dunning,
		private readonly McpRequisitionTools $requisitions,
		private readonly McpPaymentRunTools $paymentRuns,
		private readonly McpBankTools $bank,
		private readonly McpInvoiceTools $invoices,
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
		return self::tools();
	}//end getTools()

	/**
	 * The catalogue, read from its file once.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	private static function tools(): array {
		if (self::$tools === null) {
			$decoded = json_decode((string)file_get_contents(self::CATALOGUE), true, 64, JSON_THROW_ON_ERROR);
			self::$tools = array_values((array)$decoded);
		}

		return self::$tools;
	}//end tools()

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
			result: $result,
			arguments: $arguments
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
				message: "Unknown tool id '{$toolId}'. Available tools: " . implode(', ', array_column(self::tools(), 'id')) . '.'
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
			'shillinq.vat_return.create' => $this->vatReturns->create(args: $arguments),
			'shillinq.vat_return.rebase' => $this->vatReturns->rebase(args: $arguments),
			'shillinq.vat_return.submit' => $this->vatReturns->submit(args: $arguments),
			'shillinq.vat_return.destroy' => $this->vatReturns->destroy(args: $arguments),
			'shillinq.dunning.dossier' => $this->dunning->dossier(args: $arguments),
			'shillinq.dunning.execute_run' => $this->dunning->executeRun(args: $arguments),
			'shillinq.dunning.pause' => $this->dunning->pause(args: $arguments),
			'shillinq.dunning.resume' => $this->dunning->resume(args: $arguments),
			'shillinq.dunning.write_off' => $this->dunning->writeOff(args: $arguments),
			'shillinq.requisition.create' => $this->requisitions->create(args: $arguments),
			'shillinq.requisition.submit' => $this->requisitions->submit(args: $arguments),
			'shillinq.requisition.decide' => $this->requisitions->decide(args: $arguments),
			'shillinq.requisition.convert' => $this->requisitions->convert(args: $arguments),
			'shillinq.payment_run.export_sepa' => $this->paymentRuns->exportSepa(args: $arguments),
			'shillinq.payment_run.reconcile' => $this->paymentRuns->reconcile(args: $arguments),
			'shillinq.bank_statement.import' => $this->bank->importStatement(args: $arguments),
			'shillinq.bank_reconciliation.resolve' => $this->bank->resolve(args: $arguments),
			'shillinq.invoice.draft' => $this->invoices->draft(args: $arguments),
			'shillinq.invoice.issue' => $this->invoices->issue(args: $arguments),
			'shillinq.invoice.render_pdf' => $this->invoices->renderPdf(args: $arguments),
			'shillinq.invoice.send_einvoice' => $this->invoices->sendEInvoice(args: $arguments),
			'shillinq.recurring_invoice.generate' => $this->invoices->generateRecurring(args: $arguments),
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
		foreach (self::tools() as $tool) {
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
