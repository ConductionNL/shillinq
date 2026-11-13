<?php

/**
 * VAT Position Controller
 *
 * GET /api/vat-position?administrationId=&period= answers the running VAT
 * position of a period per return box (REQ-VPS-001). Authenticated with
 * #[NoAdminRequired] and authorised per administration through
 * AdministrationContextService::canAccess(): an administration the caller is
 * not a member of answers 404, the same as one that does not exist.
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
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Tax\VatPositionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The VAT position endpoint.
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.2
 */
class VatPositionController extends Controller {

	/**
	 * The shape of an administration id.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request  The request.
	 * @param VatPositionService           $position The position service.
	 * @param IUserSession                 $session  The user session.
	 * @param AdministrationContextService $context  The caller's administrations.
	 * @param LoggerInterface              $logger   The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly VatPositionService $position,
		private readonly IUserSession $session,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The VAT position of an administration for a period.
	 *
	 * @return JSONResponse 200 with the position; 400 for a missing administration or a malformed
	 *                      period; 401 without a user; 404 for an administration the caller may not see.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.2
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if (preg_match(self::ID_PATTERN, $administrationId) !== 1) {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$period = trim((string)$this->request->getParam('period', ''));
		try {
			$position = $this->position->positionFor(administrationId: $administrationId, period: $period);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error(
				'VatPositionController: failed to read the VAT position',
				['administrationId' => $administrationId, 'period' => $period, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to read the VAT position'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['data' => $position], Http::STATUS_OK);
	}//end show()
}//end class
