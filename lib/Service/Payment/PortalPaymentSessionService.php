<?php

/**
 * Portal Payment Session Service (portal-payment-initiation).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The imperative half of the subject-initiated pay-now flow (design.md "The
 * initiation chain"). Given a VERIFIED assertion's claims and a client-chosen
 * opaque target id, this service:
 *
 *   1. resolves the subject's `customerMasterId` scope claim server-side
 *      (PortalSubjectResolver, shared with the decline receiver) by
 *      reading portaliq's OWN `portalAccount` register the same way
 *      portaliq's `PortalObjectReader::resolveClaim()` does (design.md Open
 *      Q1) — the frozen A6 assertion carries only `sub`/`audience`/
 *      `organisation`/`trust`/`jti`, never an app-specific scope claim, so
 *      the receiver must derive it itself, exactly as every scopeClaim
 *      collection read already requires portaliq-side;
 *   2. resolves the target `ARInvoice` — id/slug match AND
 *      `customerId === customerMasterId` AND a payable `state` — via
 *      OpenRegister, REQ-SPPI-003;
 *   3. mints or reuses a pending `PaymentRequest` for that invoice, with the
 *      amount/currency read from the SERVER invoice (REQ-SPPI-004);
 *   4. drives `PaymentProviderInterface::createSession()` with `method:
 *      'ideal'` and persists the returned `paymentIntentId`.
 *
 * FAIL-CLOSED CONVENTION (deliberate, apply-time decision): any OpenRegister
 * or PSP call that THROWS collapses to `downstream_error` (502, REQ-SPPI-002);
 * any call that SUCCEEDS but yields no usable claim/invoice collapses to the
 * SAME uniform `forbidden` (403) result whether the target is foreign-owned,
 * non-payable, non-existent, or malformed — no existence oracle
 * (REQ-SPPI-003). Portal reads/writes bypass NC per-user RBAC/multitenancy
 * (`_rbac: false, _multitenancy: false`) exactly like the fleet-reference A6
 * receiver (petstore's PortalActionController) — portal subjects are not
 * Nextcloud users; the ownership checks in this class ARE the security
 * boundary (ADR-005).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payment
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003, REQ-SPPI-004)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payment;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\PortalPaymentSessionServiceInterface;
use OCA\Shillinq\Portal\PortalSubjectResolver;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Resolves ownership + mints an iDEAL payment session for a verified portal
 * subject.
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003, REQ-SPPI-004)
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) -- one fail-closed guard per
 * step of the ownership chain (ADR-005); collapsing them would trade
 * auditability for a score.
 */
class PortalPaymentSessionService implements PortalPaymentSessionServiceInterface {
	/**
	 * OpenRegister's object service, resolved lazily by FQCN so shillinq
	 * keeps its existing zero-compile-time-coupling convention.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The shillinq register slug.
	 */
	private const REGISTER = 'shillinq';

	/**
	 * The AR invoice schema.
	 */
	private const SCHEMA_AR_INVOICE = 'ARInvoice';

	/**
	 * The payment-request schema.
	 */
	private const SCHEMA_PAYMENT_REQUEST = 'PaymentRequest';

	/**
	 * ARInvoice states a debtor may still pay against (design.md / REQ-SPPI-003).
	 *
	 * @var array<int, string>
	 */
	private const PAYABLE_STATES = ['issued', 'partially-paid', 'overdue'];

	/**
	 * The audiences this flow serves: customers, and parents paying a school
	 * contribution (REQ-SCON-010). Any other assertion is refused upstream by
	 * the controller, and the service re-checks defensively.
	 *
	 * @var array<int, string>
	 */
	private const PAYING_AUDIENCES = ['customer', 'parent'];

	/**
	 * The webhook route name (shillinq.paymentRequestWebhook.handle) — an
	 * absolute URL is built from it for the PSP's async callback.
	 */
	private const WEBHOOK_ROUTE = 'shillinq.paymentRequestWebhook.handle';

	/**
	 * The gateway slug this flow always mints for (Mollie iDEAL, REQ-SPPI-001).
	 */
	private const GATEWAY = 'mollie';

	/**
	 * App-config key for the portaliq-owned return URL (design.md Open Q3).
	 * Never sourced from the client body.
	 */
	private const CONFIG_REDIRECT_URL = 'portal_payment_redirect_url';

