<?php

/**
 * Payment Block Service
 *
 * Sets and releases the payment block of a supplier invoice (APTransaction)
 * or a supplier (Payee). Both need the `payment.block` payment action, access
 * to the record's administration and a reason, and the person who set a block
 * cannot release it. The actor and time of both are stored on the record.
 * PaymentBlockLockListener refuses any other write of these fields.
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PaymentActionAuthorizer;

/**
 * Blocks and releases payment of one invoice or supplier.
 *
 * @spec openspec/specs/payment-control-guards/spec.md
 */
class PaymentBlockService {

	private const REGISTER = 'shillinq';

	/**
	 * The schemas a payment block can be set on.
	 */
	public const SCHEMAS = ['APTransaction', 'Payee'];

	/**
	 * The fields only this service writes.
	 */
	public const FIELDS = [
		'paymentBlocked',
		'paymentBlockReason',
		'paymentBlockedBy',
		'paymentBlockedAt',
		'paymentReleaseReason',
		'paymentReleasedBy',
		'paymentReleasedAt',
	];

	/**
	 * Construct the service.
	 *
	 * @param ObjectServiceInterface       $objectService  OpenRegister objects.
	 * @param AdministrationContextService $administrations The caller's administrations.
	 * @param PaymentActionAuthorizer      $authorizer     The payment action matrix.
	 * @param PaymentBlockWriteScope       $scope          Opens the block fields to this service's write.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AdministrationContextService $administrations,
		private readonly PaymentActionAuthorizer $authorizer,
		private readonly PaymentBlockWriteScope $scope,
	) {
	}//end __construct()

	/**
	 * Block or release payment of one record.
	 *
	 * @param string $schema  APTransaction or Payee.
	 * @param string $id      The record's id.
	 * @param bool   $blocked True to block, false to release.
	 * @param string $reason  Why; required both ways.
	 *
	 * @return array{status: int, object?: array<string,mixed>, error?: string}
	 *
	 * @spec openspec/specs/payment-control-guards/spec.md
	 */
	public function setBlock(string $schema, string $id, bool $blocked, string $reason): array {
		if (in_array($schema, self::SCHEMAS, true) === false || trim($id) === '') {
			return ['status' => 404, 'error' => 'not-found'];
		}

		$actor = $this->authorizer->callerId();
		if ($actor === '') {
			return ['status' => 401, 'error' => 'not-logged-in'];
		}

		if ($this->authorizer->may(PaymentActionAuthorizer::ACTION_BLOCK) === false) {
			return ['status' => 403, 'error' => 'not-allowed'];
		}

		$reason = trim($reason);
		if ($reason === '') {
			return ['status' => 400, 'error' => 'reason-required'];
		}

		$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);
		if ($entity === null) {
			return ['status' => 404, 'error' => 'not-found'];
		}

		$record = $entity->getObject();
		if ($this->administrations->canAccess(administrationId: (string)($record['administrationId'] ?? '')) === false) {
			return ['status' => 404, 'error' => 'not-found'];
		}

		if (($record['paymentBlocked'] ?? false) === $blocked) {
			$error = 'not-blocked';
			if ($blocked === true) {
				$error = 'already-blocked';
			}

			return ['status' => 409, 'error' => $error];
		}

		if ($blocked === false && (string)($record['paymentBlockedBy'] ?? '') === $actor) {
			return ['status' => 403, 'error' => 'four-eyes'];
		}

		$stamp = $this->releaseFields(actor: $actor, reason: $reason);
		if ($blocked === true) {
			$stamp = $this->blockFields(actor: $actor, reason: $reason);
		}

		$record = array_merge($record, $stamp);

		$saved = $this->scope->run(
			fn () => $this->objectService->saveObject(
				object: $record,
				register: self::REGISTER,
				schema: $schema,
				uuid: ($entity->getUuid() ?? $id),
				_rbac: false,
				_multitenancy: false
			)
		);

		return ['status' => 200, 'object' => $saved->getObject()];

	}//end setBlock()

	/**
	 * The fields a block writes; it clears the last release.
	 *
	 * @param string $actor  Who blocks.
	 * @param string $reason Why.
	 *
	 * @return array<string,mixed>
	 */
	private function blockFields(string $actor, string $reason): array {
		return [
			'paymentBlocked' => true,
			'paymentBlockReason' => $reason,
			'paymentBlockedBy' => $actor,
			'paymentBlockedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'paymentReleaseReason' => null,
			'paymentReleasedBy' => null,
			'paymentReleasedAt' => null,
		];

	}//end blockFields()

	/**
	 * The fields a release writes; the block reason stays on the record.
	 *
	 * @param string $actor  Who releases.
	 * @param string $reason Why.
	 *
	 * @return array<string,mixed>
	 */
	private function releaseFields(string $actor, string $reason): array {
		return [
			'paymentBlocked' => false,
			'paymentReleaseReason' => $reason,
			'paymentReleasedBy' => $actor,
			'paymentReleasedAt' => gmdate('Y-m-d\TH:i:s\Z'),
		];

	}//end releaseFields()
}//end class
