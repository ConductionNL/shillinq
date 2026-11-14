<?php

/**
 * Align Purchase Order Status Code
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PurchaseOrderService once wrote the order's state to `lifecycleState`, a
 * field the schema does not declare, and left the declared lifecycle field
 * `statusCode` behind. An order marked sent that way still reads approved.
 * This step gives `statusCode` the value sent where `lifecycleState` says
 * sent and `statusCode` is still approved (REQ-PODR-003, design Migration
 * Plan). Any other order is left alone, so a rerun saves nothing.
 *
 * Runs post-migration, after InitializeSettings. Best-effort: a failure warns
 * and never blocks the upgrade. Reads and writes are unscoped, because the
 * repair context has no user.
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
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves orders sent under the old field onto the declared lifecycle field.
 *
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-1.1
 */
class AlignPurchaseOrderStatusCode implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * The schema repaired.
	 *
	 * @var string
	 */
	private const SCHEMA = 'PurchaseOrder';

	/**
	 * Constructor.
	 *
	 * @param RegisterLocation       $settingsService Names shillinq's register.
	 * @param LoggerInterface        $logger          Logger.
	 * @param ObjectServiceInterface $objectService   OpenRegister's object service.
	 */
	public function __construct(
		private readonly RegisterLocation $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-1.1
	 */
	public function getName(): string {
		return 'Shillinq: give purchase orders sent under the old state field the declared state sent';
	}//end getName()

	/**
	 * Copy lifecycleState sent into statusCode where statusCode is still approved.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-1.1
	 */
	public function run(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();
			$rows = $this->readAllRows(
				objectService: $this->objectService,
				registerSlug: $registerSlug,
				schema: self::SCHEMA,
				filters: ['statusCode' => 'approved']
			);

			$aligned = 0;
			foreach ($rows as $row) {
				$order = $this->rowPayload(row: $row);
				$uuid = ObjectIdentifier::resolve(saved: $row);
				if ($uuid === '' || ($order['lifecycleState'] ?? null) !== 'sent' || ($order['statusCode'] ?? null) !== 'approved') {
					continue;
				}

				$data = [];
				foreach ($order as $key => $value) {
					if ($key !== 'id' && str_starts_with((string)$key, '@') === false) {
						$data[$key] = $value;
					}
				}

				$data['statusCode'] = 'sent';
				$this->objectService->saveObject(
					object: $data,
					register: $registerSlug,
					schema: self::SCHEMA,
					uuid: $uuid,
					_rbac: false,
					_multitenancy: false,
				);
				$aligned++;
			}//end foreach

			$output->info(sprintf('Shillinq: %d purchase order(s) moved to the declared state sent.', $aligned));
		} catch (Throwable $e) {
			$output->warning('Shillinq: aligning the purchase order state failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: aligning the purchase order state failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end run()
}//end class
