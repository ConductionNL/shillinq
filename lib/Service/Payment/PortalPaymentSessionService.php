<?php

/**
 * Portal Payment Session Service (portal-payment-initiation).
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payment;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Portal\PortalSubjectResolver;
use OCA\Shillinq\Util\ObjectIdentifier;
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
class PortalPaymentSessionService {
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
		return $this->openSession(
			claims: $claims,
			target: $target,
			prepare: fn (object $objectService, string $id, string $customerMasterId): ?array => $this->prepareInvoice(
				objectService: $objectService,
				target: $id,
				customerMasterId: $customerMasterId,
			),
		);
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
		return $this->openSession(
			claims: $claims,
			target: $target,
			prepare: fn (object $objectService, string $id, string $customerMasterId): ?array => $this->prepareRequest(
				objectService: $objectService,
				target: $id,
				customerMasterId: $customerMasterId,
			),
		);
	}//end initiateForRequest()

	/**
	 * The chain both targets share: the target shape, the audience, the
	 * subject's customer, the target-specific preparation, the provider call
	 * and the saved intent id.
	 *
	 * @param array<string, mixed> $claims The VERIFIED assertion claims.
	 * @param string $target The client-supplied opaque id.
	 * @param callable $prepare Resolves the owned, payable target from (object service, id,
	 *                          customer): ['request' => row, 'session' => PaymentSessionRequest] or null.
	 *
	 * @return PortalPaymentSessionResult
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003)
	 */
	private function openSession(array $claims, string $target, callable $prepare): PortalPaymentSessionResult {
		$target = trim($target);
		if ($this->subjects->isOpaqueId(target: $target) === false) {
			return PortalPaymentSessionResult::forbidden();
		}

		if (in_array((string)($claims['audience'] ?? ''), self::PAYING_AUDIENCES, true) === false) {
			return PortalPaymentSessionResult::forbidden();
		}

		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			$this->logDownstreamFailure(step: 'object-service-unavailable', exception: $e);
			return PortalPaymentSessionResult::downstreamError();
		}

		$session = null;
		try {
			$customerMasterId = $this->subjects->customerMasterId(
				objectService: $objectService,
				subjectRef: (string)($claims['sub'] ?? ''),
				audience: (string)($claims['audience'] ?? ''),
			);
			if ($customerMasterId === null) {
				return PortalPaymentSessionResult::forbidden();
			}

			$prepared = $prepare($objectService, $target, $customerMasterId);
			if ($prepared === null) {
				return PortalPaymentSessionResult::forbidden();
			}

			$session = $this->provider->createSession($prepared['session']);

			$this->persistPaymentIntentId(
				objectService: $objectService,
				paymentRequest: $prepared['request'],
				paymentIntentId: $session->paymentIntentId,
			);
		} catch (Throwable $e) {
			$this->logDownstreamFailure(step: 'initiation-chain-failed', exception: $e);
			return PortalPaymentSessionResult::downstreamError();
		}//end try

		if ($session->dormant === true) {
			return PortalPaymentSessionResult::deferred();
		}

		return PortalPaymentSessionResult::success(checkoutUrl: $session->checkoutUrl);
	}//end openSession()

	/**
	 * The invoice target: the owned payable invoice, its minted or reused
	 * request, and the session for the invoice amount.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $target The opaque invoice id or slug.
	 * @param string $customerMasterId The verified owner.
	 *
	 * @return array{request: array<string, mixed>, session: PaymentSessionRequest}|null Null when not payable by this subject.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-003, REQ-SPPI-004)
	 */
	private function prepareInvoice(object $objectService, string $target, string $customerMasterId): ?array {
		$invoice = $this->findOwnedPayableInvoice(objectService: $objectService, target: $target, customerMasterId: $customerMasterId);
		if ($invoice === null) {
			return null;
		}

		$paymentRequest = $this->mintOrReusePaymentRequest(objectService: $objectService, invoice: $invoice);

		return [
			'request' => $paymentRequest,
			'session' => $this->buildSessionRequest(invoice: $invoice, paymentRequest: $paymentRequest),
		];
	}//end prepareInvoice()

	/**
	 * The request target: the subject's own pending request without an
	 * invoice, and the session for its own amount (REQ-SOPR-005).
	 *
	 * Read with `find()` by uuid: a `findAll()` filter on `id` addresses a JSON
	 * property and matches nothing. A foreign, invoice-backed, settled or
	 * missing request collapses to the same null (no existence oracle).
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $target The opaque payment request id.
	 * @param string $customerMasterId The verified owner.
	 *
	 * @return array{request: array<string, mixed>, session: PaymentSessionRequest}|null Null when not payable by this subject.
	 *
	 * @throws RuntimeException When the request carries no chargeable amount.
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	private function prepareRequest(object $objectService, string $target, string $customerMasterId): ?array {
		try {
			$found = $objectService->find(
				id: $target,
				register: self::REGISTER,
				schema: self::SCHEMA_PAYMENT_REQUEST,
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $notFound) {
			return null;
		}

		$request = ObjectIdentifier::recordWithId(candidate: $found);
		if ($request === null) {
			return null;
		}

		if ((string)($request['id'] ?? '') === '') {
			$request['id'] = $target;
		}

		$owner = (string)($request['customerId'] ?? ($request['debtor']['customerMasterId'] ?? ''));
		if ($owner !== $customerMasterId
			|| (string)($request['invoiceReference'] ?? '') !== ''
			|| (string)($request['state'] ?? '') !== 'pending'
		) {
			return null;
		}

		$amount = ($request['amount'] ?? null);
		if (is_bool($amount) === true || is_numeric($amount) === false || (float)$amount <= 0.0) {
			throw new RuntimeException('This payment request carries no amount that can be charged, so no payment session was opened.');
		}

		$description = trim((string)($request['description'] ?? ''));
		if ($description === '') {
			$description = 'Payment request';
		}

		return [
			'request' => $request,
			'session' => new PaymentSessionRequest(
				amount: (float)$amount,
				currency: (string)($request['currency'] ?? 'EUR'),
				description: $description,
				redirectUrl: $this->resolveRedirectUrl(),
				webhookUrl: $this->urlGenerator->linkToRouteAbsolute(self::WEBHOOK_ROUTE, ['gateway' => self::GATEWAY]),
				method: 'ideal',
				metadata: [
					'paymentRequestId' => (string)$request['id'],
					'administrationId' => (string)($request['administrationId'] ?? ''),
					'correlationId' => (string)$request['id'],
				],
			),
		];
	}//end prepareRequest()

	/**
	 * Resolve the target ARInvoice — id/slug match AND owned by the
	 * verified customerMasterId AND in a payable state. A foreign owner, a
	 * non-payable state and a non-existent id all collapse to the SAME null
	 * (no existence oracle, REQ-SPPI-003).
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $target The client-supplied opaque id/slug.
	 * @param string $customerMasterId The verified owner (never from the request).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-003)
	 */
	private function findOwnedPayableInvoice(object $objectService, string $target, string $customerMasterId): ?array {
		foreach (['id', 'slug'] as $key) {
			$rows = $objectService
				->setRegister(self::REGISTER)
				->setSchema(self::SCHEMA_AR_INVOICE)
				->findAll(
					config: [
						'filters' => [
							$key => $target,
							'customerId' => $customerMasterId,
						],
						'limit' => 1,
					],
					_rbac: false,
					_multitenancy: false,
				);

			if (is_array($rows) === true && empty($rows) === false) {
				$invoice = $rows[0];

				// ARInvoice's lifecycle field is `lifecycleState`; `state` is not a
				// property it declares, so reading only `state` found no invoice
				// payable at all (REQ-SCON-010).
				$state = (string)($invoice['lifecycleState'] ?? ($invoice['state'] ?? ''));
				if (in_array($state, self::PAYABLE_STATES, true) === true) {
					return $invoice;
				}

				// Matched by id/slug but foreign/non-payable — do not also
				// try the other key with the same raw string (it already
				// resolved to a concrete, non-payable row).
				return null;
			}
		}//end foreach

		return null;
	}//end findOwnedPayableInvoice()

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
		$configured = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_REDIRECT_URL, '');
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
