<?php

/**
 * Cancel Unconfirmed Appointments Job.
 *
 * Daily background job that scans for appointments still in
 * `pending_confirmation` whose `confirmationDeadline` has passed and moves
 * them to `cancelled` with reason "Confirmation deadline passed" per
 * REQ-BCF-005. The job is fail-soft: a per-record exception logs and
 * continues so a single bad row cannot wedge the whole sweep.
 *
 * @category Cron
 * @package  OCA\Shillinq\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Cron;

use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily auto-cancel sweep for unconfirmed appointments past their deadline.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-15
 */
class CancelUnconfirmedAppointmentsJob extends TimedJob
{

    /**
     * Daily interval in seconds (24h). REQ-BCF-005 requires the sweep run
     * daily — exact-cron scheduling is not supported by NC's TimedJob, so
     * the operator's scheduler picks the window.
     */
    private const INTERVAL_SECONDS = 86400;

    /**
     * Constructor.
     *
     * @param ITimeFactory       $time      Time factory for job scheduling
     *                                      and current-time reads.
     * @param SettingsService    $settings  Resolves the active OR register
     *                                      slug.
     * @param ContainerInterface $container DI container for OR ObjectService
     *                                      lookup.
     * @param LoggerInterface    $logger    Logger for diagnostics.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly SettingsService $settings,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        $this->setInterval(seconds: self::INTERVAL_SECONDS);

        // Auto-cancellation is not millisecond-critical; let the scheduler
        // pick the window so the run does not contend with online traffic.
        $this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

        // Sequential — avoid double-cancellation on overlap.
        $this->setAllowParallelRuns(allow: false);

        // Capture the factory for nowIso() — TimedJob's parent owns a
        // protected `$time` of the same type but accessing it as a
        // private member keeps this class's intent explicit.
        $this->timeFactory = $time;

    }//end __construct()

    /**
     * Time factory captured at construction. Distinct from TimedJob's
     * protected `$time` so the class is self-contained for testing.
     *
     * @var ITimeFactory
     */
    private ITimeFactory $timeFactory;

    /**
     * Sweep pending appointments and cancel those past their deadline.
     *
     * @param mixed $argument Job argument (unused).
     *
     * @return void
     *
     * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-15
     */
    protected function run($argument): void
    {
        if ($this->settings->isOpenRegisterAvailable() === false) {
            $this->logger->info('CancelUnconfirmedAppointmentsJob: OpenRegister not available, skipping.');
            return;
        }

        try {
            $now           = $this->nowIso();
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            $registerSlug  = $this->settings->getRegisterSlug();

            $pending = $objectService
                ->setRegister($registerSlug)
                ->setSchema('Appointment')
                ->findAll(
                        [
                            'filters' => ['status' => 'pending_confirmation'],
                            'limit'   => 5000,
                        ]
                        );

            $cancelled = 0;
            foreach ($pending as $record) {
                if ($this->cancelIfExpired(record: $record, objectService: $objectService, registerSlug: $registerSlug, now: $now) === true) {
                    $cancelled++;
                }
            }

            $this->logger->info(
                'CancelUnconfirmedAppointmentsJob: auto-cancelled '
                .$cancelled
                .' unconfirmed appointment(s) past the confirmation deadline.'
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'CancelUnconfirmedAppointmentsJob failed: '.$e->getMessage(),
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end run()

    /**
     * Cancel one appointment if its deadline has passed.
     *
     * @param mixed  $record        Appointment OR record.
     * @param mixed  $objectService OR ObjectService (loose-typed).
     * @param string $registerSlug  Register slug.
     * @param string $now           ISO 8601 UTC reference time.
     *
     * @return bool TRUE when the record was cancelled.
     */
    private function cancelIfExpired(mixed $record, mixed $objectService, string $registerSlug, string $now): bool
    {
        try {
            $appt     = $this->toArray(object: $record);
            $deadline = (string) ($appt['confirmationDeadline'] ?? '');
            if ($deadline === '') {
                return false;
            }

            $deadlineDt = new \DateTimeImmutable($deadline);
            $nowDt      = new \DateTimeImmutable($now);
            if ($deadlineDt > $nowDt) {
                return false;
            }

            $appt['status']          = 'cancelled';
            $appt['cancelledAt']     = $now;
            $appt['cancelledReason'] = 'Confirmation deadline passed';

            $objectService
                ->setRegister($registerSlug)
                ->setSchema('Appointment')
                ->saveObject(
                    object: $appt,
                    register: $registerSlug,
                    schema: 'Appointment',
                );

            $this->logger->info(
                'CancelUnconfirmedAppointmentsJob: cancelled '
                .((string) ($appt['appointmentId'] ?? 'unknown'))
                .' past deadline '.$deadline
            );
            return true;
        } catch (Throwable $e) {
            $this->logger->warning(
                'CancelUnconfirmedAppointmentsJob: skip record: '.$e->getMessage()
            );
            return false;
        }//end try

    }//end cancelIfExpired()

    /**
     * Current server time as ISO 8601 UTC.
     *
     * @return string
     */
    private function nowIso(): string
    {
        return $this->timeFactory->getDateTime()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

    }//end nowIso()

    /**
     * Normalise an OR record into a flat array.
     *
     * @param mixed $object OR ObjectService payload.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true) {
            if (method_exists($object, 'jsonSerialize') === true) {
                $serialised = $object->jsonSerialize();
                if (is_array($serialised) === true) {
                    return $serialised;
                }
            }

            if (method_exists($object, 'getObject') === true) {
                $inner = $object->getObject();
                if (is_array($inner) === true) {
                    return $inner;
                }
            }

            return (array) $object;
        }

        return [];

    }//end toArray()
}//end class
