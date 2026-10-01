<?php

/**
 * Fold CustomerMaster.vatID into vatId
 *
 * A customer's VAT number had two spellings: `vatID` (compliance fragment)
 * and `vatId` (ICP fragment, the one VIES checks read). `vatID` is retired
 * from the schema; this step copies its value into `vatId` wherever `vatId`
 * is still empty, so no number is lost (tax-vat-number-check D3).
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies a customer's `vatID` into `vatId` where `vatId` is empty. Idempotent.
 */
class FoldCustomerVatId implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SettingsService        $settings      For the register slug.
	 * @param LoggerInterface        $logger        Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Shillinq: keep each customer VAT number in one field (vatId)';

	}//end getName()

	/**
	 * Copy `vatID` into an empty `vatId`.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-1.1
	 */
	public function run(IOutput $output): void {
		try {
			$register = $this->settings->getRegisterSlug();
			$folded = 0;
			$rows = $this->objectService->setRegister($register)->setSchema('CustomerMaster')->findAll(['limit' => 100000]);
			foreach ($rows as $row) {
				$customer = $row;
				if (is_array($row) === false) {
					$customer = $row->jsonSerialize();
				}

				$legacy = trim((string)($customer['vatID'] ?? ''));
				$id = (string)($customer['id'] ?? ($customer['@self']['id'] ?? ''));
				if ($legacy === '' || $id === '' || trim((string)($customer['vatId'] ?? '')) !== '') {
					continue;
				}

				$this->objectService->patchObject(objectId: $id, data: ['vatId' => $legacy], register: $register, schema: 'CustomerMaster');
				$folded++;
			}

			$output->info(sprintf('Shillinq: %d customer VAT number(s) moved from vatID to vatId.', $folded));
		} catch (Throwable $e) {
			$output->warning('Shillinq: moving customer VAT numbers to vatId failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: moving customer VAT numbers to vatId failed', ['exception' => $e->getMessage()]);
		}//end try

	}//end run()
}//end class
