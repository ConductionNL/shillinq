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
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the order belongs to.',
					],
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
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the period belongs to.',
					],
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
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the period belongs to.',
					],
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
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the period belongs to.',
					],
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
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the period belongs to.',
					],
					'periodId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The accounting period.'],
					'closeReason' => ['type' => ['string', 'null'], 'maxLength' => 2000, 'description' => 'Why the period is reopened.'],
				],
				'required' => ['administrationId', 'periodId'],
			],
		],
		[
			'id' => 'shillinq.vat_return.create',
			'name' => 'Create a VAT return',
			'description' => 'Create a draft VAT return for an administration for a past or current quarter, month or year, '
				. 'with its lines derived from the bookings. Use it when the user asks to prepare a VAT return; it files '
				. 'nothing. A future period is refused. Submit the draft later with shillinq.vat_return.submit.',
			'scope' => 'create',
			'reach' => 'user',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the return is for.',
					],
					'period' => ['type' => 'string', 'enum' => ['quarter', 'month', 'year'], 'description' => 'The kind of period.'],
					'periodYear' => ['type' => 'integer', 'minimum' => 2020, 'maximum' => 2099, 'description' => 'The fiscal year.'],
					'periodNumber' => [
						'type' => 'integer',
						'minimum' => 1,
						'maximum' => 12,
						'description' => 'The quarter (1-4), month (1-12) or 1 for a year.',
					],
					'regime' => ['type' => 'string', 'enum' => ['standard', 'kor', 'reverse-charge'], 'description' => 'The VAT regime.'],
				],
				'required' => ['administrationId', 'period', 'periodYear', 'periodNumber', 'regime'],
			],
		],
		[
			'id' => 'shillinq.vat_return.rebase',
			'name' => 'Rebase a VAT return to draft',
			'description' => 'Move a submitted VAT return of an administration back to draft and derive its lines again from the '
				. 'current bookings, as the signed-in user. Use it when the user asks to correct a submitted return '
				. 'before it is filed. The service refuses a return that is not submitted.',
			'scope' => 'update',
			'reach' => 'user',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the return belongs to.',
					],
					'returnId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The VAT return; find it with shillinq.VatReturn.search.',
					],
				],
				'required' => ['administrationId', 'returnId'],
			],
		],
		[
			'id' => 'shillinq.vat_return.submit',
			'name' => 'Submit a VAT return',
			'description' => 'Submit a draft VAT return of an administration for filing with the tax authority, as the signed-in '
				. 'user. Use it only when the user explicitly asks to submit or file that return; the filing leaves '
				. 'the instance. The service refuses a return that is not a draft or has negative totals.',
			'scope' => 'update',
			'reach' => 'external',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the return belongs to.',
					],
					'returnId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The VAT return; find it with shillinq.VatReturn.search.',
					],
				],
				'required' => ['administrationId', 'returnId'],
			],
		],
		[
			'id' => 'shillinq.vat_return.destroy',
			'name' => 'Delete a draft VAT return',
			'description' => 'Delete a draft VAT return of an administration. Use it only when the user explicitly asks to discard '
				. 'that draft. A submitted return is never deleted: it is refused, and corrected with '
				. 'shillinq.vat_return.rebase instead.',
			'scope' => 'delete',
			'reach' => 'user',
			'readOnlyHint' => false,
			'destructiveHint' => true,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the return belongs to.',
					],
					'returnId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The VAT return; find it with shillinq.VatReturn.search.',
					],
				],
				'required' => ['administrationId', 'returnId'],
			],
		],
		[
			'id' => 'shillinq.dunning.dossier',
			'name' => 'Compose the collection dossier of an invoice',
			'description' => 'Compose the collection dossier of an overdue sales invoice: the invoice, the reminders sent, the '
				. 'collection costs and the customer details a collection agency needs. Use it when the user asks what '
				. 'would be handed over for collection, or before a transfer. Changes nothing and sends nothing.',
			'scope' => 'read',
			'readOnlyHint' => true,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the invoice belongs to.',
					],
					'invoiceId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The sales invoice; find it with shillinq.ARInvoice.search.',
					],
					'customerId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The customer of the invoice.'],
				],
				'required' => ['administrationId', 'invoiceId', 'customerId'],
			],
		],
		[
			'id' => 'shillinq.dunning.execute_run',
			'name' => 'Send the next dunning reminder',
			'description' => 'Run the next stage of the dunning ladder for an overdue sales invoice: compose the reminder and send '
				. 'it to the customer through its channel. Use it only when the user explicitly asks to send a reminder '
				. 'for that invoice; the letter leaves the instance. A paused invoice is refused.',
			'scope' => 'update',
			'reach' => 'external',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the invoice belongs to.',
					],
					'invoiceId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The sales invoice; find it with shillinq.ARInvoice.search.',
					],
					'ladderId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The dunning ladder to follow; empty for the configured one.',
					],
					'stageNr' => [
						'type' => 'integer',
						'minimum' => 1,
						'maximum' => 10,
						'description' => 'The stage of the ladder to run; defaults to the first.',
					],
				],
				'required' => ['administrationId', 'invoiceId'],
			],
		],
		[
			'id' => 'shillinq.dunning.pause',
			'name' => 'Pause dunning on an invoice',
			'description' => 'Pause the dunning of a sales invoice, as the signed-in user, because it is disputed, under a payment '
				. 'plan or for another reason. Use it when the user says a customer disputes an invoice or agreed a '
				. 'payment plan; no reminder is sent while the pause stands. Lift it with shillinq.dunning.resume.',
			'scope' => 'update',
			'reach' => 'user',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the invoice belongs to.',
					],
					'invoiceId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The sales invoice; find it with shillinq.ARInvoice.search.',
					],
					'reason' => ['type' => 'string', 'enum' => ['DISPUTED', 'PAYMENT_PLAN', 'OTHER'], 'description' => 'Why dunning is paused.'],
					'details' => ['type' => ['string', 'null'], 'maxLength' => 2000, 'description' => 'What the customer said or agreed.'],
				],
				'required' => ['administrationId', 'invoiceId', 'reason'],
			],
		],
		[
			'id' => 'shillinq.dunning.resume',
			'name' => 'Lift a dunning pause',
			'description' => 'Lift a dunning pause of an administration: resolve it (the dispute is settled, optionally with a '
				. 'partial settlement) or let it expire, so dunning continues. Use it when the user says the dispute or '
				. 'payment plan is over. Find the pause with the invoice it was set on.',
			'scope' => 'update',
			'reach' => 'user',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the pause belongs to.',
					],
					'pauseId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The dunning pause.'],
					'resolution' => [
						'type' => 'string',
						'enum' => ['resolve', 'expire'],
						'description' => 'Resolve the pause, or let it expire; defaults to resolve.',
					],
					'partialSettlement' => [
						'type' => ['number', 'null'],
						'minimum' => 0,
						'description' => 'The amount settled, when the dispute ended in a partial settlement.',
					],
				],
				'required' => ['administrationId', 'pauseId'],
			],
		],
		[
			'id' => 'shillinq.dunning.write_off',
			'name' => 'Write off an uncollectable invoice',
			'description' => 'Write off the principal of an uncollectable sales invoice and record the art. 29 OB VAT reclaim, '
				. 'as the signed-in user. Use it only when the user explicitly asks to write that invoice off; it books '
				. 'into the ledger and the VAT return and binds the administration.',
			'scope' => 'update',
			'reach' => 'instance',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the invoice belongs to.',
					],
					'invoiceId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The sales invoice; find it with shillinq.ARInvoice.search.',
					],
					'principalDepreciated' => ['type' => 'number', 'minimum' => 0, 'description' => 'The principal written off, more than zero.'],
					'art29OBDeclaration' => [
						'type' => 'string',
						'maxLength' => 2000,
						'description' => 'The art. 29 OB statement on why the claim is uncollectable.',
					],
					'vatAmount' => [
						'type' => ['number', 'null'],
						'minimum' => 0,
						'description' => 'The VAT reclaimed; derived from the invoice when empty.',
					],
					'vatTaxReturnPeriod' => [
						'type' => ['string', 'null'],
						'pattern' => '^[0-9]{4}-(Q[1-4]|[0-9]{2})$',
						'description' => 'The VAT period the reclaim lands in, e.g. 2026-Q3; the next one when empty.',
					],
					'evidenceRef' => [
						'type' => ['string', 'null'],
						'maxLength' => 500,
						'description' => 'A reference to the evidence, such as a bankruptcy notice.',
					],
				],
				'required' => ['administrationId', 'invoiceId', 'principalDepreciated', 'art29OBDeclaration'],
			],
		],
		[
			'id' => 'shillinq.requisition.create',
			'name' => 'Create a purchase requisition',
			'description' => 'Create a draft purchase requisition in an administration, as the signed-in requester, with what is '
				. 'needed, why, by when and on which budget programme, line by line. Use it when the user asks to request '
				. 'a purchase; nothing is ordered. Submit it for approval with shillinq.requisition.submit.',
			'scope' => 'create',
			'reach' => 'user',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the requisition belongs to.',
					],
					'programme' => ['type' => 'string', 'maxLength' => 200, 'description' => 'The budget programme it is charged to.'],
					'financialYear' => ['type' => 'integer', 'minimum' => 2000, 'maximum' => 2099, 'description' => 'The financial year.'],
					'neededByDate' => [
						'type' => 'string',
						'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$',
						'description' => 'When it is needed, as YYYY-MM-DD.',
					],
					'justification' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Why it is needed.'],
					'kind' => ['type' => 'string', 'maxLength' => 100, 'description' => 'The kind of purchase, such as goods or services.'],
					'preferredSupplierId' => [
						'type' => ['string', 'null'],
						'pattern' => self::ID_PATTERN,
						'description' => 'A preferred supplier, if any.',
					],
					'lines' => [
						'type' => 'array',
						'minItems' => 1,
						'maxItems' => 100,
						'description' => 'What is requested, one line per item.',
						'items' => [
							'type' => 'object',
							'properties' => [
								'description' => ['type' => 'string', 'maxLength' => 500, 'description' => 'What the line is for.'],
								'glAccountSuggestion' => [
									'type' => 'string',
									'pattern' => self::ID_PATTERN,
									'description' => 'The ledger account it is likely booked on.',
								],
								'quantity' => ['type' => 'number', 'minimum' => 0, 'description' => 'How many, more than zero.'],
								'unitPrice' => ['type' => 'number', 'minimum' => 0, 'description' => 'The price per unit, excluding VAT.'],
							],
							'required' => ['description', 'glAccountSuggestion', 'quantity', 'unitPrice'],
						],
					],
				],
				'required' => ['administrationId', 'programme', 'financialYear', 'neededByDate', 'justification', 'kind', 'lines'],
			],
		],
		[
			'id' => 'shillinq.requisition.submit',
			'name' => 'Submit a requisition for approval',
			'description' => 'Submit a draft purchase requisition of an administration for approval. Use it when the user asks to '
				. 'send their requisition on; the approvers then see it. A requisition without a positive total or not '
				. 'in draft is refused.',
			'scope' => 'update',
			'reach' => 'user',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the requisition belongs to.',
					],
					'requisitionId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The purchase requisition.'],
				],
				'required' => ['administrationId', 'requisitionId'],
			],
		],
		[
			'id' => 'shillinq.requisition.decide',
			'name' => 'Approve or reject a requisition',
			'description' => 'Approve or reject a submitted purchase requisition of an administration, as the signed-in approver, '
				. 'with a reason when rejecting. Use it only when the user explicitly asks to approve or reject that '
				. 'requisition; the decision binds the administration.',
			'scope' => 'update',
			'reach' => 'instance',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the requisition belongs to.',
					],
					'requisitionId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The purchase requisition.'],
					'decision' => ['type' => 'string', 'enum' => ['approved', 'rejected'], 'description' => 'The decision.'],
					'reason' => ['type' => ['string', 'null'], 'maxLength' => 2000, 'description' => 'Why it is rejected.'],
				],
				'required' => ['administrationId', 'requisitionId', 'decision'],
			],
		],
		[
			'id' => 'shillinq.requisition.convert',
			'name' => 'Turn a requisition into a purchase order',
			'description' => 'Convert an approved purchase requisition of an administration into a purchase order for its '
				. 'supplier. Use it when the user asks to order what an approved requisition requests; the order then '
				. 'goes through its own approval with shillinq.purchase_order.decide.',
			'scope' => 'create',
			'reach' => 'instance',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the requisition belongs to.',
					],
					'requisitionId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The purchase requisition.'],
				],
				'required' => ['administrationId', 'requisitionId'],
			],
		],
		[
			'id' => 'shillinq.payment_run.export_sepa',
			'name' => 'Export a payment run as a SEPA file',
			'description' => 'Export an approved payment run of an administration as a SEPA credit-transfer file for the bank, '
				. 'and mark the run exported. Use it only when the user explicitly asks to export or pay that run; the '
				. 'file is meant to leave the instance for the bank. A run that is not approved is refused.',
			'scope' => 'update',
			'reach' => 'external',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the run belongs to.',
					],
					'paymentRunId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The payment run.'],
				],
				'required' => ['administrationId', 'paymentRunId'],
			],
		],
		[
			'id' => 'shillinq.payment_run.reconcile',
			'name' => 'Reconcile a payment run with a bank statement',
			'description' => 'Match an exported payment run of an administration against the bank statement that shows its '
				. 'payments, and mark the paid lines. Use it when the user provides the statement after the bank executed '
				. 'the run. A run that was not exported is refused.',
			'scope' => 'update',
			'reach' => 'user',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the run belongs to.',
					],
					'paymentRunId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The payment run.'],
					'contents' => [
						'type' => 'string',
						'maxLength' => 5000000,
						'description' => 'The bank statement, as its file text (camt.053 or MT940).',
					],
				],
				'required' => ['administrationId', 'paymentRunId', 'contents'],
			],
		],
		[
			'id' => 'shillinq.bank_statement.import',
			'name' => 'Import a bank statement',
			'description' => 'Import a bank statement file into an administration: its transactions are added as unmatched '
				. 'statement lines, skipping lines already on file for the account. Use it when the user provides a '
				. 'camt.053, MT940 or CSV export from their bank. Matching happens afterwards.',
			'scope' => 'create',
			'reach' => 'user',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the statement is for.',
					],
					'format' => ['type' => 'string', 'enum' => ['camt053', 'mt940', 'csv'], 'description' => 'The file format.'],
					'contents' => ['type' => 'string', 'maxLength' => 5000000, 'description' => 'The statement, as its file text.'],
					'bankAccountIban' => [
						'type' => ['string', 'null'],
						'pattern' => '^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$',
						'description' => 'The IBAN of the account, when the file does not name it.',
					],
					'glAccountId' => [
						'type' => ['string', 'null'],
						'pattern' => self::ID_PATTERN,
						'description' => 'The ledger account of the bank account.',
					],
				],
				'required' => ['administrationId', 'format', 'contents'],
			],
		],
		[
			'id' => 'shillinq.bank_reconciliation.resolve',
			'name' => 'Resolve bank reconciliation matches',
			'description' => 'Resolve one or more matches of a bank reconciliation of an administration, as the signed-in user: '
				. 'mark them matched, a timing difference, pending or an adjustment, with the reason kept in the audit '
				. 'trail. Use it when the user explains unmatched lines. A closed reconciliation is refused per match.',
			'scope' => 'update',
			'reach' => 'user',
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'administrationId' => [
						'type' => 'string',
						'pattern' => self::ID_PATTERN,
						'description' => 'The administration the reconciliation belongs to.',
					],
					'reconId' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'description' => 'The bank reconciliation.'],
					'matchIds' => [
						'type' => 'array',
						'minItems' => 1,
						'maxItems' => 200,
						'items' => ['type' => 'string', 'pattern' => self::ID_PATTERN],
						'description' => 'The matches to resolve.',
					],
					'resolutionStatus' => [
						'type' => 'string',
						'enum' => ['matched', 'timing', 'pending', 'adjustment'],
						'description' => 'How they are resolved.',
					],
					'resolutionReason' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Why, for the audit trail.'],
				],
				'required' => ['administrationId', 'reconId', 'matchIds', 'resolutionStatus', 'resolutionReason'],
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
	 * @param McpVatReturnTools     $vatReturns     The VAT-return handlers.
	 * @param McpDunningTools       $dunning        The dunning handlers.
	 * @param McpRequisitionTools   $requisitions   The requisition handlers.
	 * @param McpPaymentRunTools    $paymentRuns    The payment-run handlers.
	 * @param McpBankTools          $bank           The bank-statement and reconciliation handlers.
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
