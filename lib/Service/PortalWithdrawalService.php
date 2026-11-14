<?php

/**
 * Portal Withdrawal Service
 *
 * The withdrawal function forwarded by portaliq (sales-cancellation
 * REQ-SCX-004, design D3 and D4): a signed-in customer withdraws from their
 * own appointment, a guest from the one appointment their signed link names.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\Shillinq\Portal\PortalSubjectResolver;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks who may withdraw from which appointment, then withdraws.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
 */
class PortalWithdrawalService {

	/**
	 * The appointment is withdrawn.
	 *
	 * @var string
	 */
	public const WITHDRAWN = 'withdrawn';

	/**
	 * The appointment may no longer be withdrawn; the result names why.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused';

	/**
	 * Not this subject's appointment, or a link that opens nothing.
	 *
	 * @var string
	 */
	public const FORBIDDEN = 'forbidden';

	/**
	 * OpenRegister or the refund failed.
	 *
	 * @var string
	 */
	public const DOWNSTREAM_ERROR = 'downstream_error';

	/**
	 * OpenRegister's object service.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The register.
	 *
	 * @var string
	 */
	private const REGISTER = 'shillinq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface      $container   The container, for OpenRegister's services.
	 * @param WithdrawalService       $withdrawals Applies the withdrawal rule and withdraws.
	 * @param WithdrawalLinkSigner    $links       Verifies a guest's signed link.
	 * @param ContactCustomerResolver $customers   Resolves an appointment's contact to its CustomerMaster.
	 * @param LoggerInterface         $logger      The logger.
	 * @param PortalSubjectResolver   $subjects    Resolves the subject to its CustomerMaster.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly WithdrawalService $withdrawals,
		private readonly WithdrawalLinkSigner $links,
		private readonly ContactCustomerResolver $customers,
		private readonly LoggerInterface $logger,
		private readonly PortalSubjectResolver $subjects = new PortalSubjectResolver(),
	) {
	}//end __construct()

	/**
	 * Withdraw a signed-in customer from their own appointment.
	 *
	 * @param array<string, mixed> $claims        The verified portal subject claims.
	 * @param string               $appointmentId The appointment's id.
	 * @param DateTimeImmutable    $now           The moment of the withdrawal.
	 *
	 * @return array{status: string, code: string|null, reason: string|null} The outcome.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
	 */
	public function withdrawForSubject(array $claims, string $appointmentId, DateTimeImmutable $now): array {
		$appointmentId = trim($appointmentId);
		if ((string)($claims['audience'] ?? '') !== 'customer' || $this->subjects->isOpaqueId(target: $appointmentId) === false) {
			return self::outcome(status: self::FORBIDDEN);
		}

		try {
			$objects = $this->container->get(self::OBJECT_SERVICE);
			$customerMasterId = $this->subjects->customerMasterId(objectService: $objects, subjectRef: (string)($claims['sub'] ?? ''), audience: 'customer');
			if ($customerMasterId === null) {
				return self::outcome(status: self::FORBIDDEN);
			}

			$appointment = $this->read(objects: $objects, schema: 'Appointment', id: $appointmentId);
			$customer = $this->read(objects: $objects, schema: 'CustomerMaster', id: $customerMasterId);
			if ($appointment === null || $customer === null
				|| $this->bookedBy(appointment: $appointment, customer: $customer, customerMasterId: $customerMasterId) === false
			) {
				return self::outcome(status: self::FORBIDDEN);
			}
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: checking a portal withdrawal failed', ['exception' => $e->getMessage()]);
			return self::outcome(status: self::DOWNSTREAM_ERROR);
		}//end try

		return $this->withdraw(appointmentId: $appointmentId, channel: 'portal', now: $now);
	}//end withdrawForSubject()

	/**
	 * Withdraw a guest from the one appointment their signed link names.
	 *
	 * @param string            $token The token from the link.
	 * @param DateTimeImmutable $now   The moment of the withdrawal.
	 *
	 * @return array{status: string, code: string|null, reason: string|null} The outcome.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
	 */
	public function withdrawWithLink(string $token, DateTimeImmutable $now): array {
		$appointmentId = $this->links->verify(token: $token, now: $now);
		if ($appointmentId === null) {
			return self::outcome(status: self::FORBIDDEN);
		}

		return $this->withdraw(appointmentId: $appointmentId, channel: 'guest-link', now: $now);
	}//end withdrawWithLink()

	/**
	 * Withdraw, and say how it went.
	 *
	 * @param string            $appointmentId The appointment's id.
	 * @param string            $channel       `portal` or `guest-link`.
	 * @param DateTimeImmutable $now           The moment of the withdrawal.
	 *
	 * @return array{status: string, code: string|null, reason: string|null}
	 */
	private function withdraw(string $appointmentId, string $channel, DateTimeImmutable $now): array {
		try {
			$result = $this->withdrawals->withdraw(appointmentId: $appointmentId, channel: $channel, now: $now);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: a portal withdrawal failed', ['exception' => $e->getMessage()]);
			return self::outcome(status: self::DOWNSTREAM_ERROR);
		}

		if ($result['appointment'] === null) {
			// No such appointment: say no more than for another's.
			return self::outcome(status: self::FORBIDDEN);
		}

		if ($result['withdrawn'] === true) {
			return self::outcome(status: self::WITHDRAWN);
		}

		return self::outcome(status: self::REFUSED, code: $result['code'], reason: $result['reason']);
	}//end withdraw()

	/**
	 * Whether the customer booked the appointment: its contact resolves to
	 * the customer, or it was booked with the customer's e-mail address.
	 *
	 * @param array<string, mixed> $appointment      The appointment.
	 * @param array<string, mixed> $customer         The subject's CustomerMaster.
	 * @param string               $customerMasterId Its id.
	 *
	 * @return bool
	 */
	private function bookedBy(array $appointment, array $customer, string $customerMasterId): bool {
		$customer['id'] = $customerMasterId;
		$email = strtolower(trim((string)($customer['email'] ?? '')));
		if ($email !== '' && strtolower(trim((string)($appointment['customerEmail'] ?? ''))) === $email) {
			return true;
		}

		return $this->customers->resolve(reference: (string)($appointment['customerId'] ?? ''), customers: [$customer]) === $customerMasterId;
	}//end bookedBy()

	/**
	 * One object as an array with its id, or null when it is missing.
	 *
	 * @param object $objects OpenRegister's object service.
	 * @param string $schema  The schema.
	 * @param string $id      The id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(object $objects, string $schema, string $id): ?array {
		try {
			$found = ObjectIdentifier::findScoped(
				scoped: $objects->setRegister(self::REGISTER)->setSchema($schema),
				id: $id,
				named: ['_rbac' => false, '_multitenancy' => false]
			);
		} catch (Throwable $notFound) {
			return null;
		}

		return ObjectIdentifier::recordWithId(candidate: $found);
	}//end read()

	/**
	 * The outcome shape.
	 *
	 * @param string      $status The status.
	 * @param string|null $code   Why not, when refused.
	 * @param string|null $reason The translated reason, when refused.
	 *
	 * @return array{status: string, code: string|null, reason: string|null}
	 */
	private static function outcome(string $status, ?string $code = null, ?string $reason = null): array {
		return ['status' => $status, 'code' => $code, 'reason' => $reason];
	}//end outcome()
}//end class
