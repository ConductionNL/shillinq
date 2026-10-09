<?php

/**
 * Reserve Overview Controller
 *
 * Serves the multi-year reserve overview (public-sector-reserves-and-interest,
 * REQ-PSRI-002): per reserve and year the opening balance, additions,
 * withdrawals and closing balance, for a year and the four after it, with the
 * years that count planned mutations marked and the floor and ceiling flags.
 * Only a member of the administration may read it.
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
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PublicSector\ReserveBalances;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The multi-year reserve overview endpoint.
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 */
class ReserveOverviewController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request  The request.
	 * @param ReserveBalances              $balances The reserve balances.
	 * @param AdministrationContextService $context  Administration access.
	 * @param IL10N                        $l10n     Translations.
	 * @param LoggerInterface              $logger   Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ReserveBalances $balances,
		private readonly AdministrationContextService $context,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The multi-year overview of the administration's reserves.
	 *
	 * Query: `administrationId` (default the active administration) and
	 * `year` (default the current year).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	#[NoAdminRequired]
	public function overview(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if ($administrationId === '') {
			$administrationId = (string)($this->context->buildContext()['activeAdministrationId'] ?? '');
		}

		if ($administrationId === '' || $this->context->canAccess(administrationId: $administrationId) === false) {
			return $this->message(text: 'Administration not found', status: Http::STATUS_NOT_FOUND);
		}

		$year = (int)$this->request->getParam('year', 0);
		if ($year < 1900 || $year > 2999) {
			$year = (int)gmdate('Y');
		}

		try {
			return new JSONResponse(data: $this->balances->overview(administrationId: $administrationId, fromYear: $year));
		} catch (Throwable $e) {
			$this->logger->error('ReserveOverviewController: overview failed', ['exception' => $e->getMessage()]);
			return $this->message(text: 'The reserve overview could not be loaded.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end overview()

	/**
	 * A translated message response.
	 *
	 * @param string $text   The message.
	 * @param int    $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function message(string $text, int $status): JSONResponse {
		return new JSONResponse(data: ['message' => $this->l10n->t($text)], statusCode: $status);

	}//end message()
}//end class
