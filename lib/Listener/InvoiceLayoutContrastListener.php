<?php

/**
 * InvoiceLayoutContrastListener — refuse an invoice layout whose primary
 * colour is too light to read on a white page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Invoice layouts are written straight into OpenRegister from the invoice
 * layout pages, so OpenRegister's pre-save veto is the one place the rule can
 * run. A stopped `ObjectCreatingEvent` or `ObjectUpdatingEvent` becomes HTTP
 * 422 with the message below, as FeeScheduleValidationListener does.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Service\Sales\InvoiceDocument\ColourContrast;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Write-path veto of an invoice layout primary colour below 4.5:1 on white.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-4.1
 */
class InvoiceLayoutContrastListener implements IEventListener {

	/**
	 * The schema this listener is scoped to.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'InvoiceLayout';

	/**
	 * Constructor.
	 *
	 * @param ColourContrast                  $contrast       The contrast formula.
	 * @param LoggerInterface                 $logger         Logger for refusals.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema to its slug.
	 */
	public function __construct(
		private readonly ColourContrast $contrast,
		private readonly LoggerInterface $logger,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Veto an invoice layout whose primary colour does not reach 4.5:1
	 * against white. A layout without a primary colour keeps the schema's
	 * default, which passes.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-4.1
	 */
	public function handle(Event $event): void {
		$colour = $this->incomingPrimaryColour(event: $event);
		if ($colour === null || $this->contrast->passesOnWhite(hex: $colour) === true) {
			return;
		}

		$this->logger->warning(
			'Shillinq: refused an InvoiceLayout write with a low-contrast primary colour',
			['primaryColor' => $colour]
		);

		$event->setErrors(
			[
				'message'     => $this->refusal(colour: $colour),
				'requirement' => 'REQ-SID-001',
			]
		);
		$event->stopPropagation();

	}//end handle()

	/**
	 * The primary colour an invoice layout write carries, or null when the
	 * write is of another schema or sets no primary colour.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return string|null
	 */
	private function incomingPrimaryColour(Event $event): string|null {
		$write  = new PreSaveWrite();
		$entity = $write->incomingEntity(event: $event);
		if ($entity === null) {
			return null;
		}

		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_SLUG) === false) {
			return null;
		}

		$primary = (string) ($write->payload(entity: $entity)['primaryColor'] ?? '');
		if ($primary === '') {
			return null;
		}

		return $primary;

	}//end incomingPrimaryColour()

	/**
	 * The sentence that refuses the colour, with its ratio rounded down so a
	 * colour just under the line never reads 4.5:1.
	 *
	 * @param string $colour The refused colour.
	 *
	 * @return string
	 */
	private function refusal(string $colour): string {
		$ratio = $this->contrast->ratioAgainstWhite(hex: $colour);
		if ($ratio === null) {
			return 'The primary colour '.$colour.' is not a #RRGGBB colour.';
		}

		return sprintf(
			'The primary colour %s has a contrast of %.1f:1 against white; choose a darker colour that reaches at least 4.5:1.',
			$colour,
			(floor($ratio * 10) / 10)
		);

	}//end refusal()
}//end class
