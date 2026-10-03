<?php

/**
 * Contribution Decline Service
 *
 * A parent may refuse a voluntary school contribution (Wet vrijwillige
 * ouderbijdrage). The one reminder tells them how: "I will not pay" in the
 * parent portal (decision D28). Portaliq forwards that choice here, and this
 * service closes the guardian's own open voluntary contribution: the invoice
 * becomes `declined` with the moment stamped, and its pending payment requests
 * are voided so the pay link stops working. A declined invoice is never
 * reminded (DunningRunService) and is not overdue.
 *
 * The ownership chain is the pay receiver's (PortalSubjectResolver): the owner
 * comes from the subject's own portal account, never from the body, and the
 * target is an opaque id. A foreign, compulsory, closed or missing invoice all
 * get the same forbidden answer, so the endpoint is no existence oracle. A
 * second decline of an invoice this guardian already declined answers the same
 * success and writes nothing. Portal subjects are not Nextcloud users, so the
 * reads and writes bypass NC RBAC; the ownership check is the boundary
 * (ADR-005).
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
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\Shillinq\Portal\PortalSubjectResolver;
use OCA\Shillinq\Service\Dunning\VoluntaryContributionPolicy;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Closes a guardian's own open voluntary contribution on their refusal.
 *
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
 */
class ContributionDeclineService {
	/**
	 * The contribution is declined (or already was, by this guardian).
	 *
	 * @var string
	 */
	public const DECLINED = 'declined';

	/**
	 * Anything that is not an open voluntary contribution the guardian owns.
	 *
	 * @var string
	 */
	public const FORBIDDEN = 'forbidden';

	/**
	 * OpenRegister failed.
	 *
	 * @var string
	 */
	public const DOWNSTREAM_ERROR = 'downstream_error';

	/**
	 * OpenRegister's object service, fetched lazily.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The shillinq register.
	 *
	 * @var string
	 */
	private const REGISTER = 'shillinq';

	/**
	 * The audiences a debtor signs in with: a guardian, or a customer.
	 *
	 * @var array<int, string>
	 */
	private const DECLINING_AUDIENCES = ['parent', 'customer'];

