<?php

/**
 * AppHost Registration
 *
 * Points shillinq's settings classes at OpenRegister's AppHost generics,
 * registers the app's metrics provider and aliases the store controller.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\CustomerBridgeMetricsServiceInterface;
use OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Wires the AppHost generic settings, the metrics provider and the store alias.
 *
 * The customer-bridge metrics service is registered as the app's
 * {@see \OCA\OpenRegister\AppHost\IMetricsProvider} under the ADR-035 alias
 * `…IMetricsProvider::shillinq`, so the manifest `{"kind":"provider"}`
 * descriptor merges its series into the generic Prometheus exposition.
 *
 * All references to OCA\OpenRegister\AppHost\… classes that get built are
 * STRINGS resolved inside closures, so a disabled OpenRegister never fatals
 * bootstrap.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
final class AppHostRegistration {

	private const APP_ID = AppIdentity::APP_ID;

	/**
	 * Register the generic settings, metrics provider and store alias.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerSettings(context: $context);

		// ADR-035 provider discovery: expose the customer-bridge counters to the
		// observability engine's `{"kind":"provider"}` metric descriptor.
		$context->registerService(
			'OCA\\OpenRegister\\AppHost\\IMetricsProvider::' . self::APP_ID,
			static function (ContainerInterface $c): CustomerBridgeMetricsServiceInterface {
				return $c->get(CustomerBridgeMetricsService::class);
			}
		);

		$this->bindStoreController(context: $context);

	}//end register()

	/**
	 * Point the admin settings page and its section at the generics.
	 *
	 * The info.xml file references the LEAF class names (OCA\Shillinq\Settings\AdminSettings
	 * / Sections\SettingsSection), so Nextcloud's settings Manager resolves them
	 * through Shillinq's own DI container, where these factories inject the leaf
	 * metadata (appId, section id "shillinq", priority, icon). Registering under
	 * the generic OCA\OpenRegister\... names instead would route resolution to
	 * OpenRegister's container, which has no appId bound, and blank every
	 * settings page in the instance.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerSettings(IRegistrationContext $context): void {
		$appId = self::APP_ID;

		$context->registerService(
			'OCA\\Shillinq\\Settings\\AdminSettings',
			static function (ContainerInterface $c) use ($appId) {
				$class = 'OCA\\OpenRegister\\AppHost\\Settings\\GenericAdminSettings';
				return new $class(
					appId: $appId,
					sectionId: $appId,
					priority: 10,
					appManager: $c->get('OCP\\App\\IAppManager'),
					initialState: $c->get('OCP\\AppFramework\\Services\\IInitialState'),
					appConfig: $c->get('OCP\\IAppConfig')
				);
			}
		);

		$context->registerService(
			'OCA\\Shillinq\\Sections\\SettingsSection',
			static function (ContainerInterface $c) use ($appId) {
				$class = 'OCA\\OpenRegister\\AppHost\\Settings\\GenericSettingsSection';
				return new $class(
					sectionId: $appId,
					name: 'Shillinq',
					appId: $appId,
					iconFile: 'app-dark.svg',
					priority: 75,
					urlGenerator: $c->get('OCP\\IURLGenerator')
				);
			}
		);

	}//end registerSettings()

	/**
	 * Alias `Controller\StoreController` to OpenRegister's generic store
	 * controller, which serves the `store#search` and `store#install` routes
	 * that `Routes::standard()` declares.
	 *
	 * The class_exists() guard stays in this method: psalm relies on it to
	 * accept the Bootstrap call and does not carry that narrowing across a call.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function bindStoreController(IRegistrationContext $context): void {
		// Gate-64: apphost-prelude exclude OpenRegisterAutoloader::register() below is the prelude.
		OpenRegisterAutoloader::register();

		if (class_exists(Bootstrap::class) === true) {
			try {
				Bootstrap::aliasStoreController(
					context: $context,
					appId: self::APP_ID,
					controllerNs: 'OCA\\Shillinq\\Controller'
				);
			} catch (\Throwable) {
				// An OpenRegister older than the helper, or present but
				// unloadable: the store routes stay unbound and every other
				// registration still runs.
			}
		}

	}//end bindStoreController()
}//end class
