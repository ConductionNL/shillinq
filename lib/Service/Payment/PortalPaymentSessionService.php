<?php

/**
 * Portal Payment Session Service (portal-payment-initiation).
 *
 * The imperative half of the subject-initiated pay-now flow (design.md "The
 * initiation chain"). Given a VERIFIED assertion's claims and a client-chosen
 * opaque target id, this service:
 *
 *   1. resolves the subject's `customerMasterId` scope claim server-side by
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
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
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
	 * Portaliq's own register slug — read cross-app to resolve the subject's
	 * claims, never written.
	 */
	private const PORTALIQ_REGISTER = 'portaliq';

	/**
	 * The portalAccount schema, carrying the server-managed `claims` map.
	 */
	private const SCHEMA_PORTAL_ACCOUNT = 'portalAccount';

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
	 * The claim namespace this app's own scope claim lives under
	 * (`claims.shillinq.customerMasterId`, contract v2 A4 addressing).
	 */
	private const CLAIM_APP_ID = 'shillinq';

	/**
	 * The claim name resolved from the subject's portalAccount.
	 */
	private const CLAIM_NAME = 'customerMasterId';

	/**
	 * The audience this flow serves — a non-customer assertion is refused
	 * upstream by the controller, but the service re-checks defensively.
	 */
	private const AUDIENCE_CUSTOMER = 'customer';

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
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PaymentProviderInterface $provider,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
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
	 */
	public function initiate(array $claims, string $target): PortalPaymentSessionResult {
		$target = trim($target);
		if ($this->isOpaqueId(target: $target) === false) {
			return PortalPaymentSessionResult::forbidden();
		}

		if ((string)($claims['audience'] ?? '') !== self::AUDIENCE_CUSTOMER) {
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
			$customerMasterId = $this->resolveCustomerMasterId(
				objectService: $objectService,
				subjectRef: (string)($claims['sub'] ?? ''),
				audience: (string)($claims['audience'] ?? ''),
			);
			if ($customerMasterId === null) {
				return PortalPaymentSessionResult::forbidden();
			}

			$invoice = $this->findOwnedPayableInvoice(
				objectService: $objectService,
				target: $target,
				customerMasterId: $customerMasterId,
			);
			if ($invoice === null) {
				return PortalPaymentSessionResult::forbidden();
			}

			$paymentRequest = $this->mintOrReusePaymentRequest(objectService: $objectService, invoice: $invoice);

			$session = $this->provider->createSession(
				$this->buildSessionRequest(invoice: $invoice, paymentRequest: $paymentRequest)
			);

			$this->persistPaymentIntentId(
				objectService: $objectService,
				paymentRequest: $paymentRequest,
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
	}//end initiate()

	/**
	 * SSRF hardening (REQ-SPPI-003): the target is used ONLY as an opaque
	 * OpenRegister object id/slug, never to build an outbound request. Reject
	 * anything that looks like a URL, an absolute/relative path, or a parent
	 * traversal.
	 *
	 * @param string $target The client-supplied target id.
	 *
	 * @return bool True when the target is safe to use as an opaque id.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-003)
	 */
	private function isOpaqueId(string $target): bool {
		if ($target === '') {
			return false;
		}

		if (str_contains($target, '://') === true) {
			return false;
		}

		if (str_starts_with($target, '/') === true || str_starts_with($target, '\\') === true) {
			return false;
		}

		if (str_contains($target, '..') === true) {
			return false;
		}

		return true;
	}//end isOpaqueId()

	/**
	 * Resolve `claims.shillinq.customerMasterId` from the subject's OWN
	 * portalAccount row — mirrors portaliq's
	 * `PortalObjectReader::resolveClaim()` (design.md Open Q1): the frozen A6
	 * assertion carries only `sub`/`audience`/`organisation`/`trust`/`jti`,
	 * never an app-specific scope claim, so this app resolves it itself by
	 * reading portaliq's own register cross-app (read-only).
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $subjectRef The verified assertion's `sub` claim.
	 * @param string $audience The verified assertion's `audience` claim.
	 *
	 * @return string|null The resolved customerMasterId, or null when absent/malformed.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
	 */
	private function resolveCustomerMasterId(object $objectService, string $subjectRef, string $audience): ?string {
		if ($subjectRef === '' || $audience === '') {
			return null;
		}

		$rows = $objectService
			->setRegister(self::PORTALIQ_REGISTER)
			->setSchema(self::SCHEMA_PORTAL_ACCOUNT)
			->findAll(
				config: [
					'filters' => [
						'subjectRef' => $subjectRef,
						'audience' => $audience,
					],
					'limit' => 2,
				],
				_rbac: false,
				_multitenancy: false,
			);

		if (is_array($rows) === false || empty($rows) === true) {
			return null;
		}

		$claims = ($rows[0]['claims'] ?? null);
		if (is_array($claims) === false) {
			return null;
		}

		$appClaims = ($claims[self::CLAIM_APP_ID] ?? null);
		if (is_array($appClaims) === false) {
			return null;
		}

		$value = ($appClaims[self::CLAIM_NAME] ?? null);
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end resolveCustomerMasterId()

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
				if (in_array((string)($invoice['state'] ?? ''), self::PAYABLE_STATES, true) === true) {
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

		$paymentRequest = [
			'invoiceReference' => $invoiceKey,
			'amount' => (float)($invoice['totalAmount'] ?? 0.0),
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
			'paymentGateway' => self::GATEWAY,
			'state' => 'pending',
			'administrationId' => (string)($invoice['administrationId'] ?? ''),
		];

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
