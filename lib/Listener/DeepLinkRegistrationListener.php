<?php

/**
 * Shillinq DeepLinkRegistrationListener
 *
 * Registers Shillinq's deep link URL patterns with OpenRegister's search provider.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
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

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\Shillinq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Registers Shillinq's deep link URL patterns with OpenRegister's search provider.
 *
 * When a user searches in Nextcloud's unified search, results for Shillinq schemas
 * will link directly to the relevant detail views in the app.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 * @spec openspec/changes/shillinq-ap-push-notifications/specs/bookkeeping-accounts-payable-core/spec.md
 */
class DeepLinkRegistrationListener implements IEventListener {
	/**
	 * Construct the listener with app config for dynamic register-slug resolution (L3).
	 *
	 * @param IAppConfig $appConfig App config — reads the 'register' key to resolve
	 *                              the actual register slug set by the admin. Falls
	 *                              back to the canonical default 'shillinq'.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Handle the deep link registration event.
	 *
	 * Registers deep links for real Shillinq schemas. The `account` schema is
	 * the only schema present in T1. Additional schemas (invoice, payment, etc.)
	 * should be added here once their Vue detail routes are implemented.
	 *
	 * The register slug is read from app config (key: 'register') instead of being
	 * hardcoded, so deep links keep working if the admin binds the app to a
	 * non-default register (L3).
	 *
	 * @param Event $event The event to handle
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
	 * @spec openspec/changes/shillinq-ap-push-notifications/specs/bookkeeping-accounts-payable-core/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof DeepLinkRegistrationEvent === false) {
			return;
		}

		// L3: read the configured register slug; fall back to 'shillinq' if unset.
		$registerSlug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($registerSlug === '') {
			$registerSlug = 'shillinq';
		}

		// Register deep link for the Account schema (T1 schema).
		$event->register(
			appId: 'shillinq',
			registerSlug: $registerSlug,
			schemaSlug: 'account',
			urlTemplate: '/apps/shillinq/chart-of-accounts/{uuid}'
		);

		// Register deep link for the APTransaction (accounts-payable invoice)
		// schema. Targets the APTransactionDetail route declared in
		// src/manifest.d/bookkeeping-accounts-payable-core.json
		// (/bookkeeping/ap-transactions/:id, schema APTransaction). This is the
		// route the "Open invoice" web-push notification action deeplinks to.
		$event->register(
			appId: 'shillinq',
			registerSlug: $registerSlug,
			schemaSlug: 'APTransaction',
			urlTemplate: '/apps/shillinq/bookkeeping/ap-transactions/{uuid}'
		);

	}//end handle()
}//end class
