<?php

/**
 * Shillinq BackfillAdministrationJurisdiction.
 *
 * Gives every administration that names no jurisdiction the jurisdiction NL,
 * the rules every administration was kept under before jurisdictions existed.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Jurisdiction\AdministrationJurisdiction;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sets jurisdiction NL on administrations that name none. Idempotent; never overwrites.
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */
class BackfillAdministrationJurisdiction implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param RegisterLocation       $settings      Where the register lives.
	 * @param LoggerInterface        $logger        Logger.
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function getName(): string {
		return 'Shillinq: give every administration a jurisdiction (NL when it names none)';
	}//end getName()

	/**
	 * Set NL on each administration without a jurisdiction.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$register = $this->settings->getRegisterSlug();
			$stamped = 0;
			$rows = $this->objectService->setRegister($register)->setSchema('Administration')->findAll(['limit' => 100000]);
			foreach ($rows as $row) {
				$administration = $row;
				if (is_array($row) === false) {
					$administration = $row->jsonSerialize();
				}

				$id = (string)($administration['id'] ?? ($administration['@self']['id'] ?? ''));
				if ($id === '' || trim((string)($administration['jurisdiction'] ?? '')) !== '') {
					continue;
				}

				$this->objectService->patchObject(
					objectId: $id,
					data: ['jurisdiction' => AdministrationJurisdiction::DEFAULT],
					register: $register,
					schema: 'Administration'
				);
				$stamped++;
			}

			$output->info(sprintf('Shillinq: %d administration(s) given jurisdiction NL.', $stamped));
		} catch (Throwable $e) {
			$output->warning('Shillinq: giving administrations a jurisdiction failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: giving administrations a jurisdiction failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end run()
}//end class
