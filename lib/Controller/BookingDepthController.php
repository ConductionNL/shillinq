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

use OCA\Shillinq\AppInfo\Application;
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
		parent::__construct(appName: Application::APP_ID, request: $request);

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
				register: $this->settings->getRegisterSlug(),
				schema: 'Appointment',
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

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		$seriesId = trim((string)$this->request->getParam('seriesId', ''));
		$serviceId = trim((string)$this->request->getParam('serviceId', ''));
		$resourceId = trim((string)$this->request->getParam('resourceId', ''));
		$startTime = trim((string)$this->request->getParam('startTime', ''));
		$durationMinutes = (int)$this->request->getParam('durationMinutes', 0);
		$recurrenceRule = trim((string)$this->request->getParam('recurrenceRule', ''));
		$customerId = trim((string)$this->request->getParam('customerId', ''));

		if ($administrationId === '' || $seriesId === '' || $serviceId === '' || $resourceId === ''
			|| $startTime === '' || $durationMinutes <= 0 || $recurrenceRule === ''
		) {
			return new JSONResponse(
				['error' => 'administrationId, seriesId, serviceId, resourceId, startTime, durationMinutes and recurrenceRule are required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		// Security-endpoint-guards REQ-001: the request-supplied administrationId
		// is what will be stamped on the new AppointmentSeries/Appointment
		// objects, so checking canAccess() against it here (before the object
		// exists to fetch) is the correct create-time guard — matches the
		// convention CBSSubmissionController::create() established.
		if ($this->context->canAccess($administrationId) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(['error' => 'OpenRegister unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			$resource = $this->loadResource(resourceId: $resourceId);
			if ($resource === null) {
				return new JSONResponse(['error' => 'Resource not found'], Http::STATUS_NOT_FOUND);
			}

			// Security-endpoint-guards REQ-001 hardening: canAccess() above only
			// proved the caller may act within $administrationId — it does NOT
			// prove the FETCHED $resource actually belongs to that
			// administration. Without this check a member of administration A
			// could pass administrationId=A (their own, so canAccess() passes)
			// together with a resourceId belonging to administration B and book
			// appointments against B's resource under A's label — a cross-
			// tenant IDOR on the Resource object itself.
			$resourceAdministrationId = (string)($resource['administrationId'] ?? '');
			if ($resourceAdministrationId !== $administrationId) {
				return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
			}

			$plan = $this->series->planSeries(
				seriesDef: [
					'seriesId' => $seriesId,
					'administrationId' => $administrationId,
					'serviceId' => $serviceId,
					'resourceId' => $resourceId,
					'customerId' => $customerId,
					'startTime' => $startTime,
					'durationMinutes' => $durationMinutes,
					'recurrenceRule' => $recurrenceRule,
					'openingTime' => (string)($resource['openingTime'] ?? '09:00'),
					'closingTime' => (string)($resource['closingTime'] ?? '18:00'),
					'allowOverlap' => (bool)($resource['allowOverlap'] ?? false),
					'existingAppointments' => $this->loadResourceAppointments(resourceId: $resourceId),
				]
			);

			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();

			$objectService->saveObject(
				object: [
					'administrationId' => $administrationId,
					'seriesId' => $seriesId,
					'serviceId' => $serviceId,
					'resourceId' => $resourceId,
					'customerId' => $customerId,
					'startTime' => $startTime,
					'durationMinutes' => $durationMinutes,
					'recurrenceRule' => $recurrenceRule,
					'status' => 'active',
					'generatedCount' => count($plan['generated']),
					'skippedCount' => count($plan['skipped']),
				],
				register: $registerSlug,
				schema: 'AppointmentSeries',
			);

			foreach ($plan['generated'] as $appointment) {
				$appointment['appointmentId'] = $seriesId . '-' . ((int)$appointment['recurrenceIndex']);
				$objectService->saveObject(
					object: $appointment,
					register: $registerSlug,
					schema: 'Appointment',
				);
			}

			$this->logger->info(
				'BookingDepthController: recurring series created',
				[
					'seriesId' => $seriesId,
					'generated' => count($plan['generated']),
					'skipped' => count($plan['skipped']),
				]
			);

			return new JSONResponse(
				[
					'seriesId' => $seriesId,
					'generated' => count($plan['generated']),
					'skipped' => count($plan['skipped']),
				],
				Http::STATUS_OK,
			);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error('BookingDepthController.createSeries failed', ['exception' => $e]);
			return new JSONResponse(
				[
					'message' => $this->l10n->t('Unable to create the appointment series'),
					'error' => 'appointment-series-create-failed',
				],
				Http::STATUS_BAD_REQUEST,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'BookingDepthController: series creation failed',
				['seriesId' => $seriesId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Series creation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end createSeries()

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
