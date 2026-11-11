<?php

/**
 * Receipt Request Guard
 *
 * The checks every goods-receipt and service-receipt request passes before it
 * reaches its service, in order: a logged-in user (401), for a request about
 * one receipt a well-formed receipt id (400), and a well-formed administration
 * the caller may access (400, or a masked 404).
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;

/**
 * Resolves the administration of a receipt request, or the refusal.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
final class ReceiptRequestGuard {
	/**
	 * Allowed shape of a receipt id.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IUserSession        $userSession The session, for the 401 check.
	 * @param ReceiptRequestScope $scope       The request's administration check.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ReceiptRequestScope $scope,
	) {
	}//end __construct()

	/**
	 * The administration a new receipt is created in: 401 without a user, 400
	 * without a well-formed administration, a masked 404 for a foreign one.
	 *
	 * @return string|JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function newReceipt(): string|JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->scope->administration(notFound: 'Administration not found');

	}//end newReceipt()

	/**
	 * The administration of a request about one receipt: 401 without a user,
	 * 400 for a malformed id or administration, a masked 404 for a foreign one.
	 *
	 * @param string       $id    The receipt id.
	 * @param ReceiptNames $names How the refusals name the receipt.
	 *
	 * @return string|JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function receipt(string $id, ReceiptNames $names): string|JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => $names->invalidId], Http::STATUS_BAD_REQUEST);
		}

		return $this->scope->administration(notFound: $names->notFound);

	}//end receipt()
}//end class
