<?php

/**
 * Shillinq Admin Settings Section.
 *
 * Registers the Shillinq section in the Nextcloud admin settings, referenced by
 * appinfo/info.xml's <admin-section>. Hosts the AdminSettings form.
 *
 * @category Settings
 * @package  OCA\Shillinq\Sections
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

namespace OCA\Shillinq\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Defines the Shillinq section in the Nextcloud admin settings.
 */
class SettingsSection implements IIconSection {
	/**
	 * Constructor for SettingsSection.
	 *
	 * @param IL10N $l The localization service.
	 * @param IURLGenerator $urlGenerator The URL generator service.
	 */
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Get the section identifier.
	 *
	 * @return string
	 */
	public function getID(): string {
		return 'shillinq';
	}//end getID()

	/**
	 * Get the display name of this section.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->l->t('Shillinq');
	}//end getName()

	/**
	 * Get the priority for ordering this section.
	 *
	 * @return int
	 */
	public function getPriority(): int {
		return 75;
	}//end getPriority()

	/**
	 * Get the section icon path.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(appName: 'shillinq', file: 'app-dark.svg');
	}//end getIcon()
}//end class
