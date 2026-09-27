<?php

/**
 * Contribution Debtor Resolver
 *
 * Turns the guardian a school names into the CustomerMaster an invoice is
 * billed to. The portal scopes a guardian's invoices by the `customerMasterId`
 * claim on their portal account, so a guardian billed against a customer their
 * account does not point at never sees the invoice. This class therefore finds
 * the customer the account already points at before it looks anywhere else, and
 * when it has to create or pick a customer it asks portaliq to link the account.
 *
 * Portaliq is optional. Its claim event is dispatched behind `class_exists()`,
 * with no import and no `info.xml` dependency (ADR-046): without portaliq the
 * guardian is still billed, and the payment link travels by mail.
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
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a guardian to a CustomerMaster and links their portal account.
 *
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-005)
 */
final class ContributionDebtorResolver {
	/**
	 * Portaliq's typed request to write an app's claim on a portal account.
	 *
	 * @var string
	 */
	public const CLAIM_EVENT = 'OCA\\Portaliq\\Event\\PortalAccountClaimRequestedEvent';

	/**
	 * The claim the portal scopes shillinq's invoices by.
	 *
	 * @var string
	 */
	public const CLAIM_NAME = 'customerMasterId';

	/**
	 * Portaliq's register, read cross-app and never written.
	 *
	 * @var string
	 */
	private const PORTAL_REGISTER = 'portaliq';

	/**
	 * Portaliq's account schema, which carries the server-managed claims.
	 *
	 * @var string
	 */
	private const PORTAL_ACCOUNT = 'portalAccount';