	/**
	 * Construct the service.
	 *
	 * @param ContainerInterface $container DI container — OpenRegister's ObjectService is
	 *                                      fetched lazily.
	 * @param PaymentProviderInterface $provider The bound payment-provider port.
	 * @param IURLGenerator $urlGenerator Builds the webhook + default redirect URL.
	 * @param IAppConfig $appConfig App config for the redirect-URL override.
	 * @param LoggerInterface $logger Logger (never receives PSP/PII detail).
	 * @param PortalSubjectResolver $subjects The ownership chain shared with the decline receiver.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PaymentProviderInterface $provider,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly PortalSubjectResolver $subjects = new PortalSubjectResolver(),
	) {
	}//end __construct()

	/**
	 * Initiate (or reuse) a payment session for the subject's own invoice.
	 *
	 * @param array<string, mixed> $claims The VERIFIED assertion claims (never trust unverified input).
	 * @param string $target The client-supplied opaque invoice id/slug.
	 *
	 * @return PortalPaymentSessionResult
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003, REQ-SPPI-004)
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-010)
	 */
	public function initiate(array $claims, string $target): PortalPaymentSessionResult {
		return $this->openSession(claims: $claims, target: new PortalPaymentTarget(id: $target, kind: PortalPaymentTarget::INVOICE));
	}//end initiate()

	/**
	 * Initiate a payment session for the subject's own payment request that
	 * stands without an invoice, such as leges on a case (REQ-SOPR-005). The
	 * request's own amount is charged; nothing is minted.
	 *
	 * @param array<string, mixed> $claims The VERIFIED assertion claims.
	 * @param string $target The client-supplied opaque payment request id.
	 *
	 * @return PortalPaymentSessionResult
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function initiateForRequest(array $claims, string $target): PortalPaymentSessionResult {
		return $this->openSession(claims: $claims, target: new PortalPaymentTarget(id: $target, kind: PortalPaymentTarget::REQUEST));
	}//end initiateForRequest()

	/**
	 * The chain both targets share: the target shape, the audience, the
	 * subject's customer, the target-specific preparation, the provider call
	 * and the saved intent id.
	 *
	 * @param array<string, mixed> $claims The VERIFIED assertion claims.
	 * @param PortalPaymentTarget $target The client-supplied target and its kind.
	 *
	 * @return PortalPaymentSessionResult
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003)
	 */
	private function openSession(array $claims, PortalPaymentTarget $target): PortalPaymentSessionResult {
		$targetId = trim($target->id);
		if ($this->subjects->isOpaqueId(target: $targetId) === false) {
			return PortalPaymentSessionResult::forbidden();
		}

		if (in_array((string)($claims['audience'] ?? ''), self::PAYING_AUDIENCES, true) === false) {
			return PortalPaymentSessionResult::forbidden();
		}

		$objectService = $this->objectServiceOrNull();
		if ($objectService === null) {
			return PortalPaymentSessionResult::downstreamError();
		}

		$lookup = new PaymentTargetLookup(objectService: $objectService, targetId: $targetId, kind: $target->kind);
		return $this->initiationChain(claims: $claims, lookup: $lookup);
	}//end openSession()

	/**
	 * OpenRegister's object service; null (logged) when it cannot be resolved.
	 *
	 * @return object|null
	 */
	private function objectServiceOrNull(): ?object {
		try {
			return $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			$this->logDownstreamFailure(step: 'object-service-unavailable', exception: $e);
			return null;
		}
	}//end objectServiceOrNull()

	/**
	 * Run the ownership chain and the provider call, and map the outcome to a result.
	 *
	 * @param array<string, mixed> $claims The VERIFIED assertion claims.
	 * @param PaymentTargetLookup $lookup The target lookup.
	 *
	 * @return PortalPaymentSessionResult
	 */
	private function initiationChain(array $claims, PaymentTargetLookup $lookup): PortalPaymentSessionResult {
		try {
			$session = $this->openProviderSession(claims: $claims, lookup: $lookup);
		} catch (Throwable $e) {
			$this->logDownstreamFailure(step: 'initiation-chain-failed', exception: $e);
			return PortalPaymentSessionResult::downstreamError();
		}

		if ($session === null) {
			return PortalPaymentSessionResult::forbidden();
		}

		if ($session->dormant === true) {
			return PortalPaymentSessionResult::deferred();
		}

		return PortalPaymentSessionResult::success(checkoutUrl: $session->checkoutUrl);
	}//end initiationChain()

