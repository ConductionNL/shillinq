<?php

/**
 * Booking Depth Controller
 *
 * Reachable operator surface for the two bookings-depth capabilities:
 *
 *  1. POST /api/v1/appointments/{appointmentId}/no-show — records a no-show and
 *     CAPTURES the defined `noShowFee` through the DepositPayment payment-provider
 *     rails via {@see NoShowFeeCaptureService}. Closes the no-show-fee-capture gap
 *     (the fee was spec'd by bookings-cancellation-rules but had no capture path).
 *
 *  2. POST /api/v1/appointment-series — generates a recurring appointment series
 *     from an RRULE-style rule via {@see RecurringSeriesService}, persisting the
 *     AppointmentSeries and each generated individual Appointment. Occurrences that
 *     violate the resource availability / conflict rules (SlotService) are skipped.
 *     Closes the recurring-appointment-series gap (deferred to Tier-2 in
 *     bookings/spec.md).
 *
 * Both endpoints run as #[NoAdminRequired] operator actions and additionally
 * enforce a per-administration authorisation guard (AdministrationContextService)
 * so an authenticated user cannot act on another administration's bookings
 * (ADR-005 / OWASP A01:2021 — no IDOR). Reads + writes go through OpenRegister's
 * ObjectService (ADR-022 — no app tables, no SQL).
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
 * @spec openspec/specs/bookings-recurring-series/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\NoShowFeeCaptureService;
use OCA\Shillinq\Service\RecurringSeriesService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Operator endpoints for no-show-fee capture and recurring-series generation.
 *
 * @spec openspec/specs/bookings-recurring-series/spec.md
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pre-existing debt (issue
 *     #506): inherent branch complexity in this domain logic; deferred
 *     pending a dedicated refactor.
 */
