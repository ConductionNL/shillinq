<?php

/**
 * Sales orders from quotes and draft invoices from sales orders.
 *
 * POST /api/quotes/{id}/order turns an accepted quote into a draft sales
 * order; POST /api/orders/{id}/invoice writes a draft sales invoice for what
 * a confirmed sales order has not invoiced yet. The object's administration
 * is checked against the caller's before anything is written; another
 * administration's object answers 404, the same as a missing one.
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
 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Sales\SalesOrderInvoicingService;
use OCA\Shillinq\Service\Sales\SalesOrderRefusedException;
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
 * Quote-to-order and order-to-invoice endpoints.
 *
 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md
 */
class SalesOrderController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request The request.
	 * @param SalesOrderInvoicingService   $service The conversions.
	 * @param AdministrationContextService $context Administration access check.
	 * @param IL10N                        $l10n    Translations for refusals.
	 * @param LoggerInterface              $logger  Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly SalesOrderInvoicingService $service,
		private readonly AdministrationContextService $context,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Turn an accepted quote into a draft sales order (REQ-QTI-001).
	 *
	 * @param string $id The quote uuid or number.
	 *
	 * @return JSONResponse 201 with the order, 404 when missing or outside the caller's administrations, or 422.
	 *
	 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md#requirement-an-accepted-quote-becomes-a-sales-order-req-qti-001
	 */
	#[NoAdminRequired]
	public function fromQuote(string $id): JSONResponse {
		$administrationId = $this->accessibleAdministration(find: fn (): array => $this->service->findQuote(quoteId: $id));
		if ($administrationId === null) {
			return $this->notFound(message: $this->l10n->t('Quote not found'));
		}

		return $this->answer(
			work: fn (): array => $this->service->orderFromQuote(quoteId: $id, administrationId: $administrationId),
			notFound: $this->l10n->t('Quote not found')
		);

	}//end fromQuote()

	/**
	 * Write a draft sales invoice for what a confirmed sales order has not invoiced yet (REQ-QTI-003).
	 *
	 * @param string $id The order uuid or number.
	 *
	 * @return JSONResponse 201 with the invoice, 404 when missing or outside the caller's administrations, or 422.
	 *
	 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md#requirement-a-confirmed-sales-order-becomes-a-draft-invoice-req-qti-003
	 */
	#[NoAdminRequired]
	public function invoice(string $id): JSONResponse {
		$administrationId = $this->accessibleAdministration(find: fn (): array => $this->service->findOrder(orderId: $id));
		if ($administrationId === null) {
			return $this->notFound(message: $this->l10n->t('Order not found'));
		}

		return $this->answer(
			work: fn (): array => $this->service->invoiceFromOrder(orderId: $id, administrationId: $administrationId),
			notFound: $this->l10n->t('Order not found')
		);

	}//end invoice()

	/**
	 * The object's administration when it exists and the caller may work in it (ADR-005: before any write).
	 *
	 * @param callable $find Reads the object; throws OutOfBoundsException when it does not exist.
	 *
	 * @return string|null The administration id, or null to answer 404.
	 */
	private function accessibleAdministration(callable $find): ?string {
		try {
			$record = $find();
		} catch (OutOfBoundsException $e) {
			return null;
		}

		$administrationId = (string)($record['administrationId'] ?? '');
		if ($administrationId === '' || $this->context->canAccess(administrationId: $administrationId) === false) {
			return null;
		}

		return $administrationId;

	}//end accessibleAdministration()

	/**
	 * Run the work and answer it: 201, a refusal as 422 in the caller's language, a vanished object as 404.
	 *
	 * @param callable $work     Returns the response body.
	 * @param string   $notFound The 404 message.
	 *
	 * @return JSONResponse
	 */
	private function answer(callable $work, string $notFound): JSONResponse {
		try {
			return new JSONResponse(data: $work(), statusCode: Http::STATUS_CREATED);
		} catch (SalesOrderRefusedException $e) {
			return new JSONResponse(
				data: ['message' => $this->l10n->t($e->getTemplate(), $e->getParameters())],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (OutOfBoundsException $e) {
			return $this->notFound(message: $notFound);
		} catch (Throwable $e) {
			$this->logger->error('SalesOrderController: request failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				data: ['message' => $this->l10n->t('The sales order could not be saved.')],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

	}//end answer()

	/**
	 * The masked 404.
	 *
	 * @param string $message The message.
	 *
	 * @return JSONResponse
	 */
	private function notFound(string $message): JSONResponse {
		return new JSONResponse(data: ['message' => $message], statusCode: Http::STATUS_NOT_FOUND);

	}//end notFound()
}//end class
