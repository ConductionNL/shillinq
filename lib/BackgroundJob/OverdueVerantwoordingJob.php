<?php

/**
 * Shillinq Overdue Verantwoording Background Job
 *
 * Daily background job that detects SubsidieVerantwoording accountability
 * reports that are not finalised within 90 days of grant award and fires an
 * in-app notification to the grant owner + finance officer (REQ-SUBV-010).
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily job that flags overdue accountability reports and notifies the grant owner.
 *
 * The overdue rule (REQ-SUBV-010) is factored into the pure isOverdue() method so
 * it is unit-testable without a live OpenRegister instance. run() wires it to the
 * real ObjectService (ADR-022) and the Nextcloud notification manager.
 *
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */
class OverdueVerantwoordingJob extends TimedJob
{
    /**
     * Interval between job runs: 86400 seconds = 24 hours.
     */
    private const INTERVAL_SECONDS = 86400;

    /**
     * Overdue horizon: an accountability report is overdue 90 days after award.
     */
    public const OVERDUE_DAYS = 90;

    /**
     * Non-final accountability report states that can become overdue.
     */
    private const NON_FINAL_STATES = ['draft', 'submitted', 'approved'];

    /**
     * Constructor.
     *
     * @param ITimeFactory         $time                Nextcloud time factory (injected by TimedJob).
     * @param ContainerInterface   $container           DI container for lazy ObjectService resolution.
     * @param IAppConfig           $appConfig           App config for the register slug.
     * @param INotificationManager $notificationManager Nextcloud notification manager.
     * @param LoggerInterface      $logger              The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL_SECONDS);
    }//end __construct()

    /**
     * Determine whether an accountability report is overdue.
     *
     * REQ-SUBV-010: a report in a non-final state (draft / submitted / approved)
     * is overdue once more than OVERDUE_DAYS have elapsed since its grant award
     * date. A `final` report, or one with no parseable award date, is never
     * overdue.
     *
     * @param array<string,mixed> $verantwoording The SubsidieVerantwoording object.
     * @param DateTimeImmutable   $now            The reference "now" instant.
     * @param string|null         $awardDate      The grant award date (Y-m-d); falls back to the
     *                                            verantwoording reportingPeriod start when null.
     *
     * @return bool True when the report is overdue.
     *
     * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
     */
    public function isOverdue(array $verantwoording, DateTimeImmutable $now, ?string $awardDate=null): bool
    {
        $status = (string) ($verantwoording['status'] ?? '');
        if (in_array($status, self::NON_FINAL_STATES, true) === false) {
            return false;
        }

        $reference = $awardDate;
        if ($reference === null || $reference === '') {
            $period    = (string) ($verantwoording['reportingPeriod'] ?? '');
            $reference = trim((string) (explode(' to ', $period)[0] ?? ''));
        }

        if ($reference === '') {
            return false;
        }

        try {
            $awardInstant = new DateTimeImmutable($reference);
        } catch (Throwable) {
            return false;
        }

        $ageDays = (int) $awardInstant->diff($now)->days;
        return $now > $awardInstant && $ageDays > self::OVERDUE_DAYS;
    }//end isOverdue()

    /**
     * Execute the overdue detection logic.
     *
     * @param mixed $argument Not used; required by the TimedJob interface.
     *
     * @return void
     *
     * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('Shillinq: OverdueVerantwoordingJob started');

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'Shillinq OverdueVerantwoordingJob: OpenRegister not available, skipping.',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $register = $this->resolveRegister();
        $now      = new DateTimeImmutable();
        $notified = 0;

        foreach (self::NON_FINAL_STATES as $status) {
            $records = $this->fetchByStatus(objectService: $objectService, register: $register, status: $status);

            foreach ($records as $record) {
                if ($this->isOverdue(verantwoording: $record, now: $now) === false) {
                    continue;
                }

                if ($this->dispatchOverdueNotification(record: $record) === true) {
                    $notified++;
                }
            }
        }

        $this->logger->info(
            sprintf('Shillinq OverdueVerantwoordingJob: dispatched %d overdue notifications', $notified)
        );
    }//end run()

    /**
     * Dispatch a single overdue notification for an accountability report.
     *
     * @param array<string,mixed> $record The overdue SubsidieVerantwoording object.
     *
     * @return bool True when a notification was dispatched.
     */
    private function dispatchOverdueNotification(array $record): bool
    {
        $approverUserId = (string) ($record['approverUserId'] ?? '');
        if ($approverUserId === '') {
            // No assigned approver to notify; the declarative state-change
            // notifications (ADR-031) still inform role recipients on transition.
            return false;
        }

        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($approverUserId)
                ->setDateTime(new DateTime())
                ->setObject('subsidieverantwoording', (string) ($record['verantwoordingId'] ?? ''))
                ->setSubject(
                    'subsidie_verantwoording_overdue',
                    ['grantId' => (string) ($record['grantId'] ?? '')]
                );
            $this->notificationManager->notify($notification);
            return true;
        } catch (Throwable $e) {
            $this->logger->error(
                'Shillinq OverdueVerantwoordingJob: failed to dispatch notification',
                [
                    'verantwoordingId' => ($record['verantwoordingId'] ?? ''),
                    'exception'        => $e->getMessage(),
                ]
            );
            return false;
        }//end try
    }//end dispatchOverdueNotification()

    /**
     * Fetch all SubsidieVerantwoording records in a given status, paginated.
     *
     * @param object $objectService The OpenRegister ObjectService.
     * @param string $register      The register slug.
     * @param string $status        The status to filter on.
     *
     * @return array<int,array<string,mixed>> The matching object arrays.
     */
    private function fetchByStatus(object $objectService, string $register, string $status): array
    {
        $pageSize = 100;
        $offset   = 0;
        $result   = [];

        while (true) {
            try {
                $entities = $objectService
                    ->setRegister($register)
                    ->setSchema('SubsidieVerantwoording')
                    ->findAll(
                        [
                            'filters' => ['status' => $status],
                            'limit'   => $pageSize,
                            'offset'  => $offset,
                        ]
                    );
            } catch (Throwable $e) {
                $this->logger->error(
                    'Shillinq OverdueVerantwoordingJob: failed to fetch records',
                    ['status' => $status, 'offset' => $offset, 'exception' => $e->getMessage()]
                );
                break;
            }

            $batch = [];
            foreach ($entities as $entity) {
                if (is_array($entity) === true) {
                    $batch[] = $entity;
                } else if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
                    $data = $entity->getObject();
                    if (is_array($data) === true) {
                        $batch[] = $data;
                    }
                }
            }

            $result  = array_merge($result, $batch);
            $offset += count($batch);

            if (count($batch) < $pageSize) {
                break;
            }
        }//end while

        return $result;
    }//end fetchByStatus()

    /**
     * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
     *
     * @return string The register slug.
     */
    private function resolveRegister(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($register === '') {
            return 'shillinq';
        }

        return $register;
    }//end resolveRegister()
}//end class
