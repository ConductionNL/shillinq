<?php

/**
 * Widget Service
 *
 * Domain orchestration for the public self-service booking widget API
 * (REQ-WSW-001, REQ-WSW-003, REQ-WSW-006). Exposes the public-safe service
 * catalogue, validates customer input, and creates appointments with
 * server-side double-booking prevention. Customer PII is write-only and is
 * never echoed back to the public API (design D6).
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
 * @spec openspec/specs/bookings-self-service-widget/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the public service catalogue and creates widget appointments.
 *
 * Validation helpers (validateEmail/validatePhone/validateName) are pure and
 * unit-tested in isolation; createAppointment() wires them to OpenRegister and
 * the slot cache.
 *
 * @spec openspec/specs/bookings-self-service-widget/spec.md
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; deferred pending a dedicated refactor.
 */
class WidgetService
{
    /**
     * Construct the service with lazy DI of OR's ObjectService.
     *
     * @param ContainerInterface $container   DI container for OR's ObjectService.
     * @param IAppConfig         $appConfig   App config for register-slug resolution.
     * @param SlotService        $slotService Slot availability + cache invalidation.
     * @param LoggerInterface    $logger      Nextcloud logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly SlotService $slotService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return the configured register slug, falling back to 'shillinq'.
     *
     * @return string
     */
    private function getRegisterSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($slug === '') {
            return 'shillinq';
        }

