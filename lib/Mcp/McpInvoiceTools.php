<?php

/**
 * Shillinq MCP invoicing tools
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

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BillableLinesServiceInterface;
use OCA\Shillinq\Kernel\Contracts\EInvoiceServiceInterface;
use OCA\Shillinq\Kernel\Contracts\InvoiceGenerationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\InvoicePdfGeneratorInterface;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\RecurringInvoiceGenerator;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * `shillinq.invoice.draft|issue|render_pdf|send_einvoice` and
 * `shillinq.recurring_invoice.generate`: the service paths behind
 * `InvoiceApiController::generate`, `post` and `pdf`,
 * `ARInvoiceEInvoiceController::send` and `RecurringInvoiceGenerator`, with
 * the same refusals.
 *
 * Every call names its administration; an invoice or profile is acted on only
 * when it belongs to it.
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */
class McpInvoiceTools {

	/**
	 * The masked answer for an administration the caller may not reach.
	 */
	public const ADMINISTRATION_NOT_FOUND = 'Administration not found';

	/**
	 * The masked answer for a foreign or missing invoice or profile.
	 */
	public const NOT_FOUND = 'Not found';

	/**
	 * The fields of a draft request, as `InvoiceGenerationRequest::fromArray()` reads them.
	 */
	private const DRAFT_FIELDS = [
		'billingModel', 'customerId', 'fromDate', 'toDate', 'timeEntryIds', 'expenseIds', 'rateCardId', 'retainerScheduleId',
		'fixedFeeCents', 'milestoneId', 'projectId', 'notes', 'meterReadingIds', 'usageRatePlanId',
	];

