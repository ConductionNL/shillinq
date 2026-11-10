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
use OCA\Shillinq\Integration\FinancialDocumentLeafProvider;
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
	 * The render-surface half of the financial documents leaf.
	 *
	 * @var string
	 */
	public const DOCUMENTS_PANEL_ID = 'shillinq-financial-documents-panel';

	/**
	 * Where both panels render, written out on this half and repeated verbatim
	 * on the JS half, because two explicit sets are what the parity check can
	 * compare.
	 *
	 * The values come from `LeafDescriptor::VALID_SURFACES`. They used to read
	 * `['widget', 'tab']`, which is not that vocabulary: nothing validates
	 * surfaces at registration, and `CnIntegrationWidgetGrid` filters with
	 * `surfaces.includes(surface)`, so those two words excluded the panels from
	 * every grid they were meant to appear in while reading like a declaration
	 * that they appeared in two.
	 *
	 * @var array<int, string>
	 */
	public const PANEL_SURFACES = ['detail-page', 'single-entity'];

	/**
	 * Label and icon of the payment-request leaves.
	 *
	 * @var array{label: string, icon: string}
	 */
	private const PAYMENT_REQUESTS_LOOK = ['label' => 'Payment requests', 'icon' => 'CreditCardOutline'];

	/**
	 * Label and icon of the contract leaves.
	 *
	 * @var array{label: string, icon: string}
	 */
	private const CONTRACT_LOOK = ['label' => 'Contract', 'icon' => 'FileDocumentOutline'];

	/**
	 * Label and icon of the financial-document leaves.
	 *
	 * @var array{label: string, icon: string}
	 */
	private const DOCUMENTS_LOOK = ['label' => 'Financial documents', 'icon' => 'FileDocumentMultipleOutline'];

	/**
	 * Constructor.
	 *
	 * @param PaymentRequestLeafProvider $provider The data-provider half.
	 * @param ContractLeafProvider $contracts The contract a case is handled under.
	 * @param FinancialDocumentLeafProvider $documents Every financial document linked to an object.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PaymentRequestLeafProvider $provider,
		private readonly ContractLeafProvider $contracts,
		private readonly FinancialDocumentLeafProvider $documents,
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

		foreach ($this->leaves() as [$descriptor, $provider]) {
			$event->registerLeaf($descriptor, $provider);
		}
	}//end handle()

	/**
	 * Every leaf this app contributes, in registration order, with the
	 * provider that answers it (null for a render surface).
	 *
	 * @return list<array{0: LeafDescriptor, 1: object|null}> The leaves.
	 */
	private function leaves(): array {
		return [
			[
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
			],
			[$this->panelLeaf(id: self::PANEL_ID, look: self::PAYMENT_REQUESTS_LOOK), null],
			// Spec fees-payments-and-the-contract-register REQ-FPCR-006 — the case app
			// names the contract and reads its term, counterparty and remaining
			// value from here, so no case ever carries a stale copy of an agreement.
			[$this->dataLeaf(id: ContractLeafProvider::LEAF_ID, look: self::CONTRACT_LOOK), $this->contracts],
			// Spec financial-documents-on-any-object REQ-FDO-002: every quote,
			// invoice, credit note, payment request and payout linked to the object.
			[$this->dataLeaf(id: FinancialDocumentLeafProvider::LEAF_ID, look: self::DOCUMENTS_LOOK), $this->documents],
			[$this->panelLeaf(id: self::DOCUMENTS_PANEL_ID, look: self::DOCUMENTS_LOOK), null],
			[$this->panelLeaf(id: self::CONTRACT_PANEL_ID, look: self::CONTRACT_LOOK), null],
		];
	}//end leaves()

	/**
	 * A Finance data-provider leaf without a permission of its own.
	 *
	 * @param string                             $id   The leaf id.
	 * @param array{label: string, icon: string} $look The label and icon.
	 *
	 * @return LeafDescriptor The descriptor.
	 */
	private function dataLeaf(string $id, array $look): LeafDescriptor {
		return new LeafDescriptor(
			id: $id,
			label: $look['label'],
			icon: $look['icon'],
			kinds: [LeafDescriptor::KIND_DATA_PROVIDER],
			requiredApp: 'shillinq',
			group: 'Finance',
		);
	}//end dataLeaf()

	/**
	 * A Finance render-surface panel mounted on the detail and single-entity
	 * surfaces.
	 *
	 * @param string                             $id   The panel id.
	 * @param array{label: string, icon: string} $look The label and icon.
	 *
	 * @return LeafDescriptor The descriptor.
	 */
	private function panelLeaf(string $id, array $look): LeafDescriptor {
		return new LeafDescriptor(
			id: $id,
			label: $look['label'],
			icon: $look['icon'],
			kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
			requiredApp: 'shillinq',
			group: 'Finance',
			surfaces: self::PANEL_SURFACES,
			renderMode: LeafDescriptor::RENDER_MODE_MOUNT,
		);
	}//end panelLeaf()
}//end class
