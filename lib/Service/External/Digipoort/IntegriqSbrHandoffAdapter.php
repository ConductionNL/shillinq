<?php

/**
 * Shillinq integriq SBR hand-off adapter
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Digipoort
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Digipoort;

use InvalidArgumentException;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * The Digipoort port bound when integriq is installed: hands the filing to
 * integriq's Digipoort connector (source slug `digipoort-sbr`) by emitting
 * `nl.conduction.sbr.filing.requested`, and contacts nothing itself.
 *
 * The answer is always pending: the filing counts as submitted only when
 * integriq reports the delivery (`nl.conduction.sbr.filing.status`, read by
 * SbrFilingStatusListener). A dispatch that fails is thrown to the caller,
 * so nothing is marked handed off that integriq never heard of. The event
 * names are the contract proposed to integriq in tax-digipoort-filing; they
 * live in the two constants here and on the listener.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md#requirement-submission-hands-the-instance-to-integriq-and-follows-its-reports-req-tdf-004
 */
class IntegriqSbrHandoffAdapter implements DigipoortSbrAdapterInterface {

	/**
	 * The event integriq's Digipoort connector acts on.
	 */
	public const EVENT_FILING_REQUESTED = 'nl.conduction.sbr.filing.requested';

	/**
	 * The integriq source slug the filing goes through.
	 */
	public const SOURCE_SLUG = 'digipoort-sbr';

	/**
	 * The delivery status of a filing handed to integriq.
	 */
	public const PENDING = 'PENDING';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Carries the event to integriq.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.1
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
	) {
	}//end __construct()

	/**
	 * Emit the filing request for integriq.
	 *
	 * @param array<string,mixed> $payload {objectUri, filingType, recipient, payloadFileUri, administrationId?, correlationId?}.
	 *
	 * @return DigipoortSubmissionResult Pending, with the correlation id as reference.
	 *
	 * @throws InvalidArgumentException When the object, filing type, recipient or file is missing.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.1
	 */
	public function submit(array $payload): DigipoortSubmissionResult {
		$event = [
			'sourceApp'      => 'shillinq',
			'objectType'     => 'xbrl-instance',
			'objectUri'      => trim((string) ($payload['objectUri'] ?? '')),
			'filingType'     => trim((string) ($payload['filingType'] ?? '')),
			'recipient'      => trim((string) ($payload['recipient'] ?? '')),
			'payloadFileUri' => trim((string) ($payload['payloadFileUri'] ?? '')),
		];
		foreach ($event as $key => $value) {
			if ($value === '') {
				throw new InvalidArgumentException(sprintf('The SBR filing cannot be handed off without %s.', $key));
			}
		}

		$event['administrationId'] = trim((string) ($payload['administrationId'] ?? ''));
		$event['sourceSlug']       = self::SOURCE_SLUG;

		$this->eventDispatcher->dispatch(self::EVENT_FILING_REQUESTED, new GenericEvent(null, $event));

		$reference = trim((string) ($payload['correlationId'] ?? ''));
		if ($reference === '') {
			$reference = $event['objectUri'];
		}

		return new DigipoortSubmissionResult(
			deliveryStatus: self::PENDING,
			reference: $reference,
			dormant: false,
			extras: ['event' => self::EVENT_FILING_REQUESTED, 'sourceSlug' => self::SOURCE_SLUG]
		);
	}//end submit()

	/**
	 * Never dormant: the filing does leave shillinq, to integriq.
	 *
	 * @return bool False.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.1
	 */
	public function isDormant(): bool {
		return false;
	}//end isDormant()
}//end class
