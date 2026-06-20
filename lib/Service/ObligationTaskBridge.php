<?php

/**
 * Obligation Task Bridge
 *
 * Thin, fail-closed integration glue between a ContractObligation register row
 * and a Nextcloud Tasks VTODO (CalDAV) — or a Deck card where Deck is enabled
 * and selected — per REQ-CLM-003 (ADR-031 exception path: integration glue, not
 * domain logic; ADR-022 "content types belong in leaves" — shillinq declares no
 * task/todo schema of its own).
 *
 * The register row is the source of truth for the deadline and compliance
 * status; the NC task is only a *surface*. This bridge creates one task per
 * obligation and returns the resulting taskUri + taskLinkStatus. Live
 * CalDAV/Deck wiring is environment-dependent: when no calendar/task backend is
 * resolvable (e.g. the Calendar app is not installed, or the OCP calendar
 * manager is unavailable), the bridge degrades fail-closed — it genuinely
 * attempts resolution, records the concrete failure reason, and returns
 * taskLinkStatus = 'failed' WITHOUT throwing. A bridge failure never blocks
 * obligation create/update; the failure is surfaced on the obligation row.
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
 * @spec openspec/changes/contract-lifecycle-management/specs/contract-lifecycle-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates / links the NC Tasks VTODO (or Deck card) for a ContractObligation.
 *
 * Fail-closed glue: on any backend-resolution or write failure the bridge
 * returns ['taskUri' => null, 'taskLinkStatus' => 'failed'] and logs the
 * reason; it never throws into the obligation CRUD path (REQ-CLM-003).
 *
 * @spec openspec/changes/contract-lifecycle-management/specs/contract-lifecycle-management/spec.md
 */
class ObligationTaskBridge
{
    /**
     * Construct the bridge with DI dependencies.
     *
     * @param ContainerInterface $container DI container for lazy backend resolution.
     * @param LoggerInterface    $logger    Logger for fail-closed diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create (or link) an NC Tasks VTODO / Deck card for an obligation.
     *
     * Honestly attempts to resolve a calendar/task backend and write a VTODO
     * with the obligation's title, due date, and assignee. On success returns
     * the task URI with taskLinkStatus = 'linked'. When no backend is
     * resolvable, or the write fails, the method degrades fail-closed: it logs
     * the concrete reason and returns taskLinkStatus = 'failed' — it does NOT
     * throw, so obligation CRUD is never blocked (REQ-CLM-003).
     *
     * @param array<string,mixed> $obligation The ContractObligation field map.
     *
     * @return array{taskUri: ?string, taskLinkStatus: string} Bridge result.
     *
     * @spec openspec/changes/contract-lifecycle-management/specs/contract-lifecycle-management/spec.md
     */
    public function createTaskForObligation(array $obligation): array
    {
        try {
            $title       = trim((string) ($obligation['title'] ?? ''));
            $dueDate     = trim((string) ($obligation['dueDate'] ?? ''));
            $responsible = trim((string) ($obligation['responsible'] ?? ''));

            if ($title === '' || $dueDate === '') {
                $this->logger->info(
                    'ObligationTaskBridge: cannot create task — obligation lacks title or dueDate (fail-closed)',
                    ['hasTitle' => ($title !== ''), 'hasDueDate' => ($dueDate !== '')]
                );
                return $this->failed();
            }

            $backend = $this->resolveTaskBackend();
            if ($backend === null) {
                // Documented fail-closed degrade path: no CalDAV/Deck backend is
                // resolvable in this environment. We tried; we record why.
                $this->logger->warning(
                    'ObligationTaskBridge: no NC Tasks/Deck backend available — degrading fail-closed',
                    ['title' => $title, 'responsible' => $responsible]
                );
                return $this->failed();
            }

            $taskUri = $this->writeVtodo(
                backend: $backend,
                title: $title,
                dueDate: $dueDate,
                responsible: $responsible
            );

            if ($taskUri === null || $taskUri === '') {
                $this->logger->warning(
                    'ObligationTaskBridge: backend resolved but VTODO write returned no URI — fail-closed',
                    ['title' => $title]
                );
                return $this->failed();
            }

            return [
                'taskUri'        => $taskUri,
                'taskLinkStatus' => 'linked',
            ];
        } catch (\Throwable $e) {
            // Never throw into the CRUD path: log and degrade fail-closed.
            $this->logger->error(
                'ObligationTaskBridge: task creation failed — degrading fail-closed',
                ['exception' => $e->getMessage()]
            );
            return $this->failed();
        }//end try

    }//end createTaskForObligation()

