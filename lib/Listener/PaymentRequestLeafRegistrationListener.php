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
use OCA\OpenRegister\Service\Integration\LeafAvailability;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\OpenRegister\Service\Integration\LeafIdentity;
use OCA\OpenRegister\Service\Integration\LeafRendering;
use OCA\Shillinq\Kernel\Contracts\ContractLeafProviderInterface;
use OCA\Shillinq\Kernel\Contracts\FinancialDocumentLeafProviderInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRequestLeafProviderInterface;
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
	 * Constructor.
	 *
	 * @param PaymentRequestLeafProviderInterface $provider The data-provider half.
	 * @param ContractLeafProviderInterface $contracts The contract a case is handled under.
	 * @param FinancialDocumentLeafProviderInterface $documents Every financial document linked to an object.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PaymentRequestLeafProviderInterface $provider,
		private readonly ContractLeafProviderInterface $contracts,
		private readonly FinancialDocumentLeafProviderInterface $documents,
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

		$this->registerPaymentRequestLeaves(event: $event);
		$this->registerContractAndDocumentProviders(event: $event);
		$this->registerDocumentAndContractPanels(event: $event);
	}//end handle()

	/**
	 * Register the payment-request data provider and its panel.
	 *
	 * @param RegisterLeafProvidersEvent $event The registration event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003, REQ-SOPR-004)
	 */
	private function registerPaymentRequestLeaves(RegisterLeafProvidersEvent $event): void {
		$event->registerLeaf(
			new LeafDescriptor(
				identity: $this->financeIdentity(id: PaymentRequestLeafProviderInterface::LEAF_ID, label: 'Payment requests', icon: 'CreditCardOutline'),
				kinds: [LeafDescriptor::KIND_DATA_PROVIDER],
				availability: new LeafAvailability(
					requiredApp: 'shillinq',
					requiresPermission: PaymentRequestLeafProviderInterface::ACTION_REQUEST,
				),
			),
			$this->provider,
		);

		$event->registerLeaf($this->panelDescriptor(id: self::PANEL_ID, label: 'Payment requests', icon: 'CreditCardOutline'), null);
	}//end registerPaymentRequestLeaves()

	/**
	 * Register the contract and financial-documents data providers.
	 *
	 * Spec fees-payments-and-the-contract-register REQ-FPCR-006 — the case app
	 * names the contract and reads its term, counterparty and remaining
	 * value from here, so no case ever carries a stale copy of an agreement.
	 * Spec financial-documents-on-any-object REQ-FDO-002: every quote,
	 * invoice, credit note, payment request and payout linked to the object.
	 *
	 * @param RegisterLeafProvidersEvent $event The registration event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003, REQ-SOPR-004)
	 */
	private function registerContractAndDocumentProviders(RegisterLeafProvidersEvent $event): void {
		$event->registerLeaf(
			$this->dataDescriptor(id: ContractLeafProviderInterface::LEAF_ID, label: 'Contract', icon: 'FileDocumentOutline'),
			$this->contracts,
		);

		$event->registerLeaf(
			$this->dataDescriptor(id: FinancialDocumentLeafProviderInterface::LEAF_ID, label: 'Financial documents', icon: 'FileDocumentMultipleOutline'),
			$this->documents,
		);
	}//end registerContractAndDocumentProviders()

	/**
	 * Register the financial-documents and contract panels.
	 *
	 * @param RegisterLeafProvidersEvent $event The registration event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003, REQ-SOPR-004)
	 */
	private function registerDocumentAndContractPanels(RegisterLeafProvidersEvent $event): void {
		$event->registerLeaf(
			$this->panelDescriptor(id: self::DOCUMENTS_PANEL_ID, label: 'Financial documents', icon: 'FileDocumentMultipleOutline'),
			null,
		);

		$event->registerLeaf($this->panelDescriptor(id: self::CONTRACT_PANEL_ID, label: 'Contract', icon: 'FileDocumentOutline'), null);
	}//end registerDocumentAndContractPanels()

	/**
	 * A leaf identity in the Finance group.
	 *
	 * @param string $id The leaf id.
	 * @param string $label The leaf label.
	 * @param string $icon The leaf icon.
	 *
	 * @return LeafIdentity
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003, REQ-SOPR-004)
	 */
	private function financeIdentity(string $id, string $label, string $icon): LeafIdentity {
		return new LeafIdentity(
			id: $id,
			label: $label,
			icon: $icon,
			group: 'Finance',
		);
	}//end financeIdentity()

	/**
	 * A data-provider leaf that needs only shillinq.
	 *
	 * @param string $id The leaf id.
	 * @param string $label The leaf label.
	 * @param string $icon The leaf icon.
	 *
	 * @return LeafDescriptor
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003, REQ-SOPR-004)
	 */
	private function dataDescriptor(string $id, string $label, string $icon): LeafDescriptor {
		return new LeafDescriptor(
			identity: $this->financeIdentity(id: $id, label: $label, icon: $icon),
			kinds: [LeafDescriptor::KIND_DATA_PROVIDER],
			availability: new LeafAvailability(requiredApp: 'shillinq'),
		);
	}//end dataDescriptor()

	/**
	 * A render-surface panel leaf mounted on the panel surfaces.
	 *
	 * @param string $id The leaf id.
	 * @param string $label The leaf label.
	 * @param string $icon The leaf icon.
	 *
	 * @return LeafDescriptor
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003, REQ-SOPR-004)
	 */
	private function panelDescriptor(string $id, string $label, string $icon): LeafDescriptor {
		return new LeafDescriptor(
			identity: $this->financeIdentity(id: $id, label: $label, icon: $icon),
			kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
			availability: new LeafAvailability(requiredApp: 'shillinq'),
			rendering: new LeafRendering(
				surfaces: self::PANEL_SURFACES,
				renderMode: LeafDescriptor::RENDER_MODE_MOUNT,
			),
		);
	}//end panelDescriptor()
}//end class
