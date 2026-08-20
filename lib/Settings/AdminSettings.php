<?php

/**
 * Shillinq Admin Settings.
 *
 * Provides the admin settings form for Shillinq and, via IDelegatedSettings,
 * scopes the #[AuthorizedAdminSetting(AdminSettings::class)] guard used by the
 * controllers that mutate Shillinq configuration (including SetupController).
 *
 * @category Settings
 * @package  OCA\Shillinq\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://shillinq.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Settings;

use OCA\Shillinq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\IDelegatedSettings;

/**
 * Provides the admin settings form for the Shillinq application.
 */
class AdminSettings implements IDelegatedSettings {
	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param IInitialState $initialState The initial state service.
	 */
	public function __construct(
		private IAppManager $appManager,
		private IInitialState $initialState,
	) {
	}//end __construct()

	/**
	 * Get the settings form template.
	 *
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$version = $this->appManager->getAppVersion(appId: Application::APP_ID);

		$this->initialState->provideInitialState('version', $version);

		return new TemplateResponse(
			Application::APP_ID,
			'settings/admin',
			[]
		);
	}//end getForm()

	/**
	 * Get the section ID this settings page belongs to.
	 *
	 * @return string
	 */
	public function getSection(): string {
		return 'shillinq';
	}//end getSection()

	/**
	 * Get the priority for ordering within the section.
	 *
	 * @return int
	 */
	public function getPriority(): int {
		return 10;
	}//end getPriority()

	/**
	 * Human-readable name of the delegated settings section.
	 *
	 * @return string|null The section name, or null to use the section default.
	 */
	public function getName(): ?string {
		return null;
	}//end getName()

	/**
	 * App config keys an authorized (delegated) admin may manage.
	 *
	 * @return array<string,string[]> Map of appId to allowed config keys.
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}//end getAuthorizedAppConfig()
}//end class
