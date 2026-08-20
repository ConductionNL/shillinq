<?php

/**
 * Widget API Controller
 *
 * Public HTTP endpoints for the Booking Self-service Widget. Implements:
 *   GET  /api/widget/services      — list services available for booking
 *   GET  /api/widget/slots         — available slots for service + resource + date
 *   POST /api/widget/appointments  — create an appointment from the widget
 *
 * All endpoints require an `Authorization: Bearer <api_key>` header plus a
 * `businessId` query parameter (REQ-WSW-001). Rate-limited to 100 req/min/
 * business per the rate-limit policy in tasks.md.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\SlotService;
use OCA\Shillinq\Service\WidgetAuthService;
use OCA\Shillinq\Service\WidgetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Public Widget API endpoints (services, slots, appointment create).
 *
 * Authorisation: `Authorization: Bearer <api_key>` + `?businessId=<id>`.
 * Rate-limit: 100 req/min/business (configurable per WidgetAccessKey).
 *
 * Response shape per REQ-WSW-001 only exposes the safe-public subset of
 * each schema — customer PII is never returned via these endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; variable renames deferred pending a dedicated pass.
 */
class WidgetApiController extends Controller {
	/**
	 * Construct the controller with DI dependencies.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param WidgetAuthService $auth API key + rate-limit gateway.
	 * @param WidgetService $widgetService The single widget booking write path.
	 * @param SlotService $slots Slot availability computation.
	 * @param SettingsService $settings Shillinq settings (register slug, OR availability).
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		IRequest $request,
		private readonly WidgetAuthService $auth,
		private readonly WidgetService $widgetService,
		private readonly SlotService $slots,
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET /api/widget/services — list available services for a business.
	 *
	 * Returns `[{serviceId, name, duration, price, description}, ...]` per
	 * REQ-WSW-001 — no internal fields, no PII.
	 *
	 * @return JSONResponse
	 *
	 * Rate limit: public booking widget. These back a citizen-facing embed —
	 * services and slots are read repeatedly as someone picks a date, so the
	 * ceiling is generous. No credential is in play, so no brute-force counter.
	 *
	 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-4
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function services(): JSONResponse {
		$authResult = $this->guard();
		if ($authResult instanceof JSONResponse) {
			return $authResult;
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			return $this->serverError(message: 'Service catalogue unavailable.');
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
			$records = $objectService
				->setRegister($registerSlug)
				->setSchema('Service')
				->findAll(
					[
						'filters' => ['status' => 'active'],
						'limit' => 200,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq widget: services lookup failed',
				['exception' => $e->getMessage()]
			);
			return $this->serverError(message: 'Service catalogue unavailable.');
		}

		$services = [];
		foreach ($records as $record) {
			$row = $this->toArray(object: $record);

			// This endpoint is reachable by any visitor loading an embedded
			// widget, so only services explicitly flagged isPublic may be
			// listed. Filtered here rather than in the findAll() query so an
			// absent or non-boolean value DENIES: a query filter that silently
			// matched nothing would look identical to "no private services".
			if ((bool)($row['isPublic'] ?? false) !== true) {
				continue;
			}

			$service = [
				'serviceId' => (string)($row['serviceId'] ?? ''),
				'name' => (string)($row['name'] ?? ''),
				'duration' => (int)($row['duration'] ?? 0),
				'currency' => (string)($row['currency'] ?? 'EUR'),
				'description' => (string)($row['description'] ?? ''),
			];

			// The priceVisible flag is a per-service choice; publishing the
			// price regardless of it made the setting inert.
			if ((bool)($row['priceVisible'] ?? false) === true) {
				$service['price'] = ($row['basePrice'] ?? $row['price'] ?? null);
			}

			$services[] = $service;
		}//end foreach

		return new JSONResponse(data: ['services' => $services]);
	}//end services()

	/**
	 * GET /api/widget/slots — available slots for a service + resource + date.
	 *
	 * Honours ETag / If-None-Match: returns 304 Not Modified when the client
	 * already has the current slot list per REQ-WSW-002.
	 *
	 * @param string $serviceId Service identifier (required).
	 * @param string $resourceId Resource identifier (required).
	 * @param string $date Calendar date YYYY-MM-DD UTC (required).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-4
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function slots(string $serviceId = '', string $resourceId = '', string $date = ''): JSONResponse {
		$authResult = $this->guard();
		if ($authResult instanceof JSONResponse) {
			return $authResult;
		}

		$serviceId = trim($serviceId);
		$resourceId = trim($resourceId);
		$date = trim($date);

		if ($serviceId === '' || $resourceId === '' || $date === '') {
			return $this->badRequest(message: 'serviceId, resourceId and date are required.');
		}

		if ((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === false) {
			return $this->badRequest(message: 'date must be ISO YYYY-MM-DD.');
		}

		// The #491 gate, third door. services() filters the catalogue to
		// `isPublic` and appointments() refuses to book a service that is not,
		// but availability was readable for ANY serviceId of this tenant.
		// guard() only proves the caller holds the widget API key — which is
		// shipped in a PUBLIC widget, so everyone who can see the page has it.
		//
		// 404 rather than 403, matching WidgetService: a 403 would confirm the
		// service exists, which is the fact being withheld.
		if ($this->widgetService->isPubliclyBookable(serviceId: $serviceId) === false) {
			return new JSONResponse(
				data: ['error' => 'service_not_found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$result = $this->slots->getAvailableSlots(
			serviceId: $serviceId,
			resourceId: $resourceId,
			date: $date,
		);

		$clientEtag = (string)$this->request->getHeader('If-None-Match');
		if ($clientEtag !== '' && $clientEtag === $result['etag']) {
			$notModified = new JSONResponse(data: [], statusCode: Http::STATUS_NOT_MODIFIED);
			$notModified->addHeader('ETag', $result['etag']);
			return $notModified;
		}

		$response = new JSONResponse(
			data: [
				'date' => $date,
				'slots' => $result['slots'],
				'cached' => (bool)$result['cached'],
			]
		);
		$response->addHeader('ETag', $result['etag']);
		$response->addHeader('Cache-Control', 'public, max-age=' . SlotService::SLOT_CACHE_TTL_SECONDS);

		return $response;
	}//end slots()

	/**
	 * POST /api/widget/appointments — create an appointment from the widget.
	 *
	 * Returns 201 Created with `{appointmentId, status, confirmationMessage}`
	 * per design D6 — never echoes customer PII.
	 *
	 * Returns 409 Conflict on double-booking; the widget retries against the
	 * refreshed slot list per REQ-WSW-002.
	 *
	 * @param string $serviceId Service to book (required).
	 * @param string $resourceId Resource to book (required).
	 * @param string $startTime Slot start time, ISO 8601 UTC (required).
	 * @param string $endTime Slot end time, ISO 8601 UTC (required).
	 * @param string $customerName Customer display name (required, 1-255 chars).
	 * @param string $email Customer email (required, RFC 5322).
	 * @param string|null $phone Customer phone (optional, E.164).
	 * @param string|null $notes Customer notes (optional, max 500 chars).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-4
	 * Rate limit: tighter than its siblings, because this one BOOKS.
	 * `services` / `slots` are reads a visitor repeats while choosing; a
	 * booking is a write and a commitment.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function appointments(
		string $serviceId = '',
		string $resourceId = '',
		string $startTime = '',
		string $endTime = '',
		string $customerName = '',
		string $email = '',
		?string $phone = null,
		?string $notes = null,
	): JSONResponse {
		$authResult = $this->guard();
		if ($authResult instanceof JSONResponse) {
			return $authResult;
		}

		$validation = $this->validateAppointmentPayload(
			serviceId: $serviceId,
			resourceId: $resourceId,
			startTime: $startTime,
			endTime: $endTime,
			customerName: $customerName,
			email: $email,
			phone: $phone,
			notes: $notes,
		);
		if ($validation !== null) {
			return $validation;
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			return $this->serverError(message: 'Booking unavailable.');
		}

		// ONE write path. This endpoint used to re-implement the booking inline
		// alongside WidgetService::createAppointment(), and the two copies had
		// already drifted twice: PR #491 had to patch an `isPublic` gate in here
		// that the service always had, and this copy persisted only an
		// anonymised customerId — silently dropping customerName /
		// customerEmail / customerPhone, which the Appointment schema declares
		// (register.d/30-bookings-self-service-widget.json) and which REQ-WSW-006
		// and the REQ-BCF-003 confirmation email both need. Delegating removes
		// the duplicate rather than keeping the two in step by hand.
		//
		// The controller keeps what belongs at the API boundary: the widget-key
		// + rate-limit guard, payload validation with its user-facing REQ-WSW-008
		// messages, and the mapping from the service's result code to HTTP.
		$result = $this->widgetService->createAppointment(
			administrationId: (string)($this->settings->getSettings()['administration_id'] ?? 'adm-1'),
			payload: [
				'serviceId' => $serviceId,
				'resourceId' => $resourceId,
				'startTime' => $startTime,
				'endTime' => $endTime,
				'customerName' => $customerName,
				'customerEmail' => $email,
				'customerPhone' => ($phone ?? ''),
				'notes' => ($notes ?? ''),
			],
		);

		$code = (int)($result['code'] ?? Http::STATUS_INTERNAL_SERVER_ERROR);

		if ($code === Http::STATUS_NOT_FOUND) {
			return new JSONResponse(
				data: [
					'error' => 'service-not-found',
					'message' => 'This service is not available for online booking.',
				],
				statusCode: Http::STATUS_NOT_FOUND,
			);
		}

		if ($code === Http::STATUS_CONFLICT) {
			return new JSONResponse(
				data: [
					'error' => 'slot-unavailable',
					'message' => 'This slot was just booked. Please select another time.',
				],
				statusCode: Http::STATUS_CONFLICT,
			);
		}

		if ($code !== Http::STATUS_CREATED) {
			$this->logger->error(
				'Shillinq widget: appointment create failed',
				['error' => (string)($result['error'] ?? 'unknown')]
			);
			return $this->serverError(message: 'Booking failed. Please try again later.');
		}

		return new JSONResponse(
			data: [
				'appointmentId' => (string)($result['appointmentId'] ?? ''),
				'status' => (string)($result['status'] ?? 'pending_confirmation'),
				'confirmationMessage' => (string)($result['confirmationMessage'] ?? ''),
			],
			statusCode: Http::STATUS_CREATED,
		);

	}//end appointments()

	/**
	 * Authenticate + rate-limit the current request.
	 *
	 * Returns null when the request is allowed; a populated JSONResponse
	 * (401 / 403 / 429) when it is not.
	 *
	 * @return JSONResponse|null
	 */
	private function guard(): ?JSONResponse {
		$businessId = (string)$this->request->getParam('businessId', '');
		$authHeader = (string)$this->request->getHeader('Authorization');
		$bearer = '';
		if (str_starts_with(strtolower($authHeader), 'bearer ') === true) {
			$bearer = trim(substr($authHeader, 7));
		}

		if ($businessId === '' || $bearer === '') {
			return $this->unauthorised();
		}

		$auth = $this->auth->validateApiKey(businessId: $businessId, apiKey: $bearer);
		if (($auth['valid'] ?? false) !== true) {
			return $this->unauthorised();
		}

		$key = ($auth['key'] ?? []);
		$rateLimit = ((int)($key['rateLimit'] ?? WidgetAuthService::DEFAULT_RATE_LIMIT));
		if ($rateLimit <= 0) {
			$rateLimit = WidgetAuthService::DEFAULT_RATE_LIMIT;
		}

		$rl = $this->auth->consumeRateLimit(businessId: $businessId, limit: $rateLimit);
		if (($rl['allowed'] ?? false) !== true) {
			$response = new JSONResponse(
				data: [
					'error' => 'rate-limited',
					'message' => 'Too many requests. Please retry shortly.',
				],
				statusCode: Http::STATUS_TOO_MANY_REQUESTS,
			);
			$response->addHeader('Retry-After', (string)($rl['retryAfter'] ?? WidgetAuthService::RATE_LIMIT_WINDOW_SECONDS));
			return $response;
		}

		return null;
	}//end guard()

