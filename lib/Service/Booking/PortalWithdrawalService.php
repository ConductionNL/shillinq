<?php

/**
 * Portal Withdrawal Service
 *
 * Answers the portal's `withdraw` forward (sales-cancellation REQ-SCX-004):
 * either a signed-in customer withdrawing one of their own appointments, or a
 * guest holding the signed link of one appointment. Once the caller is known
 * to be entitled to that appointment, WithdrawalService decides whether it is
 * still withdrawable and carries the withdrawal out.
 *
 * A signed-in customer owns an appointment when its `customerId` is their
 * CustomerMaster, or when its `customerEmail` is that CustomerMaster's e-mail
 * (a widget booking names the booker by e-mail, not by customer record).
 * Every way of not being entitled answers the same FORBIDDEN, so the
 * endpoint is no oracle for which appointments exist.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Booking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Booking;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Portal\PortalSubjectResolver;
use OCA\Shillinq\Service\WithdrawalService;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Entitlement check and hand-off for the portal's withdraw action.
 *
 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-2.2
 */
class PortalWithdrawalService {

	/**
	 * Resolves the portal subject to their CustomerMaster.
	 *
	 * @var PortalSubjectResolver
	 */
	private readonly PortalSubjectResolver $subjects;

	/**
	 * The appointment is withdrawn.
	 */
	public const WITHDRAWN = 'withdrawn';

	/**
	 * The caller may withdraw it, but it is no longer withdrawable; the reason says why.
	 */
	public const REFUSED = 'refused';

	/**
	 * The caller is not entitled to this appointment, or it does not exist.
	 */
	public const FORBIDDEN = 'forbidden';

	/**
	 * OpenRegister or the withdrawal failed.
	 */
	public const ERROR = 'error';

	/**
	 * @param WithdrawalService      $withdrawals   Decides and carries out the withdrawal.
	 * @param WithdrawalLinkService  $links         Verifies a guest's signed link.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param LoggerInterface        $logger        Diagnostics.
	 */
	public function __construct(
		private readonly WithdrawalService $withdrawals,
		private readonly WithdrawalLinkService $links,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
		$this->subjects = new PortalSubjectResolver();
	}//end __construct()

	/**
	 * Withdraw a signed-in customer from one of their own appointments.
	 *
	 * @param array<string, mixed> $claims        The verified portal subject claims.
	 * @param string               $appointmentId The appointment.
	 * @param DateTimeImmutable    $now           The moment of the request.
	 *
	 * @return array{status: string, reason?: string|null, acknowledgedAt?: string|null}
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-2.2
	 */
	public function withdrawForSubject(array $claims, string $appointmentId, DateTimeImmutable $now): array {
		$appointmentId = trim($appointmentId);
		if ((string)($claims['audience'] ?? '') !== 'customer' || $this->subjects->isOpaqueId(target: $appointmentId) === false) {
			return ['status' => self::FORBIDDEN];
		}

		try {
			$customerMasterId = $this->subjects->customerMasterId(
				objectService: $this->objectService,
				subjectRef: (string)($claims['sub'] ?? ''),
				audience: 'customer',
			);
			$appointment = $this->read(schema: 'Appointment', id: $appointmentId);
			$customer = null;
			if ($customerMasterId !== null) {
				$customer = $this->read(schema: 'CustomerMaster', id: $customerMasterId);
			}
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: portal withdrawal lookup failed', ['exception' => $e->getMessage()]);
			return ['status' => self::ERROR];
		}

		$owned = ($customerMasterId !== null && $appointment !== null
			&& self::owns(customerMasterId: $customerMasterId, customer: $customer, appointment: $appointment) === true);
		if ($owned === false) {
			return ['status' => self::FORBIDDEN];
		}

		return $this->withdraw(appointmentId: $appointmentId, channel: 'portal', now: $now);
	}//end withdrawForSubject()

	/**
	 * Withdraw a guest from the appointment their signed link opens.
	 *
	 * @param string            $token The token of the link.
	 * @param DateTimeImmutable $now   The moment of the request.
	 *
	 * @return array{status: string, reason?: string|null, acknowledgedAt?: string|null}
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-2.2
	 */
	public function withdrawByLink(string $token, DateTimeImmutable $now): array {
		$appointmentId = $this->links->verify(token: $token, now: $now);
		if ($appointmentId === null) {
			return ['status' => self::FORBIDDEN];
		}

		return $this->withdraw(appointmentId: $appointmentId, channel: 'guest-link', now: $now);
	}//end withdrawByLink()

	/**
	 * Whether a customer owns an appointment: by customer record, else by e-mail.
	 *
	 * @param string                    $customerMasterId The subject's CustomerMaster id.
	 * @param array<string, mixed>|null $customer         That CustomerMaster, when it could be read.
	 * @param array<string, mixed>      $appointment      The appointment.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-cancellation/tasks.md#task-2.2
	 */
	public static function owns(string $customerMasterId, ?array $customer, array $appointment): bool {
		if ($customerMasterId !== '' && (string)($appointment['customerId'] ?? '') === $customerMasterId) {
			return true;
		}

		$email = strtolower(trim((string)($customer['email'] ?? '')));

		return $email !== '' && strtolower(trim((string)($appointment['customerEmail'] ?? ''))) === $email;
	}//end owns()

	/**
	 * Hand an entitled request to the withdrawal service.
	 *
	 * @param string            $appointmentId The appointment.
	 * @param string            $channel       `portal` or `guest-link`.
	 * @param DateTimeImmutable $now           The moment of the request.
	 *
	 * @return array{status: string, reason?: string|null, acknowledgedAt?: string|null}
	 */
	private function withdraw(string $appointmentId, string $channel, DateTimeImmutable $now): array {
		try {
			$result = $this->withdrawals->withdraw(appointmentId: $appointmentId, channel: $channel, now: $now);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: portal withdrawal failed', ['exception' => $e->getMessage()]);
			return ['status' => self::ERROR];
		}

		if ($result['appointment'] === null) {
			return ['status' => self::FORBIDDEN];
		}

		if ($result['withdrawn'] === false) {
			return ['status' => self::REFUSED, 'reason' => $result['reason']];
		}

		return ['status' => self::WITHDRAWN, 'acknowledgedAt' => ($result['withdrawal']['acknowledgedAt'] ?? null)];
	}//end withdraw()

	/**
	 * One object of a schema, null when absent.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		return ObjectIdentifier::findOneStrict(scoped: $this->objectService->setRegister('shillinq')->setSchema($schema), id: $id);
	}//end read()
}//end class
