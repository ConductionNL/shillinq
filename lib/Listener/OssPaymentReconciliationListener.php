<?php

/**
 * OSS Payment Reconciliation Listener
 *
 * Change revive-gl-tax-capabilities (shillinq#446): the missing trigger for
 * {@see \OCA\Shillinq\Service\OssPaymentReconciliation::reconcileDistribution()}
 * (REQ-OSS-008). The method compares the per-country distribution the
 * Belastingdienst confirms back through the OSS portal against the
 * per-country totals declared on the OssReturn — the only control that
 * catches a consolidated EU-VAT payment being distributed differently from
 * what was declared. It had zero callers, so a discrepancy was never
 * detected and every OssPayment stayed `pending` forever.
 *
 * The trigger is `OssPayment` creation: the payment record is the carrier of
 * the Belastingdienst's confirmed `perCountryDistribution`. On create, this
 * listener reconciles it against the linked OssReturn and drives the record
 * to `reconciled` (distribution matches to the cent) or `discrepancy`
 * (it does not), persisting the per-country differences on the record. Both
 * are declared transitions out of `pending`, so the write is a legal
 * lifecycle move, not a state-machine bypass.
 *
 * Fail-soft: a downstream failure is logged, never bubbled into the create.
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

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\OssPaymentReconciliation;
use OCA\Shillinq\Service\OssRecordResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reconciles a newly recorded OssPayment against its OssReturn.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 */
class OssPaymentReconciliationListener implements IEventListener {

	/**
	 * Reconciliation outcome: the distribution matches the declared return.
	 *
	 * @var string
	 */
	private const STATE_RECONCILED = 'reconciled';

	/**
	 * Reconciliation outcome: the distribution diverges.
	 *
	 * @var string
	 */
	private const STATE_DISCREPANCY = 'discrepancy';

	/**
	 * Construct the listener with DI dependencies.
	 *
	 * @param OssRecordResolver $resolver Reads/persists the OSS records.
	 * @param OssPaymentReconciliation $reconciliation The pure-logic REQ-OSS-008 kernel.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly OssRecordResolver $resolver,
		private readonly OssPaymentReconciliation $reconciliation,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an ObjectCreatedEvent.
	 *
	 * @param Event $event Event from OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		try {
			$entity = $event->getObject();
			if ($entity === null) {
				return;
			}

			$schema = strtolower(trim($this->schemaResolver->schemaSlug(entity: $entity)));
			if ($schema !== 'osspayment' && $schema !== 'oss-payment') {
				return;
			}

			$payment = $entity->getObject();
			if (is_array($payment) === false) {
				return;
			}

			$ossReturn = $this->resolver->findReturnForPayment(ossPayment: $payment);
			if ($ossReturn === null) {
				$this->logger->warning(
					'OssPaymentReconciliationListener: OssPayment has no resolvable OssReturn; reconciliation skipped',
					['ossReturnId' => ($payment['ossReturnId'] ?? null)]
				);
				return;
			}

			$confirmation = ($payment['perCountryDistribution'] ?? []);
			if (is_array($confirmation) === false || $confirmation === []) {
				// Nothing confirmed yet: the payment stays `pending` until the
				// Belastingdienst returns its distribution. Not an error.
				return;
			}

			$result = $this->reconciliation->reconcileDistribution(
				ossReturn: $ossReturn,
				confirmation: $confirmation
			);

			$status = self::STATE_DISCREPANCY;
			if (($result['status'] ?? '') === 'reconciled') {
				$status = self::STATE_RECONCILED;
			}

			$data = $payment;
			$data['id'] = $this->paymentId(payment: $payment);
			$data['reconciliationStatus'] = $status;
			$data['distributionDifferences'] = ($result['differences'] ?? []);
			unset($data['@self']);

			$this->resolver->savePayment(data: $data);

			if ($status === self::STATE_DISCREPANCY) {
				$this->logger->warning(
					'OssPaymentReconciliationListener: confirmed OSS distribution diverges from the declared return',
					[
						'ossReturnId' => ($payment['ossReturnId'] ?? null),
						'differences' => ($result['differences'] ?? []),
					]
				);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'OssPaymentReconciliationListener: failed to reconcile the OSS payment',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Resolve the payment's OpenRegister id.
	 *
	 * @param array<string,mixed> $payment The payment record.
	 *
	 * @return string The id, '' when the record is not persisted.
	 */
	private function paymentId(array $payment): string {
		$id = trim((string)($payment['id'] ?? ''));
		if ($id !== '') {
			return $id;
		}

		$self = ($payment['@self'] ?? []);
		if (is_array($self) === true) {
			return trim((string)($self['id'] ?? ''));
		}

		return '';
	}//end paymentId()
}//end class
