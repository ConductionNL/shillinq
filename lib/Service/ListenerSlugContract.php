<?php

/**
 * Feature gate for shillinq's corrected listener schema matching.
 *
 * Shillinq's OpenRegister listeners compared a schema **id** against a schema
 * **slug** literal, so their handler bodies had never run once. Correcting the
 * comparison is a small change, but it is emphatically not a behaviour-neutral
 * one. The listeners it wakes include:
 *
 *   - a listener that sends **customer email**;
 *   - four making **outbound HTTP** calls (pipelinq, TenderNed, docudesk,
 *     decidesk);
 *   - six doing **bulk object creation** — the whole Peppol backlog, and one
 *     lease payment-schedule row per lease period;
 *   - a **fail-closed** commitment check that would begin blocking
 *     PurchaseOrder approvals which succeed today.
 *
 * None of them are idempotent in the strong sense and there is no backfill, so
 * enabling them on an instance with existing data will act on that backlog the
 * first time a matching write occurs. That is a business decision, not a bug
 * fix.
 *
 * This gate exists so the correction can ship, be reviewed and be tested
 * without silently switching all of that on in one deploy. It mirrors
 * `openbuild.listener_slug_contract` (openbuild#82) and the default-off flag
 * openregister#2248 used for the sibling `ObjectTransitionedEvent` defect.
 *
 * Default: OFF. Enable per instance, only after reviewing each handler body:
 *   occ config:app:set shillinq listener_slug_contract --value=yes
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reports whether the corrected listener schema matching is enabled.
 */
class ListenerSlugContract {

	/**
	 * The config key holding the flag.
	 *
	 * @var string
	 */
	private const CONFIG_KEY = 'listener_slug_contract';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Nextcloud app configuration.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether the corrected slug comparison should be honoured.
	 *
	 * @return bool True when the contract is enabled for this instance.
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::CONFIG_KEY, false);
	}//end isEnabled()
}//end class
