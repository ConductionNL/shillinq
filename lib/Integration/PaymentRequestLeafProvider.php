<?php

/**
 * Payment Request Leaf Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The read and append half of `shillinq-payment-requests`, the leaf a case app
 * places on its own object to ask a citizen or a company for money. ADR-066
 * decision 2 is the whole point of the shape: the consuming app calls `list`
 * and `create`, shillinq answers, and shillinq never calls back into the
 * consuming app. dossiq learns the outcome from the object event, not from a
 * controller shillinq knows the name of.
 *
 * `create` refuses twice before it writes. Once on the caller: raising a
 * payment request is the `payment.request` action, and a user whose groups do
 * not carry it is refused, not silently given a request. Once on the shape:
 * `ObjectPaymentRequestValidator` holds the conditional requireds and the
 * one-open-request-per-type invariant, so a second pending leges request on
 * the same case is refused by name rather than created beside the first.
 *
 * @category Integration
 * @package  OCA\Shillinq\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\Shillinq\Kernel\Contracts\DebtorCreditServiceInterface;
use OCA\Shillinq\Kernel\Contracts\FeeScheduleServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectPaymentRequestValidatorInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectRequestInvoiceServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentActionAppGrantInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentActionAuthorizerInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRequestFinderInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRequestLeafProviderInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRequestPortalScopeInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentSettlementServiceInterface;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\Payment\PaymentLinkCreator;
use OCA\Shillinq\Service\PaymentRequestFinder;
use OCA\Shillinq\Service\PaymentRequestPortalScope;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Lists and appends payment requests standing on a host object.
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003)
 */
final class PaymentRequestLeafProvider implements IntegrationProvider, PaymentRequestLeafProviderInterface {