	/**
	 * Prepare the subject's owned, payable target, open the provider session and save its intent id.
	 *
	 * @param array<string, mixed> $claims The VERIFIED assertion claims.
	 * @param PaymentTargetLookup $lookup The target lookup.
	 *
	 * @return PaymentSessionResult|null Null when the subject may not pay this target.
	 */
	private function openProviderSession(array $claims, PaymentTargetLookup $lookup): ?PaymentSessionResult {
		$prepared = $this->preparedFor(claims: $claims, lookup: $lookup);
		if ($prepared === null) {
			return null;
		}

		$session = $this->provider->createSession($prepared['session']);
		$this->persistPaymentIntentId(
			objectService: $lookup->objectService,
			paymentRequest: $prepared['request'],
			paymentIntentId: $session->paymentIntentId,
		);
		return $session;
	}//end openProviderSession()

	/**
	 * The subject's customer and, for it, the owned payable target.
	 *
	 * @param array<string, mixed> $claims The VERIFIED assertion claims.
	 * @param PaymentTargetLookup $lookup The target lookup.
	 *
	 * @return array{request: array<string, mixed>, session: PaymentSessionRequest}|null Null when not payable by this subject.
	 */
	private function preparedFor(array $claims, PaymentTargetLookup $lookup): ?array {
		$customerMasterId = $this->subjects->customerMasterId(
			objectService: $lookup->objectService,
			subjectRef: (string)($claims['sub'] ?? ''),
			audience: (string)($claims['audience'] ?? ''),
		);
		if ($customerMasterId === null) {
			return null;
		}

		return $this->prepareTarget(lookup: $lookup, customerMasterId: $customerMasterId);
	}//end preparedFor()

	/**
	 * The target-specific preparation: a payment request without an invoice, or an invoice.
	 *
	 * @param PaymentTargetLookup $lookup The target lookup.
	 * @param string $customerMasterId The verified owner.
	 *
	 * @return array{request: array<string, mixed>, session: PaymentSessionRequest}|null Null when not payable by this subject.
	 */
	private function prepareTarget(PaymentTargetLookup $lookup, string $customerMasterId): ?array {
		if ($lookup->kind === PortalPaymentTarget::REQUEST) {
			return $this->prepareRequest(lookup: $lookup, customerMasterId: $customerMasterId);
		}

		return $this->prepareInvoice(lookup: $lookup, customerMasterId: $customerMasterId);
	}//end prepareTarget()

	/**
	 * The invoice target: the owned payable invoice, its minted or reused
	 * request, and the session for the invoice amount.
	 *
	 * @param PaymentTargetLookup $lookup The target lookup (the opaque invoice id or slug).
	 * @param string $customerMasterId The verified owner.
	 *
	 * @return array{request: array<string, mixed>, session: PaymentSessionRequest}|null Null when not payable by this subject.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-003, REQ-SPPI-004)
	 */
	private function prepareInvoice(PaymentTargetLookup $lookup, string $customerMasterId): ?array {
		$invoice = $this->findOwnedPayableInvoice(lookup: $lookup, customerMasterId: $customerMasterId);
		if ($invoice === null) {
			return null;
		}

		$paymentRequest = $this->mintOrReusePaymentRequest(objectService: $lookup->objectService, invoice: $invoice);

		return [
			'request' => $paymentRequest,
			'session' => $this->buildSessionRequest(invoice: $invoice, paymentRequest: $paymentRequest),
		];
	}//end prepareInvoice()

