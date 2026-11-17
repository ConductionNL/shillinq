<?php

/**
 * Shillinq SBR filing download controller
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Downloads the XBRL file of an SBR filing (XbrlInstance), in any state.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md#requirement-annual-accounts-produce-their-kvk-xbrl-instance-req-tdf-002
 */
class SbrFilingController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request The request.
	 * @param SbrRecords                            $records Reads the filing.
	 * @param AdministrationContextServiceInterface $context Whether the caller may see its administration.
	 * @param IUserSession                          $session The caller.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-4.2
	 */
	public function __construct(
		IRequest $request,
		private readonly SbrRecords $records,
		private readonly AdministrationContextServiceInterface $context,
		private readonly IUserSession $session,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The filing's XBRL file.
	 *
	 * The filing is read with OpenRegister's access checks for the caller, and
	 * answered only when the caller is a member of its administration; any
	 * other filing reads as not found.
	 *
	 * @param string $id The XbrlInstance uuid.
	 *
	 * @return DataDownloadResponse|JSONResponse The file, or 401, 404 or 500.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-4.2
	 */
	#[NoAdminRequired]
	public function download(string $id): DataDownloadResponse|JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$instance = $this->records->find(schema: 'XbrlInstance', id: $id);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => 'The filing could not be read.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$denied = $this->requireAccessibleFiling(instance: $instance);
		if ($denied !== null) {
			return $denied;
		}

		$xml = (string) $instance['instanceXml'];

		$name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($instance['instanceNumber'] ?? $id));

		return new DataDownloadResponse($xml, $name . '.xbrl', 'application/xml');
	}//end download()

	/**
	 * The 404 for a filing that is absent, outside the caller's administrations or without a document; null when it may be read.
	 *
	 * @param array<string,mixed>|null $instance The filing.
	 *
	 * @return JSONResponse|null The refusal.
	 */
	private function requireAccessibleFiling(?array $instance): ?JSONResponse {
		if ($instance === null
			|| $this->context->canAccess(administrationId: (string) ($instance['administrationId'] ?? '')) === false
			|| trim((string) ($instance['instanceXml'] ?? '')) === ''
		) {
			return new JSONResponse(['error' => 'Filing not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end requireAccessibleFiling()
}//end class
