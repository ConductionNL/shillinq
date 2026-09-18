<?php

/**
 * Payment Request Leaf Registration Listener
 *
 * Contributes `shillinq-payment-requests` to OpenRegister's leaf catalogue
 * when OpenRegister dispatches `RegisterLeafProvidersEvent`. ADR-066: the
 * consuming app places the leaf on its own object, so the id and the descriptor
 * are declared here once and the two halves (this provider and the JS
 * registration) carry the same id, which is what gate-24 pairs.
 *
 * Registered behind `class_exists()` at the listener level, because a shillinq
 * running without OpenRegister must boot, not fatal on a missing event class.
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
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003, REQ-SOPR-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\Shillinq\Integration\ContractLeafProvider;
use OCA\Shillinq\Integration\PaymentRequestLeafProvider;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers the payment-request leaves on OpenRegister's catalogue.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003, REQ-SOPR-004)
 */
final class PaymentRequestLeafRegistrationListener implements IEventListener {
	/**
	 * The render-surface half, which shows the requests and offers the two actions.
	 *
	 * @var string
	 */
	public const PANEL_ID = 'shillinq-payment-requests-panel';

	/**
	 * The render-surface half of the contract leaf.
	 *
	 * @var string
	 */
	public const CONTRACT_PANEL_ID = 'shillinq-contracts-panel';

	/**
	 * Constructor.
	 *
	 * @param PaymentRequestLeafProvider $provider The data-provider half.
	 * @param ContractLeafProvider $contracts The contract a case is handled under.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PaymentRequestLeafProvider $provider,
		private readonly ContractLeafProvider $contracts,
	) {
	}//end __construct()

	/**
	 * Contribute both halves of the leaf.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003, REQ-SOPR-004)
	 */
	public function handle(Event $event): void {
		if ($event instanceof RegisterLeafProvidersEvent === false) {
			return;
		}

		$event->registerLeaf(
			new LeafDescriptor(
				id: PaymentRequestLeafProvider::LEAF_ID,
				label: 'Payment requests',
				icon: 'CreditCardOutline',
				kinds: [LeafDescriptor::KIND_DATA_PROVIDER],
				requiredApp: 'shillinq',
				group: 'Finance',
				requiresPermission: PaymentRequestLeafProvider::ACTION_REQUEST,
			),
			$this->provider,
		);

		$event->registerLeaf(
			new LeafDescriptor(
				id: self::PANEL_ID,
				label: 'Payment requests',
				icon: 'CreditCardOutline',
				kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
				requiredApp: 'shillinq',
				group: 'Finance',
				surfaces: ['widget', 'tab'],
			),
			null,
		);

		// fees-payments-and-the-contract-register REQ-FPCR-006 — the case app
		// names the contract and reads its term, counterparty and remaining
		// value from here, so no case ever carries a stale copy of an agreement.
		$event->registerLeaf(
			new LeafDescriptor(
				id: ContractLeafProvider::LEAF_ID,
				label: 'Contract',
				icon: 'FileDocumentOutline',
				kinds: [LeafDescriptor::KIND_DATA_PROVIDER],
				requiredApp: 'shillinq',
				group: 'Finance',
			),
			$this->contracts,
		);

		$event->registerLeaf(
			new LeafDescriptor(
				id: self::CONTRACT_PANEL_ID,
				label: 'Contract',
				icon: 'FileDocumentOutline',
				kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
				requiredApp: 'shillinq',
				group: 'Finance',
				surfaces: ['widget', 'tab'],
			),
			null,
		);
	}//end handle()
}//end class
