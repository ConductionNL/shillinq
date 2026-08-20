<?php

/**
 * Shillinq Settings Controller
 *
 * Controller for managing Shillinq application settings.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for managing Shillinq application settings.
 *
 * Shillinq KEEPS this bespoke controller rather than adopting OpenRegister's
 * `GenericSettingsController` (fragment-merge `shillinq_register.json` +
 * `register.d/*.json` config loading — see
 * `openspec/specs/apphost-adoption/spec.md`). The AppHost only aliases its
 * generic in when the leaf ships NO class of that name, so this class owes
 * every method the canonical route table routes to `settings#`:
 * `index` (GET), `create` (POST, legacy), `update` (PUT) and `load`.
 *
 * @spec openspec/specs/app-administration/spec.md
 */
class SettingsController extends Controller {
	/**
	 * Constructor for the SettingsController.
	 *
	 * @param IRequest $request The request object
	 * @param SettingsService $settingsService The settings service
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SettingsService $settingsService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Retrieve all current settings.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function index(): JSONResponse {
		return new JSONResponse(
			$this->settingsService->getSettings()
		);
	}//end index()

	/**
	 * Update settings with provided data — the canonical write.
	 *
	 * `\OCA\OpenRegister\AppHost\Routes::standard()` ships
	 * `['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT']`
	 * and shillinq's `appinfo/routes.php` returns that table verbatim, so
	 * `PUT /apps/shillinq/api/settings` is live. Because shillinq KEEPS its own
	 * `SettingsController` (fragment-merge config loading — see
	 * `openspec/specs/apphost-adoption/spec.md`),
	 * `AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()` never aliases
	 * OpenRegister's `GenericSettingsController` in, so this app owes every
	 * method the canonical table routes to `settings#`. A missing one is not a
	 * 404 — the router matches, `ControllerMethodReflector` reflects, and the
	 * request dies with a 500.
	 *
	 * Persists any supplied managed config key via `SettingsService::
	 * updateSettings()` (which filters to `SettingsService::CONFIG_KEYS`) and
	 * returns the refreshed settings. Byte-identical to what the legacy
	 * `create()` POST alias has always written.
	 *
	 * @return JSONResponse Envelope with `success` and the refreshed `config`.
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function update(): JSONResponse {
		$data = $this->request->getParams();
		$config = $this->settingsService->updateSettings($data);

		return new JSONResponse(
			[
				'success' => true,
				'config' => $config,
			]
		);
	}//end update()

	/**
	 * Legacy alias for {@see update()} — the POST spelling of the same write.
	 *
	 * The canonical AppHost route table still ships `settings#create`
	 * (POST /api/settings) for the pre-ADR-066 `index/create/load` dialect, and
	 * shillinq's admin UI still POSTs to it, so it stays reachable (ADR-029)
	 * and keeps writing exactly what it wrote before.
	 *
	 * The attribute is repeated deliberately: Nextcloud's SecurityMiddleware
	 * evaluates auth attributes on the DISPATCHED method only, so delegating to
	 * an `#[AuthorizedAdminSetting]` method does not inherit its posture.
	 *
	 * @return JSONResponse Envelope with `success` and the refreshed `config`.
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function create(): JSONResponse {
		return $this->update();
	}//end create()

	/**
	 * Re-import the configuration from shillinq_register.json.
	 *
	 * Forces a fresh import regardless of version, auto-configuring
	 * all schema and register IDs from the import result.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-2
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function load(): JSONResponse {
		$result = $this->settingsService->loadConfigurationForced();

		return new JSONResponse($result);
	}//end load()
}//end class
