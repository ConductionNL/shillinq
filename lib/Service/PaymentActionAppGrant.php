<?php

/**
 * Payment Action App Grant
 *
 * The app half of `PaymentActionAuthorizer`. A consuming app sometimes has to
 * ask for money with nobody signed in who may: larpinq accepts a registration
 * when a player signs up for a free place, or when its daily job moves the
 * oldest waitlisted registration up. Giving the player `payment.request` would
 * let them raise any request on any object, and a background job has no user
 * at all.
 *
 * So an administrator grants the action to the APP, in app config
 * (`paymentActionApps`, for example `{"payment.request": ["larpinq"]}`). This
 * class answers one question: does the named app carry the action. It fails
 * closed: an empty or malformed app id, no mapping, a malformed mapping, an
 * app not named, or an app that is not enabled all answer false. Unlike a
 * user, no app carries an action by default; there is no administrator
 * equivalent.
 *
 * The app id is never read from a request: it reaches this class only
 * through `PaymentRequestLeafProvider::createAsApp()`, which OpenRegister's
 * HTTP surface does not call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payment-request-app-caller/specs/object-payment-requests/spec.md (REQ-SOPR-010)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * Answers whether a named app carries a payment action.
 *
 * @spec openspec/changes/payment-request-app-caller/specs/object-payment-requests/spec.md (REQ-SOPR-010)
 */
final class PaymentActionAppGrant {
	/**
	 * The app-config key mapping an action to the apps that carry it.
	 *
	 * @var string
	 */
	public const CONFIG_ACTION_APPS = 'paymentActionApps';

	/**
	 * What an app id looks like (Nextcloud's own rule for app ids).
	 *
	 * @var string
	 */
	private const APP_ID = '/^[a-z][a-z0-9_]{0,63}$/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config holding the app grants.
	 * @param IAppManager $appManager Whether the named app is enabled.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * True when the named app carries the action.
	 *
	 * @param string $appId The calling app's id.
	 * @param string $action The action, one of PaymentActionAuthorizer's ACTION_* constants.
	 *
	 * @return bool True when the app carries it.
	 *
	 * @spec openspec/changes/payment-request-app-caller/specs/object-payment-requests/spec.md (REQ-SOPR-010)
	 */
	public function allows(string $appId, string $action): bool {
		if (preg_match(self::APP_ID, $appId) !== 1) {
			return false;
		}

		$decoded = json_decode($this->appConfig->getValueString('shillinq', self::CONFIG_ACTION_APPS, ''), true);
		if (is_array($decoded) === false) {
			return false;
		}

		$allowed = ($decoded[$action] ?? []);
		if (is_array($allowed) === false || in_array($appId, $allowed, true) === false) {
			return false;
		}

		return $this->appManager->isEnabledForAnyone($appId);
	}//end allows()
}//end class
