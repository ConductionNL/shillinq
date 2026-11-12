<?php

/**
 * Fixed-Asset Disposal Listener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
	 * The schema slugs a FixedAsset transition may carry (lower-cased).
	 *
	 * @var array<int,string>
	 */
	private const FIXED_ASSET_SCHEMAS = ['fixedasset', 'fixed-asset', 'fixed_asset'];

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
			$asset = $this->retiredAsset(event: $event);
			if ($asset !== null) {
				$this->postDisposal(asset: $asset);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'FixedAssetDisposalListener: failed to post the fixed-asset disposal journal',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * The FixedAsset this transition retired, or null for any other transition.
	 *
	 * @param ObjectTransitionedEvent $event The transition event.
	 *
	 * @return array<string,mixed>|null The asset.
	 */
	private function retiredAsset(ObjectTransitionedEvent $event): array|null {
		$schema = strtolower(trim((string)$event->getSchema()));
		if (in_array($schema, self::FIXED_ASSET_SCHEMAS, true) === false || (string)$event->getTo() !== self::STATE_RETIRED) {
			return null;
		}

		$object = $event->getObject()->getObject();
		if (is_array($object) === false) {
			return null;
		}

		return $object;

	}//end retiredAsset()

	/**
	 * Post the retired asset's disposal journal; an asset without administration is skipped.
	 *
	 * @param array<string,mixed> $asset The retired FixedAsset.
	 *
	 * @return void
	 */
	private function postDisposal(array $asset): void {
		$administrationId = trim((string)($asset['administrationId'] ?? ''));
		if ($administrationId === '') {
			$this->logger->warning(
				'FixedAssetDisposalListener: retired FixedAsset carries no administrationId; disposal journal skipped',
				['assetNumber' => ($asset['assetNumber'] ?? null)]
			);
			return;
		}

		$this->disposalService->postDisposalJournal(
			administrationId: $administrationId,
			asset: $asset
		);

	}//end postDisposal()
}//end class