	/**
	 * Validate the POST /appointments payload (REQ-WSW-006).
	 *
	 * Returns null when the payload passes; a JSONResponse with a structured
	 * error otherwise.
	 *
	 * @param string $serviceId Service id.
	 * @param string $resourceId Resource id.
	 * @param string $startTime Start time ISO UTC.
	 * @param string $endTime End time ISO UTC.
	 * @param string $customerName Display name (1-255).
	 * @param string $email Email (RFC 5322).
	 * @param string|null $phone Phone (E.164, optional).
	 * @param string|null $notes Notes (≤500 chars, optional).
	 *
	 * @return JSONResponse|null
	 */
	private function validateAppointmentPayload(
		string $serviceId,
		string $resourceId,
		string $startTime,
		string $endTime,
		string $customerName,
		string $email,
		?string $phone,
		?string $notes,
	): ?JSONResponse {
		if ($serviceId === '' || $resourceId === '' || $startTime === '' || $endTime === '') {
			return $this->badRequest(message: 'serviceId, resourceId, startTime and endTime are required.');
		}

		$isoPattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';
		if ((bool)preg_match($isoPattern, $startTime) === false
			|| (bool)preg_match($isoPattern, $endTime) === false
		) {
			return $this->badRequest(message: 'startTime and endTime must be ISO 8601 UTC (YYYY-MM-DDTHH:MM:SSZ).');
		}

		$nameLength = strlen(trim($customerName));
		if ($nameLength < 1 || $nameLength > 255) {
			return $this->badRequest(message: 'customerName must be 1-255 characters.');
		}

		if ((bool)preg_match('/^[\p{L}\p{Mn}\s\'\-\.]+$/u', $customerName) === false) {
			return $this->badRequest(message: 'customerName contains invalid characters.');
		}

		if ($email === '' || (filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
			return $this->badRequest(message: 'Please enter a valid email address.');
		}

		if ($phone !== null && $phone !== '' && (bool)preg_match('/^\+?[1-9]\d{1,14}$/', $phone) === false) {
			return $this->badRequest(message: 'Phone number must be E.164 (e.g. +31612345678).');
		}

		if ($notes !== null && strlen($notes) > 500) {
			return $this->badRequest(message: 'notes must be at most 500 characters.');
		}

		return null;
	}//end validateAppointmentPayload()

	/**
	 * Build a 400 Bad Request JSON response.
	 *
	 * @param string $message Partner-safe explanation.
	 *
	 * @return JSONResponse
	 */
	private function badRequest(string $message): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'bad-request',
				'message' => $message,
			],
			statusCode: Http::STATUS_BAD_REQUEST,
		);

	}//end badRequest()

	/**
	 * Build a 401 Unauthorized JSON response.
	 *
	 * @return JSONResponse
	 */
	private function unauthorised(): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'unauthorised',
				'message' => 'Invalid or missing API key',
			],
			statusCode: Http::STATUS_UNAUTHORIZED,
		);

	}//end unauthorised()

	/**
	 * Build a 500 Internal Server Error JSON response.
	 *
	 * @param string $message Partner-safe explanation (no stack trace).
	 *
	 * @return JSONResponse
	 */
	private function serverError(string $message): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'server-error',
				'message' => $message,
			],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR,
		);

	}//end serverError()

	/**
	 * Normalise an OR object handle to a plain array.
	 *
	 * @param mixed $object OR object or array.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			/*
			 * @var array<string,mixed> $object
			 */

			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				/*
				 * @var array<string,mixed> $serialised
				 */

				return $serialised;
			}
		}

		if (is_object($object) === true) {
			return (array)$object;
		}

		return [];
	}//end toArray()
}//end class
