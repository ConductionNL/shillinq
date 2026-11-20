<?php

/**
 * Payment Revenue Accounts Controller
 *
 * The administration screen's read and write of `paymentRevenueAccounts`,
 * the mapping from a payment request's type to the general-ledger account
 * its receipt books against (REQ-SOPR-002). One flat field per type, so the
 * shared settings section can render and save it.
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
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\PaymentRevenueAccountResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only read and write of the revenue-account mapping.
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
 */
class PaymentRevenueAccountsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request  The current HTTP request.
	 * @param PaymentRevenueAccountResolver $accounts The mapping's store.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PaymentRevenueAccountResolver $accounts,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET the mapping, one key per request type, empty where unmapped.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
	 */
	#[AuthorizedAdminSetting(AppIdentity::APP_ID)]
	public function index(): JSONResponse {
		return new JSONResponse($this->accounts->form());
	}//end index()

	/**
	 * PUT the submitted types' accounts; an empty value removes a mapping.
	 *
	 * @return JSONResponse The stored mapping, or 400 with nothing stored.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
	 */
	#[AuthorizedAdminSetting(AppIdentity::APP_ID)]
	public function update(): JSONResponse {
		$submitted = [];
		foreach (PaymentRevenueAccountResolver::TYPES as $type) {
			$value = $this->request->getParam($type);
			if ($value !== null) {
				$submitted[$type] = $value;
			}
		}

		try {
			$this->accounts->save(submitted: $submitted);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(array_merge(['success' => true], $this->accounts->form()));
	}//end update()
}//end class