        return $slug;

    }//end getRegisterSlug()

    /**
     * Resolve OR's ObjectService, or null when OpenRegister is unavailable.
     *
     * @return object|null
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'WidgetService: ObjectService unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()

    /**
     * Normalise an OR record to an array.
     *
     * @param mixed $record The record (object or array).
     *
     * @return array<string,mixed>|null
     */
    private function toArray(mixed $record): ?array
    {
        if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {
            $record = $record->jsonSerialize();
        }

        if (is_array($record) === false) {
            return null;
        }

        return $record;

    }//end toArray()

    /**
     * List public-safe services for a business (REQ-WSW-001).
     *
     * Returns only services flagged isPublic, exposing the safe-public subset
     * (serviceSlug, name, durationMinutes, description, and price only when
     * priceVisible). The canonical Service schema is reused (bookings-service-catalog);
     * isPublic/priceVisible/resourceId are additive widget extensions. No PII and no
     * internal fields are returned (design D6).
     *
     * @param string $administrationId The owning administration (tenant scope).
     *
     * @return array<int,array<string,mixed>> The public service list.
     */
    public function listPublicServices(string $administrationId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        try {
            $services = $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema('Service')
                ->findAll(
                    [
                        'filters' => [
                            'administrationId' => $administrationId,
                            'isPublic'         => true,
                        ],
                        'limit'   => 200,
                    ]
                );
        } catch (\Throwable $e) {
            $this->logger->error(
                'WidgetService: service lookup failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $result = [];
        foreach ($services as $service) {
            $service = $this->toArray(record: $service);
            if ($service === null || (bool) ($service['isPublic'] ?? false) === false) {
                continue;
            }

            $public = [
                'serviceSlug'     => (string) ($service['@self']['slug'] ?? ($service['slug'] ?? '')),
                'name'            => (string) ($service['name'] ?? ''),
                'description'     => ($service['description'] ?? null),
                'durationMinutes' => (int) ($service['duration'] ?? 0),
            ];

            if ((bool) ($service['priceVisible'] ?? false) === true) {
                // `basePrice` is the Service schema's required price field.
                // `price` is a legacy spelling that older stored objects may
                // still carry; read it only as a fallback. Reading `price`
                // first published 0.00 for every catalogue-seeded service.
                $public['price']    = (float) ($service['basePrice'] ?? $service['price'] ?? 0);
                $public['currency'] = (string) ($service['currency'] ?? 'EUR');
            }

            $result[] = $public;
        }//end foreach

        return $result;

    }//end listPublicServices()

    /**
     * Validate an email address against RFC 5322 (REQ-WSW-006).
     *
     * @param string $email The candidate email.
     *
     * @return bool
     */
    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

    }//end validateEmail()

    /**
     * Validate an optional international phone number (REQ-WSW-006).
     *
     * Empty is valid (phone is optional). Non-empty must match E.164-ish
     * `^\+?[1-9]\d{1,14}$`.
     *
     * @param string $phone The candidate phone (may be empty).
     *
     * @return bool
     */
    public function validatePhone(string $phone): bool
    {
        if ($phone === '') {
            return true;
        }

        return preg_match('/^\+?[1-9]\d{1,14}$/', $phone) === 1;

    }//end validatePhone()

    /**
     * Validate a customer name (1-255 chars, letters/space/hyphen) (REQ-WSW-006).
     *
     * @param string $name The candidate name.
     *
     * @return bool
     */
    public function validateName(string $name): bool
    {
        $length = mb_strlen($name);
        if ($length < 1 || $length > 255) {
            return false;
        }

        return preg_match("/^[\\p{L} '\\-]+$/u", $name) === 1;

    }//end validateName()

    /**
     * Create an appointment from a widget payload (REQ-WSW-003, REQ-WSW-008).
     *
     * Validates input, re-checks slot availability against confirmed
     * appointments (server-authoritative double-booking prevention), persists
     * the appointment, and invalidates the slot cache. Returns a structured
     * result whose `code` maps to the HTTP status the controller emits.
     *
     * @param string              $administrationId The owning administration.
     * @param array<string,mixed> $payload          Widget payload (serviceSlug, startTime, customer*).
     *
     * @return array<string,mixed> ['code' => int, ...] where code is 201/400/404/409/500.
     */
    public function createAppointment(string $administrationId, array $payload): array
    {
        $serviceSlug = trim((string) ($payload['serviceSlug'] ?? ''));
        $startTime   = trim((string) ($payload['startTime'] ?? ''));
        $name        = trim((string) ($payload['customerName'] ?? ''));
        $email       = trim((string) ($payload['customerEmail'] ?? ''));
        $phone       = trim((string) ($payload['customerPhone'] ?? ''));
        $notes       = (string) ($payload['notes'] ?? '');

        // Input validation (REQ-WSW-006). Messages are i18n keys resolved client-side.
        if ($serviceSlug === '' || $startTime === '') {
            return ['code' => 400, 'error' => 'missing_fields'];
        }

        if ($this->validateName(name: $name) === false) {
            return ['code' => 400, 'error' => 'invalid_name'];
        }

        if ($this->validateEmail(email: $email) === false) {
            return ['code' => 400, 'error' => 'invalid_email'];
        }

        if ($this->validatePhone(phone: $phone) === false) {
            return ['code' => 400, 'error' => 'invalid_phone'];
        }

        if (mb_strlen($notes) > 500) {
            return ['code' => 400, 'error' => 'notes_too_long'];
        }

        try {
            $start = new DateTimeImmutable($startTime);
        } catch (\Throwable) {
            return ['code' => 400, 'error' => 'invalid_start_time'];
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['code' => 500, 'error' => 'service_unavailable'];
        }

        // Resolve the canonical Service for duration + resource (and isPublic gate).
        $service = $this->findOne(
            objectService: $objectService,
            schema: 'Service',
            slug: $serviceSlug,
            administrationId: $administrationId
        );
        if ($service === null || (bool) ($service['isPublic'] ?? false) === false) {
            return ['code' => 404, 'error' => 'service_not_found'];
        }

        $duration   = (int) ($service['duration'] ?? 0);
        $resourceId = (string) ($service['resourceId'] ?? '');
        $serviceId  = (string) ($service['serviceId'] ?? '');
        if ($duration <= 0 || $resourceId === '') {
            return ['code' => 404, 'error' => 'service_not_bookable'];
        }

        $startUtc = $start->setTimezone(new DateTimeZone('UTC'));
        $endUtc   = $startUtc->modify('+'.$duration.' minutes');

        // Server-authoritative double-booking check (REQ-WSW-003 race scenario).
        $conflict = $this->hasConflict(
            objectService: $objectService,
            resourceId: $resourceId,
            administrationId: $administrationId,
            startUtc: $startUtc,
            endUtc: $endUtc
        );
        if ($conflict === true) {
            return ['code' => 409, 'error' => 'slot_unavailable'];
        }

        $phoneValue = null;
        if ($phone !== '') {
            $phoneValue = $phone;
        }

        $notesValue = null;
        if ($notes !== '') {
            $notesValue = $notes;
        }

        // Canonical Appointment (bookings-create-appointment). A customer is a
        // Nextcloud contact entity: the public widget has no authenticated user,
        // so a deterministic guest customerId is derived from the captured email
        // and the captured contact details are stored write-only (design D6).
        $appointment = [
            'administrationId' => $administrationId,
            'appointmentId'    => 'wsw-'.bin2hex(random_bytes(8)),
            'serviceId'        => $serviceId,
            'resourceId'       => $resourceId,
            'customerId'       => 'guest-'.substr(hash('sha256', strtolower($email)), 0, 16),
            'startTime'        => $startUtc->format('Y-m-d\TH:i:s\Z'),
            'endTime'          => $endUtc->format('Y-m-d\TH:i:s\Z'),
            'status'           => 'confirmed',
            'customerName'     => $name,
            'customerEmail'    => $email,
            'customerPhone'    => $phoneValue,
            'notes'            => $notesValue,
            'source'           => 'widget',
        ];

        try {
            $saved = $objectService->saveObject(
                object: $appointment,
                register: $this->getRegisterSlug(),
                schema: 'Appointment',
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'WidgetService: appointment save failed',
                ['exception' => $e->getMessage(), 'administrationId' => $administrationId]
            );
            return ['code' => 500, 'error' => 'create_failed'];
        }

        $saved = $this->toArray(record: $saved);

        // Invalidate cached slots for the affected date so the next read updates.
        $this->slotService->invalidate(
            serviceId: $serviceId,
            resourceId: $resourceId,
            date: $startUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d')
        );

        // Design D6: never echo customer PII back to the public API.
        return [
            'code'                => 201,
            'appointmentId'       => (string) ($saved['@self']['slug'] ?? ($saved['@self']['id'] ?? '')),
            'status'              => 'confirmed',
            'startTime'           => $startUtc->format('Y-m-d\TH:i:s\Z'),
            'endTime'             => $endUtc->format('Y-m-d\TH:i:s\Z'),
            'confirmationMessage' => 'appointment_confirmed',
        ];

    }//end createAppointment()

    /**
     * Whether a confirmed appointment overlaps the candidate interval.
     *
     * @param object            $objectService    OR ObjectService.
     * @param string            $resourceId       The resource.
     * @param string            $administrationId Tenant scope.
     * @param DateTimeImmutable $startUtc         Candidate start (UTC).
     * @param DateTimeImmutable $endUtc           Candidate end (UTC).
     *
     * @return bool True when a conflict exists (or the check could not be made safely).
     */
    private function hasConflict(
        object $objectService,
        string $resourceId,
        string $administrationId,
        DateTimeImmutable $startUtc,
        DateTimeImmutable $endUtc
    ): bool {
        try {
            $appointments = $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema('Appointment')
                ->findAll(
                    [
                        'filters' => [
                            'resourceId'       => $resourceId,
                            'administrationId' => $administrationId,
                            'status'           => 'confirmed',
                        ],
                        'limit'   => 500,
                    ]
                );
        } catch (\Throwable $e) {
            // Fail-closed: deny the booking rather than risk a double-book.
            $this->logger->error(
                'WidgetService: conflict check failed',
                ['exception' => $e->getMessage()]
            );
            return true;
        }//end try

        $candStart = $startUtc->getTimestamp();
        $candEnd   = $endUtc->getTimestamp();

        foreach ($appointments as $appointment) {
            $appointment = $this->toArray(record: $appointment);
            if ($appointment === null) {
                continue;
            }

            try {
                $existStart = (new DateTimeImmutable((string) ($appointment['startTime'] ?? '')))->getTimestamp();
                $existEnd   = (new DateTimeImmutable((string) ($appointment['endTime'] ?? '')))->getTimestamp();
            } catch (\Throwable) {
                continue;
            }

            if ($candStart < $existEnd && $existStart < $candEnd) {
                return true;
            }
        }//end foreach

        return false;

    }//end hasConflict()

    /**
     * Find a single object by slug within an administration.
     *
     * @param object $objectService    OR ObjectService.
     * @param string $schema           The schema slug.
     * @param string $slug             The object slug.
     * @param string $administrationId Tenant scope.
     *
     * @return array<string,mixed>|null
     */
    private function findOne(object $objectService, string $schema, string $slug, string $administrationId): ?array
    {
        try {
            $matches = $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema($schema)
                ->findAll(
                    [
                        'filters' => ['administrationId' => $administrationId],
                        'limit'   => 200,
                    ]
                );
        } catch (\Throwable $e) {
            $this->logger->error(
                'WidgetService: '.$schema.' lookup failed',
                ['exception' => $e->getMessage()]
            );
            return null;
        }

        foreach ($matches as $match) {
            $match = $this->toArray(record: $match);
            if ($match === null) {
                continue;
            }

            $matchSlug = (string) ($match['@self']['slug'] ?? ($match['slug'] ?? ''));
            if ($matchSlug === $slug) {
                return $match;
            }
        }

        return null;

    }//end findOne()
}//end class
