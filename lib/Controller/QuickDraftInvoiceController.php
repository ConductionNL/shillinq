<?php

/**
 * Quick Draft Invoice Controller
 *
 * POST /api/ar-invoices/quick-drafts saves a draft sales invoice that the
 * server numbers, totals and stamps with the caller's administration.
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
 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Sales\QuickDraftInvoiceService;
use OCA\Shillinq\Service\Sales\QuickDraftRefusedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Quick-draft invoice endpoint.
 *
 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
 */
class QuickDraftInvoiceController extends Controller {
	/**
	 * The request fields a quick draft is made from.
	 *
	 * @var list<string>
	 */
	private const DRAFT_FIELDS = [
		'customerId',
		'invoiceDate',
		'dueDate',
		'reference',
		'glAccount',
		'lines',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request The request.
	 * @param QuickDraftInvoiceService     $service The quick-draft rules.
	 * @param AdministrationContextService $context The caller's administrations.
	 * @param IL10N                        $l10n    Translations for refusals.
	 * @param LoggerInterface              $logger  Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly QuickDraftInvoiceService $service,
		private readonly AdministrationContextService $context,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Save a quick draft in the named administration, else the active one.
	 *
	 * @return JSONResponse 201 with the draft, 403 outside the caller's administrations, 422 when refused.
	 *
	 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if ($administrationId !== '' && $this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(
				data: ['message' => $this->l10n->t('You cannot invoice in this administration.')],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		if ($administrationId === '') {
			$administrationId = (string)($this->context->buildContext()['activeAdministrationId'] ?? '');
		}

		$request = [];
		foreach (self::DRAFT_FIELDS as $field) {
			$request[$field] = $this->request->getParam($field);
		}

		try {
			return new JSONResponse(
				data: $this->service->draft(administrationId: $administrationId, request: $request),
				statusCode: Http::STATUS_CREATED
			);
		} catch (QuickDraftRefusedException $e) {
			return new JSONResponse(
				data: ['message' => $this->l10n->t($e->getMessage())],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Throwable $e) {
			$this->logger->error('QuickDraftInvoiceController.create failed: ' . $e->getMessage());
			return new JSONResponse(
				data: ['message' => $this->l10n->t('Failed to create draft invoice.')],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end create()
}//end class