	/**
	 * Constructor.
	 *
	 * @param McpAdministrationGate             $gate          The authorisation ladder.
	 * @param InvoiceGenerationServiceInterface $generation    Drafts and posts billable invoices.
	 * @param BillableLinesServiceInterface     $billableLines Says which chosen lines are not the customer's.
	 * @param InvoicePdfGeneratorInterface      $pdf           Renders an invoice.
	 * @param EInvoiceServiceInterface          $eInvoices     Sends a sales invoice as an e-invoice.
	 * @param RecurringInvoiceGenerator         $recurring     Generates a profile's due invoice.
	 * @param ObjectServiceInterface            $objectService Loads invoices, their lines and profiles.
	 * @param LoggerInterface                   $logger        Logs a refusal or an unexpected failure.
	 */
	public function __construct(
		private readonly McpAdministrationGate $gate,
		private readonly InvoiceGenerationServiceInterface $generation,
		private readonly BillableLinesServiceInterface $billableLines,
		private readonly InvoicePdfGeneratorInterface $pdf,
		private readonly EInvoiceServiceInterface $eInvoices,
		private readonly RecurringInvoiceGenerator $recurring,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Draft a billable invoice from time, expenses or a billing model (`InvoiceApiController::generate`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId and the draft fields.
	 *
	 * @return array<string, mixed> `{invoice}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function draft(array $args): array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::ADMINISTRATION_NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$request = InvoiceGenerationRequest::fromArray($administrationId, array_intersect_key($args, array_flip(self::DRAFT_FIELDS)));
			$foreign = $this->billableLines->foreignLineIds(
				administrationId: $request->administrationId,
				customerId: $request->customerId,
				timeEntryIds: $request->timeEntryIds,
				expenseIds: $request->expenseIds
			);
			if ($foreign !== []) {
				return McpResult::error(code: 'invalid_arguments', message: 'Some chosen lines are not billable to this customer in this administration.');
			}

			return ['invoice' => $this->generation->draftInvoice($request)];
		} catch (InvalidArgumentException $e) {
			return McpResult::error(code: 'invalid_arguments', message: 'The invoice generation request is invalid: ' . $e->getMessage());
		} catch (RuntimeException $e) {
			$this->logger->error('Shillinq MCP: invoice draft refused', ['exception' => $e->getMessage()]);
			if (str_contains($e->getMessage(), 'Conflict') === true) {
				return McpResult::error(code: 'conflict', message: 'One or more time entries, expenses or meter readings are already invoiced.');
			}

			return McpResult::error(code: 'invalid_arguments', message: 'Unable to generate the invoice.');
		} catch (Throwable $e) {
			return $this->failed(action: 'draft', exception: $e);
		}
	}//end draft()

	/**
	 * Post a draft invoice, giving it its sequential number (`InvoiceApiController::post`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, invoiceId.
	 *
	 * @return array<string, mixed> `{invoice}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function issue(array $args): array {
		try {
			$invoice = null;
			$refusal = $this->loadRefusal(args: $args, schema: 'BillableInvoice', id: (string)$args['invoiceId'], record: $invoice);
			if ($refusal !== null) {
				return $refusal;
			}

			return ['invoice' => $this->generation->postInvoice($invoice)];
		} catch (RuntimeException $e) {
			$this->logger->error('Shillinq MCP: invoice post refused', ['exception' => $e->getMessage()]);
			if (str_contains($e->getMessage(), 'already posted') === true) {
				return McpResult::error(code: 'conflict', message: 'The invoice is already posted.');
			}

			return McpResult::error(code: 'invalid_arguments', message: 'Unable to post the invoice.');
		} catch (Throwable $e) {
			return $this->failed(action: 'issue', exception: $e);
		}
	}//end issue()

	/**
	 * Render an invoice as the printable document (`InvoiceApiController::pdf`). Changes nothing.
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, invoiceId.
	 *
	 * @return array<string, mixed> `{filename, html}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function renderPdf(array $args): array {
		$invoiceId = (string)$args['invoiceId'];
		try {
			$invoice = null;
			$refusal = $this->loadRefusal(args: $args, schema: 'BillableInvoice', id: $invoiceId, record: $invoice);
			if ($refusal !== null) {
				return $refusal;
			}

			$lines = $this->objectService->setRegister('shillinq')->setSchema('BillableInvoiceLine')->findAll(['filters' => ['invoiceId' => $invoiceId]]);
			$document = $this->pdf->generatePdf(invoice: $invoice, lines: $lines);
		} catch (Throwable $e) {
			return $this->failed(action: 'render', exception: $e);
		}

		return ['filename' => $document['filename'] ?? $invoiceId, 'html' => $document['html'] ?? ''];
	}//end renderPdf()

	/**
	 * Send a sales invoice as an e-invoice to the customer (`ARInvoiceEInvoiceController::send`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, invoiceNumber.
	 *
	 * @return array<string, mixed> The dispatch result, or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function sendEInvoice(array $args): array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::ADMINISTRATION_NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->eInvoices->sendEInvoice(administrationId: $administrationId, invoiceNumber: (string)$args['invoiceNumber']);
		} catch (RuntimeException $e) {
			$code = 'invalid_arguments';
			if (str_contains($e->getMessage(), 'not found') === true) {
				$code = 'not_found';
			}

			return McpResult::error(code: $code, message: $e->getMessage());
		} catch (Throwable $e) {
			$this->logger->error('Shillinq MCP: send-einvoice failed', ['administrationId' => $administrationId, 'exception' => $e->getMessage()]);
			return McpResult::error(code: 'failed', message: 'Could not send e-invoice');
		}
	}//end sendEInvoice()

	/**
	 * Generate the due invoice of an active recurring-invoice profile (`RecurringInvoiceGenerator::generateForProfile`).
	 *
	 * Idempotent per billing period: a period already invoiced answers the
	 * existing invoice with `created: false`.
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, profileId.
	 *
	 * @return array<string, mixed> `{invoice, profile, created, billingPeriod}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function generateRecurring(array $args): array {
		try {
			$profile = null;
			$refusal = $this->loadRefusal(args: $args, schema: 'RecurringInvoiceProfile', id: (string)$args['profileId'], record: $profile);
			if ($refusal !== null) {
				return $refusal;
			}

			if ((string)($profile['status'] ?? '') !== 'active') {
				return McpResult::error(code: 'conflict', message: 'Only an active recurring-invoice profile generates invoices');
			}

			return $this->recurring->generateForProfile(profile: $profile);
		} catch (RuntimeException $e) {
			$this->logger->error('Shillinq MCP: recurring invoice refused', ['exception' => $e->getMessage()]);
			return McpResult::error(code: 'invalid_arguments', message: 'The recurring-invoice profile cannot generate an invoice');
		} catch (Throwable $e) {
			return $this->failed(action: 'generate', exception: $e);
		}
	}//end generateRecurring()

	/**
	 * Refuse before anything loads, then load the record and refuse it unless it belongs to the named administration.
	 *
	 * @param array<string, mixed>      $args   The validated arguments.
	 * @param string                    $schema The record's schema.
	 * @param string                    $id     The record's id.
	 * @param array<string, mixed>|null $record Set to the loaded record.
	 *
	 * @return array<string, mixed>|null The refusal, or null when the call may run.
	 */
	private function loadRefusal(array $args, string $schema, string $id, ?array &$record): ?array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		$record = ObjectIdentifier::findOne(scoped: $this->objectService->setRegister('shillinq')->setSchema($schema), id: $id);

		return $this->gate->recordRefusal(administrationId: $administrationId, record: $record, notFound: self::NOT_FOUND);
	}//end loadRefusal()

	/**
	 * Log an unexpected failure and answer a generic one, as the controllers' 500 does.
	 *
	 * @param string    $action    The verb.
	 * @param Throwable $exception The failure.
	 *
	 * @return array<string, mixed>
	 */
	private function failed(string $action, Throwable $exception): array {
		$this->logger->error('Shillinq MCP: invoice ' . $action . ' failed', ['exception' => $exception->getMessage()]);

		return McpResult::error(code: 'failed', message: 'Internal error');
	}//end failed()
}//end class