	/**
	 * The customer schema.
	 *
	 * @var string
	 */
	private const CUSTOMER = 'CustomerMaster';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IEventDispatcher $dispatcher Dispatches portaliq's claim event.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param LoggerInterface $logger Logs a claim that could not be written, never the email.
	 * @param string $claimEventClass The claim event class; tests pass a stand-in.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly string $claimEventClass = self::CLAIM_EVENT,
	) {
	}//end __construct()

	/**
	 * Resolve one debtor to a customer, creating one when nothing matches.
	 *
	 * Order: the given `customerMasterId`; the claim on the guardian's portal
	 * account; the customer with that email in the administration; a new one.
	 *
	 * @param array<string, mixed> $debtor The debtor as the caller named it.
	 * @param string $administrationId The school's administration.
	 *
	 * @return array{customerMasterId: string, portalLinked: bool, created: bool} The customer and whether the account points at it.
	 *
	 * @throws InvalidArgumentException When the debtor cannot be resolved or created.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-005)
	 */
	public function resolve(array $debtor, string $administrationId): array {
		$subjectRef = trim((string)($debtor['portalSubjectRef'] ?? ''));
		$claimed = null;
		if ($subjectRef !== '') {
			$claimed = $this->claimOf(subjectRef: $subjectRef);
		}

		$created = false;
		$customerId = trim((string)($debtor['customerMasterId'] ?? ''));
		if ($customerId !== '') {
			$found = $this->customer(id: $customerId);
			if ($found === null) {
				throw new InvalidArgumentException(sprintf('No customer %s exists.', $customerId));
			}

			$customerId = $found;
		} else {
			if ($claimed !== null) {
				$customerId = ($this->customer(id: $claimed) ?? '');
			}

			if ($customerId === '') {
				[$customerId, $created] = $this->byEmailOrNew(debtor: $debtor, administrationId: $administrationId);
			}
		}//end if

		$linked = false;
		if ($subjectRef !== '') {
			$linked = ($claimed === $customerId || $this->requestClaim(subjectRef: $subjectRef, customerId: $customerId) === true);
		}

		return ['customerMasterId' => $customerId, 'portalLinked' => $linked, 'created' => $created];
	}//end resolve()

	/**
	 * The customer with the debtor's email, or a new one.
	 *
	 * @param array<string, mixed> $debtor The debtor.
	 * @param string $administrationId The school's administration.
	 *
	 * @return array{0: string, 1: bool} The customer id and whether it was created.
	 *
	 * @throws InvalidArgumentException When the email or, for a new customer, the name is missing.
	 */
	private function byEmailOrNew(array $debtor, string $administrationId): array {
		$email = strtolower(trim((string)($debtor['email'] ?? '')));
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new InvalidArgumentException('A debtor without a customer needs a valid email.');
		}

		$existing = $this->customerByEmail(email: $email, administrationId: $administrationId);
		if ($existing !== null) {
			return [$existing, false];
		}

		$name = trim((string)($debtor['name'] ?? ''));
		if ($name === '') {
			throw new InvalidArgumentException('A new debtor needs a name.');
		}

		return [$this->createCustomer(name: $name, email: $email, administrationId: $administrationId), true];
	}//end byEmailOrNew()

	/**
	 * The shillinq claim on a guardian's portal account, when it carries one.
	 *
	 * @param string $subjectRef The guardian's portal subject.
	 *
	 * @return string|null The claimed customer id, or null.
	 */
	private function claimOf(string $subjectRef): ?string {
		try {
			$rows = $this->objectService
				->setRegister(self::PORTAL_REGISTER)
				->setSchema(self::PORTAL_ACCOUNT)
				->findAll(['filters' => ['subjectRef' => $subjectRef], 'limit' => 5], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// Portaliq is not installed, or its register is not there.
			return null;
		}

		foreach ($rows as $row) {
			$account = ObjectIdentifier::recordWithId(candidate: $row);
			$value = ($account['claims']['shillinq'][self::CLAIM_NAME] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return null;
	}//end claimOf()

	/**
	 * A customer's uuid by uuid or by customer code, or null.
	 *
	 * @param string $id The uuid or the `customerId` code.
	 *
	 * @return string|null The uuid, or null when no such customer exists.
	 */
	private function customer(string $id): ?string {
		$scoped = $this->objectService->setRegister($this->registerSlug())->setSchema(self::CUSTOMER);

		try {
			$record = ObjectIdentifier::recordWithId(candidate: $scoped->find($id, _rbac: false, _multitenancy: false));
			if ($record !== null && (string)($record['id'] ?? '') !== '') {
				return (string)$record['id'];
			}
		} catch (Throwable $notAUuid) {
			// Fall through to the code lookup.
		}

		try {
			$rows = $scoped->findAll(['filters' => ['customerId' => $id], 'limit' => 1], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}

		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null && (string)($record['id'] ?? '') !== '') {
				return (string)$record['id'];
			}
		}

		return null;
	}//end customer()

	/**
	 * The first customer with this email in the administration.
	 *
	 * @param string $email The lowercased email.
	 * @param string $administrationId The administration.
	 *
	 * @return string|null The uuid, or null.
	 */
	private function customerByEmail(string $email, string $administrationId): ?string {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::CUSTOMER)
			->findAll(
				['filters' => ['email' => $email, 'administrationId' => $administrationId], 'limit' => 1],
				_rbac: false,
				_multitenancy: false,
			);

		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null && (string)($record['id'] ?? '') !== '') {
				return (string)$record['id'];
			}
		}

		return null;
	}//end customerByEmail()

	/**
	 * Create a customer for a guardian nobody has billed before.
	 *
	 * The code is derived from the email, so a second raise that races past the
	 * email lookup still lands on the same code rather than on a new one.
	 *
	 * @param string $name The guardian's name.
	 * @param string $email The lowercased email.
	 * @param string $administrationId The administration.
	 *
	 * @return string The new customer's uuid.
	 *
	 * @throws InvalidArgumentException When OpenRegister answers without an id.
	 */
	private function createCustomer(string $name, string $email, string $administrationId): string {
		$saved = $this->objectService->saveObject(
			object: [
				'customerId' => 'G-' . strtoupper(substr(sha1($email), 0, 10)),
				'legalName' => $name,
				'email' => $email,
				'administrationId' => $administrationId,
				'lifecycleState' => 'active',
			],
			register: $this->registerSlug(),
			schema: self::CUSTOMER,
			_rbac: false,
		);

		$uuid = ObjectIdentifier::resolve(saved: $saved);
		if ($uuid === '') {
			throw new InvalidArgumentException('The customer was saved but OpenRegister answered without an id.');
		}

		return $uuid;
	}//end createCustomer()

	/**
	 * Ask portaliq to point the guardian's account at the customer.
	 *
	 * @param string $subjectRef The guardian's portal subject.
	 * @param string $customerId The customer's uuid.
	 *
	 * @return bool True when portaliq answered that the claim landed.
	 */
	private function requestClaim(string $subjectRef, string $customerId): bool {
		$class = $this->claimEventClass;
		if (class_exists($class) === false) {
			return false;
		}

		try {
			$event = new $class('shillinq', $subjectRef, self::CLAIM_NAME, $customerId);
			if (($event instanceof Event) === false) {
				return false;
			}

			$this->dispatcher->dispatchTyped($event);

			return method_exists($event, 'getResult') === true && $event->getResult() === 'ok';
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq: the portal account claim for a contribution debtor could not be written', ['exception' => $e->getMessage()]);
			return false;
		}
	}//end requestClaim()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end registerSlug()
}//end class