	/**
	 * The schema holding payment requests.
	 *
	 * @var string
	 */
	private const SCHEMA = 'PaymentRequest';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param ObjectPaymentRequestValidatorInterface $validator The conditional shape and the uniqueness invariant.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param PaymentActionAuthorizerInterface $authorizer Whether the caller carries payment.request.
	 * @param FeeScheduleServiceInterface $feeSchedules The published fee for the host object's type.
	 * @param PaymentSettlementServiceInterface $settlements Money that arrived another way, and the state it derives.
	 * @param PaymentActionAppGrantInterface $appGrant Whether a calling app carries payment.request (REQ-SOPR-010).
	 * @param PaymentRequestFinderInterface|null $finder Reads every page of requests on a subject; built on demand when absent.
	 * @param PaymentRequestPortalScopeInterface $portalScope Gives a request without an invoice its portal scope.
	 * @param ?ObjectRequestInvoiceServiceInterface $invoices Issues the invoice a caller asks for at create (REQ-ORS-006).
	 * @param ?DebtorCreditServiceInterface $credits Pays a new request from the debtor's open credit first (REQ-ORC-004).
	 * @param ?MolliePaymentAdapterInterface $paymentAdapter The bound payment adapter; dormant or absent means online payment is not set up.
	 * @param ?PaymentLinkCreator $linkCreator Creates the checkout link of a raised request (receivables-payment-links D2).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectPaymentRequestValidatorInterface $validator,
		private readonly IAppConfig $appConfig,
		private readonly PaymentActionAuthorizerInterface $authorizer,
		private readonly FeeScheduleServiceInterface $feeSchedules,
		private readonly PaymentSettlementServiceInterface $settlements,
		private readonly PaymentActionAppGrantInterface $appGrant,
		private readonly ?PaymentRequestFinderInterface $finder = null,
		private readonly PaymentRequestPortalScopeInterface $portalScope = new PaymentRequestPortalScope(),
		private readonly ?ObjectRequestInvoiceServiceInterface $invoices = null,
		private readonly ?DebtorCreditServiceInterface $credits = null,
		private readonly ?MolliePaymentAdapterInterface $paymentAdapter = null,
		private readonly ?PaymentLinkCreator $linkCreator = null,
	) {
	}//end __construct()

	/**
	 * The leaf id.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return self::LEAF_ID;
	}//end getId()

	/**
	 * The label shown on the leaf.
	 *
	 * @return string The label.
	 */
	public function getLabel(): string {
		return 'Payment requests';
	}//end getLabel()

	/**
	 * The MDI icon name.
	 *
	 * @return string The icon.
	 */
	public function getIcon(): string {
		return 'CreditCardOutline';
	}//end getIcon()

	/**
	 * The group the leaf sorts under.
	 *
	 * @return string The group.
	 */
	public function getGroup(): string {
		return 'Finance';
	}//end getGroup()

	/**
	 * The app that must be installed for this leaf to answer.
	 *
	 * @return string The app id.
	 */
	public function getRequiredApp(): string {
		return 'shillinq';
	}//end getRequiredApp()

	/**
	 * Requests are stored in shillinq's own register, not fetched per call.
	 *
	 * @return string The storage strategy.
	 */
	public function getStorageStrategy(): string {
		return 'app-local';
	}//end getStorageStrategy()

	/**
	 * No OpenConnector source: the money moves through integriq's payment
	 * providers, but the request itself is shillinq's own record.
	 *
	 * @return string|null The source.
	 */
	public function getOpenConnectorSource(): ?string {
		return null;
	}//end getOpenConnectorSource()

	/**
	 * The leaf answers whenever shillinq is installed.
	 *
	 * @return bool True.
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * The action a write needs.
	 *
	 * @return string The action.
	 */
	public function requiresPermission(): string {
		return self::ACTION_REQUEST;
	}//end requiresPermission()

	/**
	 * The leaf needs no credentials of its own.
	 *
	 * @return array<string, mixed> The requirements.
	 */
	public function authRequirements(): array {
		return [];
	}//end authRequirements()

	/**
	 * The requests standing on this host object, newest first.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $filters Optional list filters; unknown keys are ignored.
	 *
	 * @return array<string, mixed> The `{items, total, nextCursor, fee}` envelope.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003)
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-008)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $filters is IntegrationProvider's;
	 * this leaf returns every request on the object and filters nothing yet.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$requests = $this->requestsOn(register: $register, schema: $schema, objectId: $objectId, asSystem: false);

		$projected = [];
		foreach ($requests as $request) {
			$settlements = [];
			if (is_array($request['settlements'] ?? null) === true) {
				$settlements = $request['settlements'];
			}

			$projected[] = [
				'id' => (string)($request['id'] ?? ''),
				'state' => (string)($request['state'] ?? 'pending'),
				'amount' => (float)($request['amount'] ?? 0),
				'currency' => (string)($request['currency'] ?? 'EUR'),
				'requestType' => (string)($request['requestType'] ?? ''),
				'description' => (string)($request['description'] ?? ''),
				'dueAt' => (string)($request['dueAt'] ?? ''),
				'paymentLink' => (string)($request['paymentLink'] ?? ''),
				'capturedAt' => (string)($request['capturedAt'] ?? ''),
				'confirmationSummary' => (string)($request['confirmationSummary'] ?? ''),
				// What the owning app of a school contribution reads to find the
				// state of each child's payment (REQ-SCON-004, REQ-SCON-009).
				'beneficiary' => ($request['beneficiary'] ?? null),
				'invoiceReference' => (string)($request['invoiceReference'] ?? ''),
				'voluntary' => (($request['voluntary'] ?? false) === true),
				'settledAt' => (string)($request['settledAt'] ?? ''),
				'settledVia' => (string)($request['settledVia'] ?? ''),
				// The provider's own `state` above is only half the truth once a
				// counter payment exists. `reported` is the two together, which is
				// what a handler is actually asking when they look (REQ-FPCR-003).
				'reported' => $this->settlements->report($request),
				'settlements' => $settlements,
			];
		}

		// The paginated envelope, plus the published fee for this object's type
		// (REQ-SOPR-008). The fee is what lets the panel offer "Raise leges
		// request" with a real amount instead of an empty form; a desk clerk
		// who has to look the tariff up somewhere else is the failure this
		// closes.
		return [
			'items' => $projected,
			'total' => count($projected),
			'nextCursor' => null,
			'fee' => $this->feeFor(register: $register, schema: $schema, objectId: $objectId),
			'onlinePayment' => $this->onlinePayment(),
		];
	}//end list()

	/**
	 * Whether a payment link can be created: a live payment adapter is bound.
	 * The panel disables Send payment link with the reason otherwise
	 * (receivables-payment-links D5, REQ-RPL-002).
	 *
	 * @return array{live: bool, reason: string} `reason` is `not-set-up` when not live, else ''.
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.3
	 */
	private function onlinePayment(): array {
		if ($this->paymentAdapter === null || $this->paymentAdapter->isDormant() === true) {
			return ['live' => false, 'reason' => 'not-set-up'];
		}

		return ['live' => true, 'reason' => ''];
	}//end onlinePayment()

	/**
	 * The published fee for the host object's type, or null when it carries none.
	 *
	 * Never throws: a fee that cannot be resolved must not take the request list
	 * down with it, because the list is the part a handler actually needs.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 *
	 * @return array<string, mixed>|null The schedule, or null.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-008)
	 */
	private function feeFor(string $register, string $schema, string $objectId): ?array {
		try {
			$rows = $this->objectService
				->setRegister($register)
				->setSchema($schema)
				->findAll(['filters' => ['id' => $objectId], 'limit' => 1]);

			if ($rows === [] || is_array($rows[0]) === false) {
				return null;
			}

			return $this->feeSchedules->resolveForObject(
				register: $register,
				schema: $schema,
				object: $rows[0],
				intakeChannel: 'desk',
			);
		} catch (\Throwable $e) {
			return null;
		}
	}//end feeFor()

	/**
	 * One request by id, scoped to the host object so a request on another
	 * object cannot be read through this host.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The request id.
	 *
	 * @return array<string, mixed> The request.
	 *
	 * @throws RuntimeException When no such request stands on this object.
	 */
	public function get(string $register, string $schema, string $objectId, string $entityId): array {
		$listed = $this->list(register: $register, schema: $schema, objectId: $objectId);
		foreach ($listed['items'] as $request) {
			if ($request['id'] === $entityId) {
				return $request;
			}
		}

		throw new RuntimeException('No payment request with that id stands on this object.');
	}//end get()

	/**
	 * Append one payment request to the host object.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload The request fields: amount, currency, requestType, description, debtor, dueAt.
	 *
	 * @return array<string, mixed> The created request.
	 *
	 * @throws RuntimeException When the caller lacks the payment.request action.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003)
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		if ($this->authorizer->may(self::ACTION_REQUEST) === false) {
			throw new RuntimeException('403 Raising a payment request needs the payment.request action.');
		}

		return $this->append(
			register: $register,
			schema: $schema,
			objectId: $objectId,
			payload: $payload,
			requestedBy: $this->authorizer->callerId(),
			asSystem: false,
		);
	}//end create()

	/**
	 * Append one payment request to the host object on behalf of an app.
	 *
	 * For a consuming app that has to ask for money with nobody signed in who
	 * may: larpinq accepting a registration a player took a free place for, or
	 * one its daily job moved up from the waitlist. The app must be granted
	 * `payment.request` in `paymentActionApps` and be enabled; no app carries
	 * it by default, and a signed-in administrator does not lend it. The
	 * request is read and written as the system, because there may be no user,
	 * and records `requestedBy` as `app:<appId>`.
	 *
	 * This method is not part of IntegrationProvider, so OpenRegister's HTTP
	 * route never reaches it: the app id comes from PHP in the calling app,
	 * never from a request body. `create` stays the only HTTP path.
	 *
	 * @param string $appId The calling app's id, for example `larpinq`.
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload The same fields as create().
	 *
	 * @return array<string, mixed> The created request.
	 *
	 * @throws RuntimeException When the app is not granted payment.request or is not enabled; nothing is written.
	 *
	 * @spec openspec/changes/payment-request-app-caller/specs/object-payment-requests/spec.md (REQ-SOPR-010)
	 */
	public function createAsApp(string $appId, string $register, string $schema, string $objectId, array $payload): array {
		if ($this->appGrant->allows(appId: $appId, action: self::ACTION_REQUEST) === false) {
			throw new RuntimeException('403 This app is not granted the payment.request action (paymentActionApps).');
		}

		return $this->append(
			register: $register,
			schema: $schema,
			objectId: $objectId,
			payload: $payload,
			requestedBy: 'app:'.$appId,
			asSystem: true,
		);
	}//end createAsApp()

	/**
	 * Build, validate and save one request on the host object, once the caller is cleared.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload The request fields.
	 * @param string $requestedBy Who asked: a user id, or `app:<appId>`.
	 * @param bool $asSystem Read and write without the session's rights (an app caller, maybe no user).
	 *
	 * @return array<string, mixed> The created request.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003)
	 */
	private function append(
		string $register,
		string $schema,
		string $objectId,
		array $payload,
		string $requestedBy,
		bool $asSystem,
	): array {
		$subject = $this->subjectOf(register: $register, schema: $schema, objectId: $objectId, payload: $payload);
		$request = $this->newRequest(subject: $subject, payload: $payload, requestedBy: $requestedBy);

		$existing = $this->requestsOn(register: $register, schema: $schema, objectId: $objectId, asSystem: $asSystem);
		$holders  = $this->finder()->withReference(reference: trim((string)($payload['paymentReference'] ?? '')), asSystem: true);
		$this->validator->validate(request: $request, existing: $existing, referenceHolders: $holders);

		return $this->finaliseRequest(request: $request, payload: $payload, requestedBy: $requestedBy, asSystem: $asSystem);
	}//end append()

	/**
	 * The request's subject: the host object.
	 *
	 * @param string               $register The host object's register.
	 * @param string               $schema   The host object's schema.
	 * @param string               $objectId The host object's id.
	 * @param array<string, mixed> $payload  The request fields.
	 *
	 * @return array<string, mixed>
	 */
	private function subjectOf(string $register, string $schema, string $objectId, array $payload): array {
		return [
			'type' => (string)($payload['subjectType'] ?? 'object'),
			'register' => $register,
			'schema' => $schema,
			'id' => $objectId,
		];
	}//end subjectOf()

	/**
	 * The new, pending request built from the payload, stamped with its portal scope.
	 *
	 * @param array<string, mixed> $subject     The request's subject.
	 * @param array<string, mixed> $payload     The request fields.
	 * @param string               $requestedBy Who asked: a user id, or `app:<appId>`.
	 *
	 * @return array<string, mixed>
	 */
	private function newRequest(array $subject, array $payload, string $requestedBy): array {
		$request = [
			'subjectKind' => 'object',
			'subject' => $subject,
			'linkedObjects' => [$subject],
			'requestType' => (string)($payload['requestType'] ?? ''),
			'amount' => $payload['amount'] ?? null,
			'currency' => (string)($payload['currency'] ?? 'EUR'),
			'description' => (string)($payload['description'] ?? ''),
			'state' => 'pending',
			'paymentGateway' => (string)($payload['paymentGateway'] ?? 'mollie'),
			'requestedBy' => $requestedBy,
		];

		// The customer portal lists a request without an invoice by its
		// debtor's customer (REQ-SPPI-008).
		return $this->portalScope->stamp(request: $this->withOptionalFields(request: $request, payload: $payload));
	}//end newRequest()

	/**
	 * Copy the optional debtor and due date onto the request.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param array<string, mixed> $payload The request fields.
	 *
	 * @return array<string, mixed>
	 */
	private function withOptionalFields(array $request, array $payload): array {
		if (isset($payload['debtor']) === true && is_array($payload['debtor']) === true) {
			$request['debtor'] = $payload['debtor'];
		}

		if ((string)($payload['dueAt'] ?? '') !== '') {
			$request['dueAt'] = (string)$payload['dueAt'];
		}

		return $this->withReferenceFields(request: $request, payload: $payload);
	}//end withOptionalFields()

	/**
	 * The transfer reference the payer quotes, and whether an invoice is
	 * wanted behind the request (REQ-ORS-002), plus the administration.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param array<string, mixed> $payload The request fields.
	 *
	 * @return array<string, mixed>
	 */
	private function withReferenceFields(array $request, array $payload): array {
		$reference = trim((string)($payload['paymentReference'] ?? ''));
		if ($reference !== '') {
			$request['paymentReference'] = $reference;
		}

		if (array_key_exists('invoiceRequested', $payload) === true) {
			$request['invoiceRequested'] = ($payload['invoiceRequested'] === true);
		}

		$administrationId = trim((string)($payload['administrationId'] ?? ''));
		if ($administrationId !== '') {
			$request['administrationId'] = $administrationId;
		}

		return $request;
	}//end withReferenceFields()

	/**
	 * Issue a requested invoice, apply open credit and save the validated request.
	 *
	 * @param array<string, mixed> $request     The validated request.
	 * @param array<string, mixed> $payload     The request fields.
	 * @param string               $requestedBy Who asked: a user id, or `app:<appId>`.
	 * @param bool                 $asSystem    Write without the session's rights.
	 *
	 * @return array<string, mixed> The created request.
	 */
	private function finaliseRequest(array $request, array $payload, string $requestedBy, bool $asSystem): array {
		$request = $this->withRequestedInvoice(request: $request, administrationId: trim((string)($payload['administrationId'] ?? '')));

		// The debtor's open credit pays the new request first (REQ-ORC-004).
		if ($this->credits !== null) {
			$request = $this->credits->applyOpenCredit(request: $request, actor: $requestedBy);
		}

		return $this->withPaymentLink(created: $this->saveRequest(request: $request, asSystem: $asSystem), asSystem: $asSystem);
	}//end finaliseRequest()

	/**
	 * Give a raised, still pending request its checkout link from the
	 * provider, and store it with the provider payment id and the checkout
	 * expiry (REQ-RPL-001). Without a live provider the request stays without
	 * a link.
	 *
	 * @param array<string, mixed> $created  The saved request, with its id.
	 * @param bool                 $asSystem Write without the session's rights.
	 *
	 * @return array<string, mixed> The request, with its link fields when it got a link.
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-1.3
	 */
	private function withPaymentLink(array $created, bool $asSystem): array {
		if ($this->linkCreator === null) {
			return $created;
		}

		$fields = $this->linkCreator->linkFieldsFor(request: $created);
		if ($fields === []) {
			return $created;
		}

		$data = [];
		foreach ($created as $key => $value) {
			if ($key !== 'id' && str_starts_with((string)$key, '@') === false) {
				$data[$key] = $value;
			}
		}

		$this->objectService->saveObject(
			object: array_merge($data, $fields),
			register: $this->registerSlug(),
			schema: self::SCHEMA,
			uuid: (string)$created['id'],
			_rbac: ($asSystem === false),
			_multitenancy: ($asSystem === false),
		);

		return array_merge($created, $fields);
	}//end withPaymentLink()

	/**
	 * An invoice asked for at create stands behind the request, so a
	 * capture settles it and books the income once (REQ-ORS-006). It is
	 * issued after the request is validated, so a refused request never
	 * leaves an invoice behind.
	 *
	 * @param array<string, mixed> $request          The validated request.
	 * @param string               $administrationId The administration the invoice is issued in.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When an invoice is requested but no invoice service is available.
	 */
	private function withRequestedInvoice(array $request, string $administrationId): array {
		if (($request['invoiceRequested'] ?? false) === true) {
			if ($this->invoices === null) {
				throw new RuntimeException('An invoice was requested, but no invoice service is available.');
			}

			$request['invoiceReference'] = $this->invoices->issue(
				request: $request,
				administrationId: $administrationId,
				today: gmdate('Y-m-d'),
			);
		}

		return $request;
	}//end withRequestedInvoice()

	/**
	 * Save the request and return it as an array.
	 *
	 * @param array<string, mixed> $request  The request.
	 * @param bool                 $asSystem Write without the session's rights.
	 *
	 * @return array<string, mixed> The created request.
	 */
	private function saveRequest(array $request, bool $asSystem): array {
		$saved = $this->objectService->saveObject(
			object: $request,
			register: $this->registerSlug(),
			schema: self::SCHEMA,
			_rbac: ($asSystem === false),
			_multitenancy: ($asSystem === false),
		);

		// OpenRegister answers a save with an ObjectEntity, not the array that
		// went in. The leaf contract returns the created thing as an array, and
		// a provider that forwarded the entity would type-error at the boundary.
		$created = $saved->getObject();
		if ($saved->getUuid() !== null) {
			$created['id'] = $saved->getUuid();
		}

		return $created;
	}//end saveRequest()

	/**
	 * The leaf appends; it never rewrites a request in place. A request changes
	 * state through its own lifecycle, which is where the audit trail is.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The request id.
	 * @param array<string, mixed> $payload Ignored.
	 *
	 * @return array<string, mixed> Never returns.
	 *
	 * @throws RuntimeException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is
	 * IntegrationProvider's. The method refuses every call, so it reads none of
	 * its arguments; dropping them would break the contract.
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
		throw new RuntimeException('A payment request changes state through its lifecycle, not through the leaf.');
	}//end update()

	/**
	 * A payment request is evidence and is never deleted through the leaf.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The request id.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is
	 * IntegrationProvider's. The method refuses every call, so it reads none of
	 * its arguments; dropping them would break the contract.
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		throw new RuntimeException('A payment request is payment evidence and is voided, never deleted.');
	}//end delete()

	/**
	 * Health of the leaf.
	 *
	 * @return array<string, mixed> The health report.
	 */
	public function health(): array {
		return ['status' => 'ok', 'leaf' => self::LEAF_ID];
	}//end health()

	/**
	 * Every stored request whose subject is this host object, read to the last
	 * page. One page of 200 used to be read and filtered here, so with school
	 * contributions in the store a case's own request could fall off the page.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param bool $asSystem Read without the session's rights (an app caller, REQ-SOPR-010).
	 *
	 * @return array<int, array<string, mixed>> The raw requests.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-004)
	 */
	private function requestsOn(string $register, string $schema, string $objectId, bool $asSystem): array {
		return $this->finder()->onSubject(register: $register, schema: $schema, objectId: $objectId, asSystem: $asSystem);
	}//end requestsOn()

	/**
	 * The request finder, injected or built on demand.
	 *
	 * @return PaymentRequestFinderInterface The finder.
	 */
	private function finder(): PaymentRequestFinderInterface {
		return ($this->finder ?? new PaymentRequestFinder(
			objectService: $this->objectService,
			validator: $this->validator,
			appConfig: $this->appConfig,
		));
	}//end finder()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
	}//end registerSlug()
}//end class