	/**
	 * The request target: the subject's own pending request without an
	 * invoice, and the session for its own amount (REQ-SOPR-005).
	 *
	 * A foreign, invoice-backed, settled or missing request collapses to the
	 * same null (no existence oracle).
	 *
	 * @param PaymentTargetLookup $lookup The target lookup (the opaque payment request id).
	 * @param string $customerMasterId The verified owner.
	 *
	 * @return array{request: array<string, mixed>, session: PaymentSessionRequest}|null Null when not payable by this subject.
	 *
	 * @throws RuntimeException When the request carries no chargeable amount.
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	private function prepareRequest(PaymentTargetLookup $lookup, string $customerMasterId): ?array {
		$request = $this->loadPaymentRequest(lookup: $lookup);
		if ($request === null || $this->isPayableRequestOf(request: $request, customerMasterId: $customerMasterId) === false) {
			return null;
		}

		return [
			'request' => $request,
			'session' => $this->requestSession(request: $request),
		];
	}//end prepareRequest()

	/**
	 * Load the target payment request, with its id.
	 *
	 * @param PaymentTargetLookup $lookup The target lookup.
	 *
	 * @return array<string, mixed>|null Null when missing.
	 */
	private function loadPaymentRequest(PaymentTargetLookup $lookup): ?array {
		$request = ObjectIdentifier::recordWithId(candidate: $this->findPaymentRequest(lookup: $lookup));
		if ($request === null) {
			return null;
		}

		return $this->withTargetId(record: $request, targetId: $lookup->targetId);
	}//end loadPaymentRequest()

	/**
	 * Read the target payment request with `find()` by uuid: a `findAll()`
	 * filter on `id` addresses a JSON property and matches nothing.
	 *
	 * @param PaymentTargetLookup $lookup The target lookup.
	 *
	 * @return mixed The found object, or null when the read fails.
	 */
	private function findPaymentRequest(PaymentTargetLookup $lookup): mixed {
		try {
			return ObjectIdentifier::findScoped(
				scoped: $lookup->objectService->setRegister(self::REGISTER)->setSchema(self::SCHEMA_PAYMENT_REQUEST),
				id: $lookup->targetId,
				named: ['_rbac' => false, '_multitenancy' => false]
			);
		} catch (Throwable $notFound) {
			return null;
		}
	}//end findPaymentRequest()

	/**
	 * A record read by the target id, given that id when it carries none.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string $targetId The target id.
	 *
	 * @return array<string, mixed>
	 */
	private function withTargetId(array $record, string $targetId): array {
		if ((string)($record['id'] ?? '') === '') {
			$record['id'] = $targetId;
		}

		return $record;
	}//end withTargetId()

	/**
	 * Whether the request is the subject's own pending request without an invoice.
	 *
	 * @param array<string, mixed> $request The PaymentRequest.
	 * @param string $customerMasterId The verified owner.
	 *
	 * @return bool
	 */
	private function isPayableRequestOf(array $request, string $customerMasterId): bool {
		$owner = (string)($request['customerId'] ?? ($request['debtor']['customerMasterId'] ?? ''));
		if ($owner !== $customerMasterId
			|| (string)($request['invoiceReference'] ?? '') !== ''
			|| (string)($request['state'] ?? '') !== 'pending'
		) {
			return false;
		}

		return true;
	}//end isPayableRequestOf()

	/**
	 * The provider session for a request without an invoice: its own amount.
	 *
	 * @param array<string, mixed> $request The payable PaymentRequest.
	 *
	 * @return PaymentSessionRequest
	 *
	 * @throws RuntimeException When the request carries no chargeable amount.
	 */
	private function requestSession(array $request): PaymentSessionRequest {
		return new PaymentSessionRequest(
			amount: $this->chargeableAmount(request: $request),
			currency: (string)($request['currency'] ?? 'EUR'),
			description: $this->requestDescription(request: $request),
			redirectUrl: $this->resolveRedirectUrl(),
			webhookUrl: $this->urlGenerator->linkToRouteAbsolute(self::WEBHOOK_ROUTE, ['gateway' => self::GATEWAY]),
			method: 'ideal',
			metadata: $this->requestMetadata(request: $request),
		);
	}//end requestSession()

	/**
	 * The request's amount, refused when it is not a positive number.
	 *
	 * @param array<string, mixed> $request The PaymentRequest.
	 *
	 * @return float
	 *
	 * @throws RuntimeException When the request carries no chargeable amount.
	 */
	private function chargeableAmount(array $request): float {
		$amount = ($request['amount'] ?? null);
		if (is_bool($amount) === true || is_numeric($amount) === false || (float)$amount <= 0.0) {
			throw new RuntimeException('This payment request carries no amount that can be charged, so no payment session was opened.');
		}

		return (float)$amount;
	}//end chargeableAmount()

