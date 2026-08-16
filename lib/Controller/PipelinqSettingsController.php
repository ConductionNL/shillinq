<?php

/**
 * Pipelinq Settings Controller.
 *
 * Admin-only REST endpoints for the pipelinq connection settings:
 * GET the current endpoint + a hasToken flag (never the token
 * itself), POST to save endpoint + token, and POST to run a live
 * health-check ("Test Connection").
 *
 * Member 1 of the bookings-pipelinq-customer-bridge chain (ADR-032).
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\Pipelinq\PipelinqConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only controller exposing pipelinq connection settings.
 *
 * `index()` returns the current endpoint and a `hasToken` flag —
 * the token itself is never returned to the frontend, so a page
 * reload always renders the token input as masked (per the
 * configuration-is-persisted-securely scenario).
 *
 * `create()` saves the endpoint (and the token if explicitly
 * provided). Submitting the form without a token preserves the
 * existing one — this lets admins update the endpoint without
 * re-entering the secret.
 *
 * `test()` runs PipelinqConfig::testConnection() and returns the
 * outcome (success, status, message) for the "Test Connection"
 * button.
 *
 * All three actions are gated by Nextcloud's
 * `#[AuthorizedAdminSetting]` so only admins authorised for the
 * `shillinq` settings section may invoke them.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 */
class PipelinqSettingsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request.
	 * @param PipelinqConfig $pipelinqConfig The connection config service.
	 */
	public function __construct(
		IRequest $request,
		private readonly PipelinqConfig $pipelinqConfig,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET the current pipelinq connection settings.
	 *
	 * Returns the endpoint URL and a `hasToken` boolean. The token
	 * itself is intentionally NOT included so a page reload renders
	 * the token input as masked — only an admin who actively wants
	 * to rotate the token re-enters it.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function index(): JSONResponse {
		return new JSONResponse(
			[
				'endpoint' => $this->pipelinqConfig->getPipelinqEndpoint(),
				'hasToken' => $this->pipelinqConfig->hasPipelinqToken(),
			]
		);

	}//end index()

	/**
	 * POST to update the pipelinq connection settings.
	 *
	 * Accepts `endpoint` (string) and an optional `token` (string).
	 * When `token` is absent or null, the currently-stored token is
	 * preserved — supporting the "edit endpoint only" case without
	 * forcing the admin to re-enter the secret. Explicitly passing
	 * an empty string clears the token (rotation/removal).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function create(): JSONResponse {
		$endpoint = (string)($this->request->getParam('endpoint') ?? '');
		$this->pipelinqConfig->setPipelinqEndpoint($endpoint);

		// Token is optional; absent => preserve, '' => clear, set => rotate.
		$tokenParam = $this->request->getParam('token');
		if ($tokenParam !== null) {
			$this->pipelinqConfig->setPipelinqToken((string)$tokenParam);
		}

		return new JSONResponse(
			[
				'success' => true,
				'endpoint' => $this->pipelinqConfig->getPipelinqEndpoint(),
				'hasToken' => $this->pipelinqConfig->hasPipelinqToken(),
			]
		);

	}//end create()

	/**
	 * POST to issue a pipelinq health-check ("Test Connection").
	 *
	 * Returns whatever PipelinqConfig::testConnection() resolves to
	 * (success/status/message). The token itself is never echoed
	 * back; only its presence is implicit in the outcome.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function test(): JSONResponse {
		return new JSONResponse(
			$this->pipelinqConfig->testConnection()
		);

	}//end test()
}//end class
