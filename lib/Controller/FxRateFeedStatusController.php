<?php

/**
 * Shillinq FX Rate Feed Status Controller
 *
 * Read-only endpoint the FX rates index page reads for its status line:
 * whether rates arrive from the feed or are typed, and the newest rate date
 * per currency pair. The rates are read through OpenRegister with the
 * caller's own register access, so the line shows nothing the grid would not.
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
 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\Treasury\FxRateFeedStatus;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read-only FX rate feed status for the FX rates page.
 *
 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-3.1
 */
class FxRateFeedStatusController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest         $request    Nextcloud request.
	 * @param FxRateFeedStatus $feedStatus The feed state and newest rates.
	 */
	public function __construct(
		IRequest $request,
		private readonly FxRateFeedStatus $feedStatus,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the feed status envelope.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-3.1
	 */
	#[NoAdminRequired]
	public function status(): JSONResponse {
		return new JSONResponse($this->feedStatus->status());
	}//end status()
}//end class