	/**
	 * The request's description, or "Payment request" when it has none.
	 *
	 * @param array<string, mixed> $request The PaymentRequest.
	 *
	 * @return string
	 */
	private function requestDescription(array $request): string {
		$description = trim((string)($request['description'] ?? ''));
		if ($description === '') {
			$description = 'Payment request';
		}

		return $description;
	}//end requestDescription()

	/**
	 * The session metadata of a request without an invoice.
	 *
	 * @param array<string, mixed> $request The PaymentRequest, with its id.
	 *
	 * @return array<string, string>
	 */
	private function requestMetadata(array $request): array {
		return [
			'paymentRequestId' => (string)$request['id'],
			'administrationId' => (string)($request['administrationId'] ?? ''),
			'correlationId' => (string)$request['id'],
		];
	}//end requestMetadata()

	/**
	 * Resolve the target ARInvoice: id/slug match AND owned by the verified
	 * customerMasterId AND in a payable state. A foreign owner, a non-payable
	 * state and a non-existent id all collapse to the SAME null (no existence
	 * oracle, REQ-SPPI-003).
	 *
	 * @param PaymentTargetLookup $lookup The target lookup (the client-supplied opaque id/slug).
	 * @param string $customerMasterId The verified owner (never from the request).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-003)
	 * @spec openspec/changes/billing-inherited-defects/specs/portal-payment-initiation/spec.md (REQ-SPPI-011)
	 */
	private function findOwnedPayableInvoice(PaymentTargetLookup $lookup, string $customerMasterId): ?array {
		$invoice = $this->invoiceById(lookup: $lookup) ?? $this->invoiceBySlug(lookup: $lookup, customerMasterId: $customerMasterId);
		if ($invoice === null) {
			return null;
		}

		$invoice = $this->withTargetId(record: $invoice, targetId: $lookup->targetId);
		if ($this->isPayableInvoiceOf(invoice: $invoice, customerMasterId: $customerMasterId) === false) {
			return null;
		}

		return $invoice;
	}//end findOwnedPayableInvoice()

	/**
	 * Read the target invoice by id with `find()`: a `findAll()` filter on
	 * `id` addresses a JSON property, matches nothing in OpenRegister, and made
	 * every invoice addressed by its uuid unpayable. A miss
	 * (DoesNotExistException) is null; any other read failure propagates as a
	 * downstream error.
	 *
	 * @param PaymentTargetLookup $lookup The target lookup.
	 *
	 * @return array<string, mixed>|null
	 */
	private function invoiceById(PaymentTargetLookup $lookup): ?array {
		try {
			return ObjectIdentifier::recordWithId(
				candidate: ObjectIdentifier::findScoped(
					scoped: $lookup->objectService->setRegister(self::REGISTER)->setSchema(self::SCHEMA_AR_INVOICE),
					id: $lookup->targetId,
					named: ['_rbac' => false, '_multitenancy' => false]
				)
			);
		} catch (DoesNotExistException $notFound) {
			return null;
		}
	}//end invoiceById()

	/**
	 * Fall through to the invoice's `slug` property, restricted to the verified owner.
	 *
	 * @param PaymentTargetLookup $lookup The target lookup.
	 * @param string $customerMasterId The verified owner.
	 *
	 * @return array<string, mixed>|null
	 */
	private function invoiceBySlug(PaymentTargetLookup $lookup, string $customerMasterId): ?array {
		$rows = $this->invoiceSlugRows(lookup: $lookup, customerMasterId: $customerMasterId);
		if (is_array($rows) === true && empty($rows) === false) {
			return ObjectIdentifier::recordWithId(candidate: $rows[0]);
		}

		return null;
	}//end invoiceBySlug()

	/**
	 * Query the owner's invoices by slug (at most one).
	 *
	 * @param PaymentTargetLookup $lookup The target lookup.
	 * @param string $customerMasterId The verified owner.
	 *
	 * @return mixed The ObjectService result.
	 */
	private function invoiceSlugRows(PaymentTargetLookup $lookup, string $customerMasterId): mixed {
		return $lookup->objectService
			->setRegister(self::REGISTER)
			->setSchema(self::SCHEMA_AR_INVOICE)
			->findAll(
				config: $this->invoiceSlugQuery(targetId: $lookup->targetId, customerMasterId: $customerMasterId),
				_rbac: false,
				_multitenancy: false,
			);
	}//end invoiceSlugRows()