	/**
	 * The states a contribution can still be declined from.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_STATES = ['issued', 'overdue'];

	/**
	 * Construct the service.
	 *
	 * @param ContainerInterface $container Container; OpenRegister's ObjectService is fetched lazily.
	 * @param LoggerInterface $logger Logger (never receives personal data).
	 * @param PortalSubjectResolver $subjects The ownership chain shared with the pay receiver.
	 * @param VoluntaryContributionPolicy $policy Decides what a voluntary contribution is.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PortalSubjectResolver $subjects = new PortalSubjectResolver(),
		private readonly VoluntaryContributionPolicy $policy = new VoluntaryContributionPolicy(),
	) {
	}//end __construct()

	/**
	 * Decline the guardian's own open voluntary contribution.
	 *
	 * @param array<string, mixed> $claims The VERIFIED assertion claims.
	 * @param string $target The client-supplied opaque invoice id.
	 * @param DateTimeImmutable|null $now The moment to stamp; defaults to now.
	 *
	 * @return string One of DECLINED, FORBIDDEN, DOWNSTREAM_ERROR.
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function decline(array $claims, string $target, ?DateTimeImmutable $now = null): string {
		$target = trim($target);
		$audience = (string)($claims['audience'] ?? '');
		if ($this->subjects->isOpaqueId(target: $target) === false || in_array($audience, self::DECLINING_AUDIENCES, true) === false) {
			return self::FORBIDDEN;
		}

		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			$customerMasterId = $this->subjects->customerMasterId(
				objectService: $objectService,
				subjectRef: (string)($claims['sub'] ?? ''),
				audience: $audience,
			);
			if ($customerMasterId === null) {
				return self::FORBIDDEN;
			}

			$invoice = $this->findOwnedVoluntaryInvoice(objectService: $objectService, target: $target, customerMasterId: $customerMasterId);
			if ($invoice === null) {
				return self::FORBIDDEN;
			}

			// A double click, or a second tab: the refusal already stands.
			if ($this->policy->isDeclined(invoice: $invoice) === true) {
				return self::DECLINED;
			}

			if (in_array((string)($invoice['lifecycleState'] ?? ''), self::OPEN_STATES, true) === false) {
				return self::FORBIDDEN;
			}

			$this->closeInvoice(objectService: $objectService, invoice: $invoice, now: ($now ?? new DateTimeImmutable()));
			$this->voidPendingRequests(objectService: $objectService, invoiceId: (string)$invoice['id']);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: declining a voluntary contribution failed', ['exception' => $e->getMessage()]);
			return self::DOWNSTREAM_ERROR;
		}//end try

		return self::DECLINED;
	}//end decline()

	/**
	 * The target invoice when it exists, belongs to this customer and is a
	 * voluntary contribution; null otherwise, whatever the reason.
	 *
	 * Looked up with `find()` by uuid: a `findAll()` filter on `id` addresses
	 * a JSON property and matches nothing (see ObjectIdentifier::findOne).
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $target The opaque invoice id.
	 * @param string $customerMasterId The verified owner.
	 *
	 * @return array<string, mixed>|null The invoice with its uuid as `id`.
	 */
	private function findOwnedVoluntaryInvoice(object $objectService, string $target, string $customerMasterId): ?array {
		try {
			$found = $objectService->find(
				id: $target,
				register: self::REGISTER,
				schema: 'ARInvoice',
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $notFound) {
			return null;
		}

		$invoice = ObjectIdentifier::recordWithId(candidate: $found);
		if ($invoice === null) {
			return null;
		}

		if ((string)($invoice['id'] ?? '') === '') {
			$invoice['id'] = $target;
		}

		if ((string)($invoice['customerId'] ?? '') !== $customerMasterId || $this->policy->isVoluntary(invoice: $invoice) === false) {
			return null;
		}

		return $invoice;
	}//end findOwnedVoluntaryInvoice()

	/**
	 * Set the invoice to declined and stamp the moment on its contribution.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $invoice The owned voluntary invoice.
	 * @param DateTimeImmutable $now The moment to stamp.
	 *
	 * @return void
	 */
	private function closeInvoice(object $objectService, array $invoice, DateTimeImmutable $now): void {
		$data = $this->payload(row: $invoice);
		$data['lifecycleState'] = VoluntaryContributionPolicy::DECLINED_STATE;
		// The policy only admitted an invoice whose contribution is an array.
		$contribution = (array)($data['contribution'] ?? []);
		$contribution['declinedAt'] = $now->format(DATE_ATOM);
		$data['contribution'] = $contribution;

		$objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: 'ARInvoice',
			uuid: (string)$invoice['id'],
			_rbac: false,
			_multitenancy: false,
		);
	}//end closeInvoice()

	/**
	 * Void every pending payment request on the invoice, so its pay link stops
	 * resolving to a payable session.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $invoiceId The invoice uuid.
	 *
	 * @return void
	 */
	private function voidPendingRequests(object $objectService, string $invoiceId): void {
		$pending = $objectService
			->setRegister(self::REGISTER)
			->setSchema('PaymentRequest')
			->findAll(
				config: [
					'filters' => [
						'invoiceReference' => $invoiceId,
						'state' => 'pending',
					],
				],
				_rbac: false,
				_multitenancy: false,
			);

		foreach ((array)$pending as $row) {
			$request = ObjectIdentifier::recordWithId(candidate: $row);
			if ($request === null || (string)($request['id'] ?? '') === '') {
				continue;
			}

			$uuid = (string)$request['id'];

			$data = $this->payload(row: $request);
			$data['state'] = 'voided';
			$objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: 'PaymentRequest',
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
			);
		}
	}//end voidPendingRequests()

	/**
	 * A row without its id and OpenRegister metadata, ready to save back.
	 *
	 * @param array<string, mixed> $row The row as read.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function payload(array $row): array {
		$data = [];
		foreach ($row as $key => $value) {
			if ($key === 'id' || str_starts_with((string)$key, '@') === true) {
				continue;
			}

			$data[$key] = $value;
		}

		return $data;
	}//end payload()
}//end class