class BookingDepthController extends Controller {
	/**
	 * Construct the controller.
	 *
	 * @param \OCP\IRequest $request The request.
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settings Shillinq settings (register slug, OR availability).
	 * @param AdministrationContextService $context Auth + per-administration guard.
	 * @param NoShowFeeCaptureService $noShowFee No-show fee capture seam.
	 * @param RecurringSeriesService $series Recurring series expander/planner.
	 * @param LoggerInterface $logger Logger.
	 * @param IL10N $l10n Translation service for ADR-050 error-response messages.
	 */
	public function __construct(
		\OCP\IRequest $request,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly AdministrationContextService $context,
		private readonly NoShowFeeCaptureService $noShowFee,
		private readonly RecurringSeriesService $series,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * POST /api/v1/appointments/{appointmentId}/no-show
	 *
	 * Record a no-show and capture the defined no-show fee via the payment
	 * provider. When no fee is defined the appointment is still marked (status
	 * `no_show`) but no charge is dispatched.
	 *
	 * @param string $appointmentId Appointment id (path parameter).
	 *
	 * @return JSONResponse 200 with `{charged, feeCents, noShowFeeStatus,
	 *                      paymentIntentId}`; 401 anonymous; 403 not authorised;
	 *                      404 missing; 503 when OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/bookings-cancellation-rules/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function captureNoShow(string $appointmentId = ''): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$appointmentId = trim($appointmentId);
		if ($appointmentId === '') {
			return new JSONResponse(['error' => 'appointmentId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(['error' => 'OpenRegister unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			$appointment = $this->loadAppointment(appointmentId: $appointmentId);
			if ($appointment === null) {
				return new JSONResponse(['error' => 'Appointment not found'], Http::STATUS_NOT_FOUND);
			}

			// Security-endpoint-guards REQ-001: per-object tenant guard checked
			// against the FETCHED target's own administrationId (never the
			// caller-supplied appointmentId's implied scope).
			if ($this->context->canAccess((string)($appointment['administrationId'] ?? '')) === false) {
				return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
			}

			$outcome = $this->noShowFee->captureNoShowFee(appointment: $appointment);
			$appointment = $outcome['appointment'];

			// Mark the appointment as a no-show alongside the fee bookkeeping.
			$appointment['status'] = 'no_show';
			$appointment['cancelledReason'] = 'no_show';

			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$objectService->saveObject(
				object: $appointment,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $this->settings->getRegisterSlug(), schema: 'Appointment')
				),
			);

			return new JSONResponse(
				[
					'charged' => $outcome['charged'],
					'feeCents' => $outcome['feeCents'],
					'noShowFeeStatus' => (string)($appointment['noShowFeeStatus'] ?? NoShowFeeCaptureService::STATUS_NONE),
					'paymentIntentId' => (string)($appointment['noShowFeePaymentIntentId'] ?? ''),
				],
				Http::STATUS_OK,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'BookingDepthController: no-show capture failed',
				['appointmentId' => $appointmentId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'No-show capture failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end captureNoShow()

	/**
	 * POST /api/v1/appointment-series
	 *
	 * Generate a recurring appointment series. Body: seriesId, serviceId,
	 * resourceId, administrationId, customerId (optional), startTime (ISO UTC),
	 * durationMinutes, recurrenceRule (RRULE). Persists the AppointmentSeries and
	 * every generated Appointment; returns the generated/skipped counts.
	 *
	 * @return JSONResponse 200 with `{seriesId, generated, skipped}`; 400 on bad
	 *                      input; 401 anonymous; 403 not authorised; 404 resource
	 *                      missing; 503 when OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/bookings-recurring-series/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function createSeries(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$input = $this->seriesInput();
		if ($this->seriesInputComplete(input: $input) === false) {
			$required = 'administrationId, seriesId, serviceId, resourceId, startTime, durationMinutes and recurrenceRule are required';
			return new JSONResponse(['error' => $required], Http::STATUS_BAD_REQUEST);
		}

		// Security-endpoint-guards REQ-001: the request-supplied administrationId
		// is what will be stamped on the new AppointmentSeries/Appointment
		// objects, so checking canAccess() against it here (before the object
		// exists to fetch) is the correct create-time guard.
		if ($this->context->canAccess($input['administrationId']) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $this->createSeriesWithin(input: $input);
	}//end createSeries()

	/**
	 * The series request: trimmed strings plus the integer duration.
	 *
	 * @return array<string, string|int> The request fields.
	 */
	private function seriesInput(): array {
		$input = [];
		foreach (['administrationId', 'seriesId', 'serviceId', 'resourceId', 'startTime', 'recurrenceRule', 'customerId'] as $name) {
			$input[$name] = trim((string)$this->request->getParam($name, ''));
		}

		$input['durationMinutes'] = (int)$this->request->getParam('durationMinutes', 0);

		return $input;
	}//end seriesInput()

	/**
	 * Whether every required series field is present and the duration positive.
	 *
	 * @param array<string, string|int> $input The request fields.
	 *
	 * @return bool
	 */
	private function seriesInputComplete(array $input): bool {
		$required = array_intersect_key($input, array_flip(['administrationId', 'seriesId', 'serviceId', 'resourceId', 'startTime', 'recurrenceRule']));
		return in_array('', $required, true) === false && $input['durationMinutes'] > 0;
	}//end seriesInputComplete()

	/**
	 * Create the series once the caller may act within its administration:
	 * 503 without OpenRegister, 400 when the planner rejects the request, 500
	 * on any other failure.
	 *
	 * @param array<string, string|int> $input The request fields.
	 *
	 * @return JSONResponse
	 */
	private function createSeriesWithin(array $input): JSONResponse {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(['error' => 'OpenRegister unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			return $this->createSeriesOnResource(input: $input);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error('BookingDepthController.createSeries failed', ['exception' => $e]);
			$message = $this->l10n->t('Unable to create the appointment series');
			return new JSONResponse(['message' => $message, 'error' => 'appointment-series-create-failed'], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return $this->seriesCreationFailed(seriesId: $input['seriesId'], error: $e);
		}//end try

	}//end createSeriesWithin()

	/**
	 * Log an unexpected series failure and answer 500 without its detail.
	 *
	 * @param string $seriesId The series being created.
	 * @param Throwable $error What went wrong.
	 *
	 * @return JSONResponse
	 */
	private function seriesCreationFailed(string $seriesId, Throwable $error): JSONResponse {
		$this->logger->error(
			'BookingDepthController: series creation failed',
			['seriesId' => $seriesId, 'exception' => $error->getMessage()]
		);
		return new JSONResponse(['error' => 'Series creation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end seriesCreationFailed()

	/**
	 * Plan and store the series on its resource; 404 when the resource is
	 * missing, 403 when it belongs to another administration.
	 *
	 * Security-endpoint-guards REQ-001 hardening: canAccess() only proved the
	 * caller may act within the request's administration, not that the
	 * fetched resource belongs to it. Without the ownership check a member of
	 * administration A could book against administration B's resource under
	 * A's label, a cross-tenant IDOR on the Resource object.
	 *
	 * @param array<string, string|int> $input The request fields.
	 *
	 * @return JSONResponse
	 */
	private function createSeriesOnResource(array $input): JSONResponse {
		$resource = $this->loadResource(resourceId: $input['resourceId']);
		if ($resource === null) {
			return new JSONResponse(['error' => 'Resource not found'], Http::STATUS_NOT_FOUND);
		}

		if ((string)($resource['administrationId'] ?? '') !== $input['administrationId']) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$plan = $this->series->planSeries(seriesDef: $this->seriesDefinition(input: $input, resource: $resource));
		$this->storeSeries(input: $input, plan: $plan);

		$counts = ['generated' => count($plan['generated']), 'skipped' => count($plan['skipped'])];
		$this->logger->info('BookingDepthController: recurring series created', ['seriesId' => $input['seriesId']] + $counts);

		return new JSONResponse(['seriesId' => $input['seriesId']] + $counts, Http::STATUS_OK);
	}//end createSeriesOnResource()

	/**
	 * The definition the series planner works from: the request, the
	 * resource's opening hours and its existing appointments.
	 *
	 * @param array<string, string|int> $input The request fields.
	 * @param array<string, mixed> $resource The Resource row.
	 *
	 * @return array<string, mixed>
	 */
	private function seriesDefinition(array $input, array $resource): array {
		return $this->seriesFields(input: $input, order: ['seriesId', 'administrationId']) + [
			'openingTime' => (string)($resource['openingTime'] ?? '09:00'),
			'closingTime' => (string)($resource['closingTime'] ?? '18:00'),
			'allowOverlap' => (bool)($resource['allowOverlap'] ?? false),
			'existingAppointments' => $this->loadResourceAppointments(resourceId: $input['resourceId']),
		];
	}//end seriesDefinition()

	/**
	 * The request fields of a series object, with the two leading keys in the
	 * given order.
	 *
	 * @param array<string, string|int> $input The request fields.
	 * @param array<int, string> $order The leading keys.
	 *
	 * @return array<string, string|int>
	 */
	private function seriesFields(array $input, array $order): array {
		$fields = [];
		foreach ([...$order, 'serviceId', 'resourceId', 'customerId', 'startTime', 'durationMinutes', 'recurrenceRule'] as $key) {
			$fields[$key] = $input[$key];
		}

		return $fields;
	}//end seriesFields()

	/**
	 * Save the AppointmentSeries and every generated Appointment.
	 *
	 * @param array<string, string|int> $input The request fields.
	 * @param array{generated: array<int, array<string, mixed>>, skipped: array<int, mixed>} $plan The planned series.
	 *
	 * @return void
	 */
	private function storeSeries(array $input, array $plan): void {
		$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		$registerSlug  = $this->settings->getRegisterSlug();
		$series        = $this->seriesFields(input: $input, order: ['administrationId', 'seriesId']) + [
			'status' => 'active',
			'generatedCount' => count($plan['generated']),
			'skippedCount' => count($plan['skipped']),
		];
		$objectService->saveObject(
			object: $series,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $registerSlug, schema: 'AppointmentSeries')
			)
		);

		foreach ($plan['generated'] as $appointment) {
			$appointment['appointmentId'] = $input['seriesId'] . '-' . ((int)$appointment['recurrenceIndex']);
			$objectService->saveObject(
				object: $appointment,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $registerSlug, schema: 'Appointment')
				)
			);
		}
	}//end storeSeries()

	/**
	 * Load a single Appointment by its logical appointmentId.
	 *
	 * @param string $appointmentId Appointment id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function loadAppointment(string $appointmentId): ?array {
		$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		$records = $objectService
			->setRegister($this->settings->getRegisterSlug())
			->setSchema('Appointment')
			->findAll(['filters' => ['appointmentId' => $appointmentId], 'limit' => 1]);

		foreach ($records as $record) {
			return $this->toArray(object: $record);
		}

		return null;
	}//end loadAppointment()

	/**
	 * Load a single Resource by its logical resourceId.
	 *
	 * @param string $resourceId Resource id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function loadResource(string $resourceId): ?array {
		$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		$records = $objectService
			->setRegister($this->settings->getRegisterSlug())
			->setSchema('Resource')
			->findAll(['filters' => ['resourceId' => $resourceId], 'limit' => 1]);

		foreach ($records as $record) {
			return $this->toArray(object: $record);
		}

		return null;
	}//end loadResource()

	/**
	 * Load non-cancelled appointment windows for a resource (for conflict reuse).
	 *
	 * @param string $resourceId Resource id.
	 *
	 * @return array<int,array{startTime:string,endTime:string}>
	 */
	private function loadResourceAppointments(string $resourceId): array {
		$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		$records = $objectService
			->setRegister($this->settings->getRegisterSlug())
			->setSchema('Appointment')
			->findAll(['filters' => ['resourceId' => $resourceId], 'limit' => 1000]);

		$out = [];
		foreach ($records as $record) {
			$row = $this->toArray(object: $record);
			if ((string)($row['status'] ?? '') === 'cancelled') {
				continue;
			}

			$start = (string)($row['startTime'] ?? '');
			$end = (string)($row['endTime'] ?? '');
			if ($start === '' || $end === '') {
				continue;
			}

			$out[] = ['startTime' => $start, 'endTime' => $end];
		}

		return $out;
	}//end loadResourceAppointments()

	/**
	 * Normalise an OR object handle to a plain array.
	 *
	 * @param mixed $object Array or OR entity.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true) {
			return (array)$object;
		}

		return [];
	}//end toArray()
}//end class
