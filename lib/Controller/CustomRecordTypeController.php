<?php

/**
 * Custom Record Type Controller
 *
 * The read endpoint tells any shillinq user which own record types are
 * shown; the admin endpoints list every own record type with its switch and
 * store the shown list. The list is the only thing these endpoints decide:
 * Open Register still applies its own authorisation to every record.
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
 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\Platform\CustomRecordTypeService;
use OCA\Shillinq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * GET / PUT /api/custom-record-types and the admin listing.
 */
class CustomRecordTypeController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request The request
	 * @param CustomRecordTypeService $service The custom record type service
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly CustomRecordTypeService $service,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The shown own record types, for any shillinq user.
	 *
	 * Security-endpoint-guards REQ-001 JUSTIFY: returns only schema slugs,
	 * titles, descriptions and icons of the app's own register, the same
	 * metadata Open Register serves to any user who can open the register; no
	 * record is read here.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-shown-record-types-have-list-and-detail-pages-req-crt-002
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return new JSONResponse(data: ['types' => $this->service->shownTypes()]);
	}//end index()

	/**
	 * Every own record type with whether it is shown, for the admin settings.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-an-administrator-chooses-which-own-record-types-show-req-crt-001
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function available(): JSONResponse {
		return new JSONResponse(
			data: [
				'types' => $this->service->customTypesWithState(),
				'shown' => (object)$this->service->shownMap(),
			]
		);
	}//end available()

	/**
	 * Store the shown list (admin only). Refuses a shipped or unknown slug.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-only-shown-record-types-can-be-opened-req-crt-003
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function update(): JSONResponse {
		$slugs = $this->request->getParam('shown');
		if (is_array($slugs) === false) {
			return new JSONResponse(
				data: ['message' => 'shown must be a list of record type slugs'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$types = $this->service->saveShown(slugs: array_values($slugs));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: ['types' => $types]);
	}//end update()

	/**
	 * Switch one own record type on or off (admin only), the write behind
	 * the admin settings' switch table. Refuses a shipped or unknown slug.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-an-administrator-chooses-which-own-record-types-show-req-crt-001
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function toggle(): JSONResponse {
		$slug = $this->request->getParam('slug');
		$shown = $this->request->getParam('shown');
		if (is_string($slug) === false || is_bool($shown) === false) {
			return new JSONResponse(
				data: ['message' => 'slug must be a record type slug and shown a boolean'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$types = $this->service->switchShown(slug: $slug, shown: $shown);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: ['types' => $types]);
	}//end toggle()
}//end class
