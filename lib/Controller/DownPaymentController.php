<?php

/**
 * Down payments on an order and their deduction on the final invoice.
 *
 * POST /api/ar-invoices/down-payments raises a draft down-payment invoice;
 * GET /api/ar-invoices/{id}/down-payments answers the order's position and
 * the orders with down payments left to deduct; POST
 * /api/ar-invoices/{id}/down-payment-deductions makes a draft the final
 * invoice of an order. The administration is checked before anything else;
 * another administration's invoice answers 404, the same as a missing one.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-down-payments/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Sales\DownPaymentRefusedException;
use OCA\Shillinq\Service\Sales\DownPaymentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Down-payment endpoints.
 *
 * @spec openspec/changes/sales-down-payments/tasks.md#task-2.2
 */
class DownPaymentController extends Controller {
	/**
	 * The request fields a down payment is raised from.
	 *
	 * @var list<string>
	 */
	private const RAISE_FIELDS = [
		'administrationId',
		'customerId',
		'orderReference',
		'orderLabel',
		'orderVatBreakdown',
		'percentage',
		'amount',
		'invoiceNumber',
		'invoiceDate',
		'dueDate',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request The request.
	 * @param DownPaymentService           $service The down-payment rules.
	 * @param AdministrationContextService $context Administration access check.
	 * @param IL10N                        $l10n    Translations for refusals.
	 * @param LoggerInterface              $logger  Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly DownPaymentService $service,
		private readonly AdministrationContextService $context,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Raise a draft down-payment invoice on an order (REQ-SDP-001).
	 *
	 * @return JSONResponse 201 with the draft, 403 outside the caller's administrations, or 422.
	 *
	 * @spec openspec/changes/sales-down-payments/tasks.md#task-2.2
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$request = [];
		foreach (self::RAISE_FIELDS as $field) {
			$request[$field] = $this->request->getParam($field);
		}

		// ADR-005: the administration decides, before the order or the customer is read.
		if ($this->context->canAccess(administrationId: (string)($request['administrationId'] ?? '')) === false) {
			return new JSONResponse(
				data: ['message' => $this->l10n->t('You cannot invoice in this administration.')],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return $this->answer(
			work: fn (): array => $this->service->raise(request: $request),
			status: Http::STATUS_CREATED
		);

	}//end create()

	/**
	 * The order's down-payment position and the orders left to deduct (REQ-SDP-005).
	 *
	 * @param string $id The invoice uuid.
	 *
	 * @return JSONResponse 200 or 404.
	 *
	 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.2
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$invoice = $this->accessibleInvoice(invoiceId: $id);
		if ($invoice === null) {
			return $this->notFound();
		}

		return $this->answer(
			work: fn (): array => $this->panel(invoice: $invoice),
			status: Http::STATUS_OK
		);

	}//end show()

	/**
	 * Make a draft the final invoice of an order (REQ-SDP-003).
	 *
	 * @param string $id The draft invoice uuid.
	 *
	 * @return JSONResponse 200 with the invoice, 404, or 422.
	 *
	 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.1
	 */
	#[NoAdminRequired]
	public function deduct(string $id): JSONResponse {
		$invoice = $this->accessibleInvoice(invoiceId: $id);
		if ($invoice === null) {
			return $this->notFound();
		}

		$orderReference = trim((string)$this->request->getParam('orderReference', ''));
		return $this->answer(
			work: fn (): array => $this->service->deductOnto(invoice: $invoice, orderReference: $orderReference),
			status: Http::STATUS_OK
		);

	}//end deduct()

	/**
	 * What the invoice page's down-payment panel shows.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return array<string,mixed>
	 */
	private function panel(array $invoice): array {
		$isDraft = (string)($invoice['lifecycleState'] ?? '') === 'draft';
		$isDownPayment = (string)(($invoice['downPayment'] ?? [])['kind'] ?? '') === 'down-payment';
		$openOrders = [];
		if ($isDraft === true && $isDownPayment === false) {
			foreach ($this->service->openDownPayments(invoice: $invoice) as $downPayment) {
				$group = (array)($downPayment['downPayment'] ?? []);
				$key = (string)($group['orderReference'] ?? '');
				$openOrders[$key]['orderReference'] = $key;
				$openOrders[$key]['orderLabel'] = (string)($group['orderLabel'] ?? $key);
				$openOrders[$key]['grossAmount'] = round((($openOrders[$key]['grossAmount'] ?? 0) + (float)($downPayment['grossAmount'] ?? 0)), 2);
				$openOrders[$key]['downPayments'][] = [
					'id' => (string)($downPayment['id'] ?? ''),
					'invoiceNumber' => (string)($downPayment['invoiceNumber'] ?? ''),
					'grossAmount' => (float)($downPayment['grossAmount'] ?? 0),
					'paid' => ($downPayment['lifecycleState'] ?? '') === 'paid',
				];
			}
		}

		return [
			'kind' => (string)(($invoice['downPayment'] ?? [])['kind'] ?? ''),
			'orderLabel' => (string)(($invoice['downPayment'] ?? [])['orderLabel'] ?? ''),
			'position' => $this->service->position(invoice: $invoice),
			'openOrders' => array_values($openOrders),
			'canDeduct' => $openOrders !== [],
		];

	}//end panel()

	/**
	 * The invoice when it exists and the caller may see its administration.
	 *
	 * @param string $invoiceId The invoice uuid.
	 *
	 * @return array<string,mixed>|null
	 */
	private function accessibleInvoice(string $invoiceId): ?array {
		try {
			$invoice = $this->service->findInvoice(invoiceId: $invoiceId);
		} catch (OutOfBoundsException $e) {
			return null;
		}

		if ($this->context->canAccess(administrationId: (string)($invoice['administrationId'] ?? '')) === false) {
			return null;
		}

		return $invoice;

	}//end accessibleInvoice()

	/**
	 * Run the work and answer it, a refusal as 422 in the caller's language.
	 *
	 * @param callable $work   Returns the response body.
	 * @param int      $status The status on success.
	 *
	 * @return JSONResponse
	 */
	private function answer(callable $work, int $status): JSONResponse {
		try {
			return new JSONResponse(data: $work(), statusCode: $status);
		} catch (DownPaymentRefusedException $e) {
			return new JSONResponse(
				data: ['message' => $this->l10n->t($e->getTemplate(), $e->getParameters())],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Throwable $e) {
			$this->logger->error('DownPaymentController: request failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				data: ['message' => $this->l10n->t('The down payment could not be saved.')],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

	}//end answer()

	/**
	 * The masked 404.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(data: ['message' => $this->l10n->t('Invoice not found')], statusCode: Http::STATUS_NOT_FOUND);

	}//end notFound()
}//end class