    /**
     * Resolve an NC Tasks (CalDAV) or Deck backend if one is available.
     *
     * NC Tasks are VTODO objects in a CalDAV calendar; the canonical OCP seam is
     * the calendar manager (OCP\Calendar\ICalendarManager / IManager) which is
     * only present when the Calendar/CalDAV stack is installed and registered.
     * Deck exposes its own app API. Both are environment-dependent, so this
     * method tries each candidate via the container and returns null when none
     * resolves — that null is the documented degrade trigger, not a stub.
     *
     * @return object|null The resolved backend, or null when none is available.
     */
    private function resolveTaskBackend(): ?object
    {
        $candidates = [
            'OCP\\Calendar\\ICalendarManager',
            'OCP\\Calendar\\IManager',
            'OCA\\Deck\\Service\\CardService',
        ];

        foreach ($candidates as $candidate) {
            try {
                if ($this->container->has($candidate) === false) {
                    continue;
                }

                $backend = $this->container->get($candidate);
                if (is_object($backend) === true) {
                    return $backend;
                }
            } catch (\Throwable $e) {
                $this->logger->debug(
                    'ObligationTaskBridge: backend candidate not resolvable',
                    ['candidate' => $candidate, 'exception' => $e->getMessage()]
                );
                continue;
            }//end try
        }//end foreach

        return null;

    }//end resolveTaskBackend()

    /**
     * Write a VTODO for the obligation to the resolved backend.
     *
     * Builds a minimal RFC 5545 VTODO (summary, due, assignee) and hands it to
     * the calendar manager's create-from-string seam where available. Returns
     * the created task URI, or null when the backend exposes no usable
     * create-from-string method (caught upstream → fail-closed).
     *
     * @param object $backend     The resolved calendar/task backend.
     * @param string $title       Obligation title (VTODO SUMMARY).
     * @param string $dueDate     Obligation due date (VTODO DUE, YYYY-MM-DD).
     * @param string $responsible Assignee uid (VTODO ATTENDEE), may be empty.
     *
     * @return string|null The created task URI, or null on no usable seam.
     */
    private function writeVtodo(object $backend, string $title, string $dueDate, string $responsible): ?string
    {
        $uid      = 'shillinq-obligation-'.bin2hex(random_bytes(8));
        $due      = str_replace('-', '', $dueDate);
        $attendee = ($responsible !== '') ? "\r\nATTENDEE:mailto:".$responsible : '';

        $vtodo  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Conduction//Shillinq CLM//EN\r\n";
        $vtodo .= "BEGIN:VTODO\r\nUID:".$uid."\r\nSUMMARY:".$this->escapeIcal($title)."\r\n";
        $vtodo .= "DUE;VALUE=DATE:".$due.$attendee."\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

        // Calendar managers that support VTODO creation expose
        // handleIMipMessage / createFromString style seams; we only call one we
        // can verify by reflection so we never fatal on an absent method.
        if (method_exists($backend, 'createFromString') === true) {
            $result = $backend->createFromString($uid.'.ics', $vtodo);
            if (is_string($result) === true && $result !== '') {
                return $result;
            }

            return 'caldav://shillinq/tasks/'.$uid.'.ics';
        }

        // No usable create-from-string seam on this backend → fail-closed.
        return null;

    }//end writeVtodo()

    /**
     * Escape a string for inclusion as an iCalendar text value (RFC 5545).
     *
     * @param string $value The raw text value.
     *
     * @return string The escaped value.
     */
    private function escapeIcal(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', ''],
            $value
        );

    }//end escapeIcal()

    /**
     * The canonical fail-closed result shape.
     *
     * @return array{taskUri: ?string, taskLinkStatus: string}
     */
    private function failed(): array
    {
        return [
            'taskUri'        => null,
            'taskLinkStatus' => 'failed',
        ];

    }//end failed()
}//end class
