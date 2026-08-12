<?php

/**
 * Fixed-Asset Disposal Listener
 *
 * Change revive-gl-tax-capabilities (shillinq#417 / #446): the missing
 * disposal-journal trigger. {@see \OCA\Shillinq\Service\DisposalJournalEmitter}
 * fully implements the closing `GLTransaction` payload for a retired
 * `FixedAsset` (REQ-FA-006 / REQ-FA-008) and passes its unit tests, but
 * nothing ever called it: an asset could be retired while its gross value
 * and its accumulated depreciation both stayed on the balance sheet and the
 * boekwinst/boekverlies never reached the P&L.
 *
 * This listener reacts to OpenRegister's `ObjectTransitionedEvent` on the
 * `FixedAsset` `active -> retired` transition and hands the record to
 * {@see \OCA\Shillinq\Service\FixedAssetDisposalService}, which normalises
 * it, resolves the depreciation actually posted, calls the emitter and
 * persists the balanced journal.
 *
 * Note the transition itself had to be repaired first: the `FixedAsset`
 * `x-openregister-lifecycle` declared no `field`, no `states` and no
 * `from`/`to` on `dispose`, so OpenRegister's `TransitionEngine` rejected
 * every attempt and no `ObjectTransitionedEvent` was ever dispatched for a
 * `FixedAsset` (design D1).
 *
 * Fail-soft, mirroring {@see GRIRClearingListener} and
 * {@see DeliveryDispatchListener}: a downstream failure is logged but never
 * bubbled into the triggering transition — a GL persistence hiccup must not
 * roll back the operator's disposal.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Service\FixedAssetDisposalService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatcher from the FixedAsset `retired` transition to the disposal posting.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 */
class FixedAssetDisposalListener implements IEventListener {

	/**
	 * The lifecycle state a disposed asset lands in (the schema's `status` enum).
	 *
	 * @var string
	 */
	private const STATE_RETIRED = 'retired';

	/**
	 * Construct the listener with DI dependencies.
	 *
	 * @param FixedAssetDisposalService $disposalService The disposal posting orchestrator.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly FixedAssetDisposalService $disposalService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event Event from OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false) {
			return;
		}

		try {
			$schema = strtolower(trim((string)$event->getSchema()));
			if ($schema !== 'fixedasset' && $schema !== 'fixed-asset' && $schema !== 'fixed_asset') {
				return;
			}

			if ((string)$event->getTo() !== self::STATE_RETIRED) {
				return;
			}

			$entity = $event->getObject();
			$object = $entity->getObject();
			if (is_array($object) === false) {
				return;
			}

			$administrationId = trim((string)($object['administrationId'] ?? ''));
			if ($administrationId === '') {
				$this->logger->warning(
					'FixedAssetDisposalListener: retired FixedAsset carries no administrationId; disposal journal skipped',
					['assetNumber' => ($object['assetNumber'] ?? null)]
				);
				return;
			}

			$this->disposalService->postDisposalJournal(
				administrationId: $administrationId,
				asset: $object
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'FixedAssetDisposalListener: failed to post the fixed-asset disposal journal',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()
}//end class
