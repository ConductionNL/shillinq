<?php

/**
 * Actuarial Valuation Approved Listener
 *
 * When an ActuarialValuation takes its `approve` transition, posts its IAS 19
 * journal entry through PensionValuationPostingService (REQ-PEN-011). The
 * posting is fail-soft: the approval stands and the reason is logged when
 * nothing could be booked.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-pension-ias19/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Service\PensionValuationPostingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Books an actuarial valuation when it is approved.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-pension-ias19/spec.md
 */
class ActuarialValuationApprovedListener implements IEventListener {

	/**
	 * @param PensionValuationPostingService  $posting        Posts the entry.
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells this app's ActuarialValuation from other objects.
	 * @param LoggerInterface                 $logger         Logs a valuation that could not be booked.
	 */
	public function __construct(
		private readonly PensionValuationPostingService $posting,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Post the entry of a valuation that moved to approved.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-pension-ias19/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false || $event->getTo() !== 'approved') {
			return;
		}

		$object = $event->getObject();
		if ($this->schemaResolver->matchesSchema(entity: $object, expectedSlug: 'ActuarialValuation') === false) {
			return;
		}

		try {
			$valuation = (array)$object->getObject();
			$valuation['id'] = (string)($valuation['id'] ?? $object->getUuid());
			$valuation['approvalStatus'] = 'approved';
			$result = $this->posting->postForValuation(valuation: $valuation);
		} catch (Throwable $e) {
			$this->logger->warning('ActuarialValuationApprovedListener: posting failed', ['exception' => $e->getMessage()]);
			return;
		}

		if ($result['posted'] === false) {
			$this->logger->info('ActuarialValuationApprovedListener: nothing booked', ['valuation' => $valuation['id'], 'reason' => $result['message']]);
		}
	}//end handle()
}//end class
