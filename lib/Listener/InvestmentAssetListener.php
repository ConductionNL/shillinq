<?php

/**
 * Shillinq Investment Asset Listener
 *
 * Records a fixed asset's investment deduction eligibility when it is
 * created or updated as active.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
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
 *
 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-an-activated-fixed-asset-gets-its-deduction-eligibility-req-idc-001
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Service\InvestmentDeduction\InvestmentDeductionService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An active FixedAsset gets its InvestmentAsset created or brought up to date.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-an-activated-fixed-asset-gets-its-deduction-eligibility-req-idc-001
 */
class InvestmentAssetListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param InvestmentDeductionService $deductions     The eligibility record.
	 * @param ListenerSchemaResolver     $schemaResolver Which schema an event's object is.
	 * @param LoggerInterface            $logger         Where a failure is logged.
	 */
	public function __construct(
		private readonly InvestmentDeductionService $deductions,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the eligibility of an active fixed asset.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-an-activated-fixed-asset-gets-its-deduction-eligibility-req-idc-001
	 */
	public function handle(Event $event): void {
		$entity = null;
		if ($event instanceof ObjectCreatedEvent) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatedEvent) {
			$entity = $event->getNewObject();
		}

		if ($entity === null || $this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: 'FixedAsset') === false) {
			return;
		}

		$fixedAsset = $entity->getObject();
		if (is_array($fixedAsset) === false || InvestmentDeductionService::isActive(fixedAsset: $fixedAsset) === false) {
			return;
		}

		try {
			$this->deductions->recordEligibility(fixedAssetId: (string)($entity->getUuid() ?? ''), fixedAsset: $fixedAsset);
		} catch (Throwable $e) {
			$this->logger->error('InvestmentAssetListener: failed to record investment deduction eligibility', ['exception' => $e->getMessage()]);
		}
	}//end handle()
}//end class
