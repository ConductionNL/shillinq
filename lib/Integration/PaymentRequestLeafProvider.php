<?php

/**
 * Payment Request Leaf Provider
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\Shillinq\Service\FeeScheduleService;
use OCA\Shillinq\Service\ObjectPaymentRequestValidator;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Service\PaymentSettlementService;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Lists and appends payment requests standing on a host object.
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003)
 */
final class PaymentRequestLeafProvider implements IntegrationProvider {
	/**
	 * The leaf id, equal on both halves so gate-24 can pair them.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'shillinq-payment-requests';

	/**
	 * The action a caller needs before a request may be raised.
	 *
	 * @var string
	 */
	public const ACTION_REQUEST = PaymentActionAuthorizer::ACTION_REQUEST;

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
	 * @param ObjectPaymentRequestValidator $validator The conditional shape and the uniqueness invariant.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param PaymentActionAuthorizer $authorizer Whether the caller carries payment.request.
	 * @param FeeScheduleService $feeSchedules The published fee for the host object's type.
	 * @param PaymentSettlementService $settlements Money that arrived another way, and the state it derives.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectPaymentRequestValidator $validator,
		private readonly IAppConfig $appConfig,
		private readonly PaymentActionAuthorizer $authorizer,
		private readonly FeeScheduleService $feeSchedules,
		private readonly PaymentSettlementService $settlements,
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
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$requests = $this->requestsOn(register: $register, schema: $schema, objectId: $objectId);

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
		];
	}//end list()

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
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		if ($this->authorizer->may(self::ACTION_REQUEST) === false) {
			throw new RuntimeException('403 Raising a payment request needs the payment.request action.');
		}

		$subject = [
			'type' => (string)($payload['subjectType'] ?? 'object'),
			'register' => $register,
			'schema' => $schema,
			'id' => $objectId,
		];

		$request = [
			'subjectKind' => 'object',
			'subject' => $subject,
			'requestType' => (string)($payload['requestType'] ?? ''),
			'amount' => $payload['amount'] ?? null,
			'currency' => (string)($payload['currency'] ?? 'EUR'),
			'description' => (string)($payload['description'] ?? ''),
			'state' => 'pending',
			'paymentGateway' => (string)($payload['paymentGateway'] ?? 'mollie'),
			'requestedBy' => $this->authorizer->callerId(),
		];

		if (isset($payload['debtor']) === true && is_array($payload['debtor']) === true) {
			$request['debtor'] = $payload['debtor'];
		}

		if ((string)($payload['dueAt'] ?? '') !== '') {
			$request['dueAt'] = (string)$payload['dueAt'];
		}

		$this->validator->validate(
			request: $request,
			existing: $this->requestsOn(register: $register, schema: $schema, objectId: $objectId),
		);

		$saved = $this->objectService->saveObject(
			object: $request,
			register: $this->registerSlug(),
			schema: self::SCHEMA,
		);

		// OpenRegister answers a save with an ObjectEntity, not the array that
		// went in. The leaf contract returns the created thing as an array, and
		// a provider that forwarded the entity would type-error at the boundary.
		$created = $saved->getObject();
		if ($saved->getUuid() !== null) {
			$created['id'] = $saved->getUuid();
		}

		return $created;
	}//end create()

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
	 * Every stored request whose subject is this host object.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 *
	 * @return array<int, array<string, mixed>> The raw requests.
	 */
	private function requestsOn(string $register, string $schema, string $objectId): array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA)
			->findAll(['filters' => ['subjectKind' => 'object'], 'limit' => 200]);

		$key = $this->validator->subjectKey(['register' => $register, 'schema' => $schema, 'id' => $objectId]);

		$mine = [];
		foreach ($rows as $row) {
			$subject = $row['subject'] ?? null;
			if (is_array($subject) === false) {
				continue;
			}

			$candidate = $this->validator->subjectKey((array)$subject);

			if ($candidate === $key) {
				$mine[] = $row;
			}
		}

		return $mine;
	}//end requestsOn()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
	}//end registerSlug()
}//end class