	/**
	 * The slug query of an owner's invoice.
	 *
	 * @param string $targetId The opaque slug.
	 * @param string $customerMasterId The verified owner.
	 *
	 * @return array<string, mixed>
	 */
	private function invoiceSlugQuery(string $targetId, string $customerMasterId): array {
		return [
			'filters' => [
				'slug' => $targetId,
				'customerId' => $customerMasterId,
			],
			'limit' => 1,
		];
	}//end invoiceSlugQuery()

	/**
	 * Whether the invoice is owned by the verified customer and in a payable state.
	 *
	 * ARInvoice's lifecycle field is `lifecycleState`; `state` is not a
	 * property it declares, so reading only `state` found no invoice
	 * payable at all (REQ-SCON-010).
	 *
	 * @param array<string, mixed> $invoice The ARInvoice.
	 * @param string $customerMasterId The verified owner.
	 *
	 * @return bool
	 */
	private function isPayableInvoiceOf(array $invoice, string $customerMasterId): bool {
		if ((string)($invoice['customerId'] ?? '') !== $customerMasterId) {
			return false;
		}

		$state = (string)($invoice['lifecycleState'] ?? ($invoice['state'] ?? ''));
		return in_array($state, self::PAYABLE_STATES, true);
	}//end isPayableInvoiceOf()

	/**
	 * Mint a new PaymentRequest for the invoice, or reuse an existing pending
	 * one (REQ-SPPI-002 idempotent initiation). Amount/currency are read from
	 * the SERVER invoice, never from client input (REQ-SPPI-004).
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $invoice The owned, payable ARInvoice row.
	 *
	 * @return array<string, mixed> The (possibly newly persisted) PaymentRequest row.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-004)
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-010)
	 */
	private function mintOrReusePaymentRequest(object $objectService, array $invoice): array {
		$invoiceKey = (string)($invoice['id'] ?? '');

		$pending = $objectService
			->setRegister(self::REGISTER)
			->setSchema(self::SCHEMA_PAYMENT_REQUEST)
			->findAll(
				config: [
					'filters' => [
						'invoiceReference' => $invoiceKey,
						'state' => 'pending',
					],
					'limit' => 1,
				],
				_rbac: false,
				_multitenancy: false,
			);

		if (is_array($pending) === true && empty($pending) === false) {
			return $pending[0];
		}

		// 🔴 NEVER MINT A REQUEST FOR AN AMOUNT NOBODY COULD READ. The cast
		// below used to turn a missing or malformed `totalAmount` into 0.00,
		// and the citizen was sent to a checkout for nothing. A payment page
		// for zero euro is worse than a page that says the payment could not
		// be started, because the person believes they have paid.
		// ARInvoice declares `grossAmount`; `totalAmount` is kept first for the
		// rows that carry it (REQ-SCON-010).
		$amount = ($invoice['totalAmount'] ?? ($invoice['grossAmount'] ?? null));
		if (is_bool($amount) === true || is_numeric($amount) === false || (float)$amount <= 0.0) {
			throw new RuntimeException(
				'This invoice carries no amount that can be charged, so no payment session was opened.'
			);
		}

		$paymentRequest = [
			'invoiceReference' => $invoiceKey,
			'amount' => (float)$amount,
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
			'paymentGateway' => self::GATEWAY,
			'state' => 'pending',
			'administrationId' => (string)($invoice['administrationId'] ?? ''),
		];

		// A fresh request for a school contribution (the raised one failed or
		// expired) keeps the reference to the chargeable, or the settled signal
		// would no longer name the owning app (REQ-SCON-010).
		$contribution = ($invoice['contribution'] ?? null);
		if (is_array($contribution) === true && is_array($contribution['chargeable'] ?? null) === true) {
			$paymentRequest += [
				'subjectKind' => 'object',
				'subject' => $contribution['chargeable'],
				'beneficiary' => ($contribution['beneficiary'] ?? null),
				'requestType' => 'contribution',
				'voluntary' => (($contribution['voluntary'] ?? false) === true),
				'raiseBatchId' => (string)($contribution['raiseBatchId'] ?? ''),
				'debtor' => ['customerMasterId' => (string)($invoice['customerId'] ?? '')],
			];
		}

		$saved = $objectService->saveObject(
			object: $paymentRequest,
			register: self::REGISTER,
			schema: self::SCHEMA_PAYMENT_REQUEST,
			_rbac: false,
			_multitenancy: false,
		);

		return (array)$saved;
	}//end mintOrReusePaymentRequest()

