<?php

/**
 * Import Period
 *
 * Answers whether the fiscal year of an import's migration date is open.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Import;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Whether a migration date falls in an open fiscal year of its administration.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
class ImportPeriod {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SettingsService        $settings      Supplies the register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * True when the fiscal year holding the date is open or reopened.
	 *
	 * An administration that has no fiscal year around the date yet is open:
	 * an import usually comes before the first year is set up.
	 *
	 * @param string $administrationId The administration.
	 * @param string $date             The migration date (Y-m-d).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function isOpen(string $administrationId, string $date): bool {
		$years = $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema('FiscalYear')
			->findAll(['filters' => ['administrationId' => $administrationId]]);
		foreach ($years as $row) {
			$year = ObjectIdentifier::recordWithId(candidate: $row);
			if ($year === null || (string)($year['startDate'] ?? '') > $date || (string)($year['endDate'] ?? '') < $date) {
				continue;
			}

			return in_array((string)($year['state'] ?? ''), ['open', 'reopened'], true);
		}

		return true;

	}//end isOpen()
}//end class
