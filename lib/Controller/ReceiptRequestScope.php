<?php

/**
 * Receipt Request Scope
 *
 * The administration a goods-receipt or service-receipt request works in.
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

use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The checked administration of a receipt request.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
final class ReceiptRequestScope {
	/**
	 * Allowed shape of an administration id.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param AdministrationContextServiceInterface $administrationContext The caller's memberships.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly AdministrationContextServiceInterface $administrationContext,
	) {
	}//end __construct()

	/**
	 * The request's administration when the caller may access it; a 400
	 * without a well-formed one, a masked 404 with the given message otherwise.
	 *
	 * @param string $notFound The 404 message.
	 *
	 * @return string|JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function administration(string $notFound): string|JSONResponse {
		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if ($administrationId === '' || preg_match(self::ID_PATTERN, $administrationId) !== 1) {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => $notFound], Http::STATUS_NOT_FOUND);
		}

		return $administrationId;

	}//end administration()
}//end class