	/**
	 * Build the provider-facing session request — amount/currency/description
	 * come from the SERVER invoice + PaymentRequest, never the client body
	 * (REQ-SPPI-004); the webhook URL is the existing shared, signature-gated
	 * endpoint (REQ-APL-004); the redirect URL is config-sourced (design.md
	 * Open Q3 — portaliq's return-URL contract is not part of the frozen
	 * assertion, so it is never accepted from the client).
	 *
	 * @param array<string, mixed> $invoice The owned, payable ARInvoice row.
	 * @param array<string, mixed> $paymentRequest The minted/reused PaymentRequest row.
	 *
	 * @return PaymentSessionRequest
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001, REQ-SPPI-004)
	 */
	private function buildSessionRequest(array $invoice, array $paymentRequest): PaymentSessionRequest {
		$invoiceKey = (string)($paymentRequest['invoiceReference'] ?? ($invoice['id'] ?? ''));
		$reference = (string)($invoice['invoiceNumber'] ?? $invoiceKey);

		return new PaymentSessionRequest(
			amount: (float)($paymentRequest['amount'] ?? ($invoice['totalAmount'] ?? 0.0)),
			currency: (string)($paymentRequest['currency'] ?? ($invoice['currency'] ?? 'EUR')),
			description: 'Invoice ' . $reference,
			redirectUrl: $this->resolveRedirectUrl(),
			webhookUrl: $this->urlGenerator->linkToRouteAbsolute(self::WEBHOOK_ROUTE, ['gateway' => self::GATEWAY]),
			method: 'ideal',
			metadata: [
				'invoiceId' => $invoiceKey,
				'administrationId' => (string)($invoice['administrationId'] ?? ''),
				'correlationId' => (string)($paymentRequest['id'] ?? ''),
			],
		);
	}//end buildSessionRequest()

	/**
	 * Resolve the payer return URL — a portaliq-owned config value if set,
	 * otherwise the instance root. NEVER sourced from the client body
	 * (design.md Open Q3).
	 *
	 * @return string
	 */
	private function resolveRedirectUrl(): string {
		$configured = $this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_REDIRECT_URL, '');
		if ($configured !== '') {
			return $configured;
		}

		return $this->urlGenerator->getAbsoluteURL('/');
	}//end resolveRedirectUrl()

	/**
	 * Persist the provider-assigned paymentIntentId onto the PaymentRequest,
	 * stripping OR metadata keys before the roundtrip (mirrors the
	 * fleet-reference receiver's normalise-then-save pattern).
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $paymentRequest The minted/reused PaymentRequest row.
	 * @param string $paymentIntentId The provider-assigned intent id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
	 */
	private function persistPaymentIntentId(object $objectService, array $paymentRequest, string $paymentIntentId): void {
		$uuid = (string)($paymentRequest['id'] ?? '');

		$data = [];
		foreach ($paymentRequest as $key => $value) {
			if ($key === 'id' || str_starts_with((string)$key, '@') === true) {
				continue;
			}

			$data[$key] = $value;
		}

		$data['paymentIntentId'] = $paymentIntentId;

		$saveUuid = null;
		if ($uuid !== '') {
			$saveUuid = $uuid;
		}

		$objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_PAYMENT_REQUEST,
			uuid: $saveUuid,
			_rbac: false,
			_multitenancy: false,
		);
	}//end persistPaymentIntentId()

	/**
	 * Log a downstream failure without leaking exception internals to the
	 * caller (ADR-005) — debug detail stays in the log only.
	 *
	 * @param string $step Which step of the chain failed.
	 * @param Throwable $exception The caught exception.
	 *
	 * @return void
	 */
	private function logDownstreamFailure(string $step, Throwable $exception): void {
		$this->logger->error(
			'Shillinq: portal payment initiation failed',
			['step' => $step, 'exception' => $exception->getMessage()]
		);
	}//end logDownstreamFailure()
}//end class
