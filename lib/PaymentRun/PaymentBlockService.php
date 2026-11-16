<?php

/**
 * Payment Block Service
 *
 * Sets and releases the payment block of a supplier invoice (APTransaction)
 * or a supplier (Payee). Who may do it is who may write the record under
 * OpenRegister's own access rules (a bookkeeper, REQ-BPR-003/004), within the
 * record's administration, always with a reason. The actor and time of both
 * are stored on the record.
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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Db\DoesNotExistException;

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
		'paymentBlockReleaseReason',
		'paymentReleasedBy',
		'paymentReleasedAt',
	];

	/**
	 * Construct the service.
	 *
	 * @param ObjectServiceInterface       $objectService  OpenRegister objects.
	 * @param AdministrationContextService $administrations The caller's administrations.
	 * @param PaymentActionAuthorizer      $authorizer     Names the calling user.
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

		$reason = trim($reason);
		if ($reason === '') {
			return ['status' => 400, 'error' => 'reason-required'];
		}

		try {
			$entity = ObjectIdentifier::findScoped(
				scoped: $this->objectService->setRegister(self::REGISTER)->setSchema($schema),
				id: $id
			);
		} catch (DoesNotExistException $miss) {
			$entity = null;
		}

		if (is_object($entity) === false) {
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

		$stamp = $this->releaseFields(actor: $actor, reason: $reason);
		if ($blocked === true) {
			$stamp = $this->blockFields(actor: $actor, reason: $reason);
		}

		$record = array_merge($record, $stamp);

		$saved = $this->scope->run(
			fn () => $this->objectService->saveObject(
				object: $record,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER, schema: $schema),
					uuid: ($entity->getUuid() ?? $id)
				),
				_rbac: true,
				_multitenancy: true
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
			'paymentBlockReleaseReason' => null,
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
			'paymentBlockReleaseReason' => $reason,
			'paymentReleasedBy' => $actor,
			'paymentReleasedAt' => gmdate('Y-m-d\TH:i:s\Z'),
		];

	}//end releaseFields()
}//end class
