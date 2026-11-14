<?php

/**
 * Object Request Settled Listener
 *
 * Mails the debtor one receipt when a payment request on an object is first
 * settled, whichever path settled it: a provider capture, money recorded by
 * hand, or later a bank match (REQ-ORS-005, design D5). It acts when
 * `settledAt` goes from empty to set, and records `receiptSentAt` so the
 * update that stamp causes, or a replayed event, never mails twice.
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
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectRequestReceiptMailerInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the receipt mail once per settled object request.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
 */
class ObjectRequestSettledListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ObjectRequestReceiptMailerInterface $receiptMailer Sends the mail.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema slug.
	 * @param ObjectServiceInterface $objectService Writes receiptSentAt back.
	 * @param RegisterLocationInterface $settings Where the register lives (slug + OpenRegister availability).
	 * @param LoggerInterface $logger Fail-soft log.
	 */
	public function __construct(
		private readonly ObjectRequestReceiptMailerInterface $receiptMailer,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocationInterface $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mail the receipt when this update is the one that settled the request.
	 *
	 * @param Event $event The object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$entity = $event->getNewObject();
		if ($this->schemaResolver->schemaSlug(entity: $entity) !== 'PaymentRequest') {
			return;
		}

		$new = ($entity->getObject() ?? []);
		$old = $this->oldData(event: $event);
		if ($this->settledWithoutReceipt(old: $old, new: $new) === false) {
			return;
		}

		try {
			$this->sendReceipt(entity: $entity, new: $new);
		} catch (Throwable $e) {
			$this->logger->warning('ObjectRequestSettledListener: receiptSentAt not recorded', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * The request as it was before the update.
	 *
	 * @param ObjectUpdatedEvent $event The update.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
	 */
	private function oldData(ObjectUpdatedEvent $event): array {
		return ($event->getOldObject()?->getObject() ?? []);
	}//end oldData()

	/**
	 * Whether this update settled the request and no receipt was sent yet.
	 *
	 * @param array<string,mixed> $old The request before the update.
	 * @param array<string,mixed> $new The request after the update.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
	 */
	private function settledWithoutReceipt(array $old, array $new): bool {
		$settledNow = (string)($old['settledAt'] ?? '') === '' && (string)($new['settledAt'] ?? '') !== '';
		return !($settledNow === false || (string)($new['receiptSentAt'] ?? '') !== '');
	}//end settledWithoutReceipt()

	/**
	 * Mail the receipt and record when it was sent.
	 *
	 * @param mixed               $entity The updated object.
	 * @param array<string,mixed> $new    The request after the update.
	 *
	 * @return void
	 *
	 * @throws Throwable When the mail or the patch fails.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
	 */
	private function sendReceipt(mixed $entity, array $new): void {
		$id = (string)($new['id'] ?? ($entity->getUuid() ?? ''));
		$new['id'] = $id;
		if ($id === '' || $this->receiptMailer->send(request: $new) === false) {
			return;
		}

		$this->objectService->patchObject(
			objectId: $id,
			data: ['receiptSentAt' => gmdate('Y-m-d\TH:i:s\Z')],
			register: $this->settings->getRegisterSlug(),
			schema: 'PaymentRequest',
			_rbac: false,
			_multitenancy: false,
		);
	}//end sendReceipt()
}//end class
