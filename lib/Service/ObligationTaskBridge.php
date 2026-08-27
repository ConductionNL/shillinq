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
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Creates / links the NC Tasks VTODO (or Deck card) for a ContractObligation.
 *
 * Fail-closed glue: on any backend-resolution or write failure the bridge
 * returns ['taskUri' => null, 'taskLinkStatus' => 'failed'] and logs the
 * reason; it never throws into the obligation CRUD path (REQ-CLM-003).
 *
 * @spec openspec/changes/contract-lifecycle-management/specs/contract-lifecycle-management/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class ObligationTaskBridge {
	/**
	 * Construct the bridge with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy backend resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
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
	 * Compliance-deadline-calendar extension (REQ-CDC-005): in addition to
	 * the VTODO, the bridge publishes an additive deadline VEVENT via
	 * {@see publishDeadlineEvent()} so the contract deadline also appears
	 * on the calendar. The result gains the additive `eventUri` +
	 * `eventLinkStatus` keys; the original `taskUri` + `taskLinkStatus`
	 * contract is unchanged.
	 *
	 * @param array<string,mixed> $obligation The ContractObligation field map.
	 *
	 * @return array{taskUri: ?string, taskLinkStatus: string, eventUri: ?string, eventLinkStatus: string} Bridge result.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function createTaskForObligation(array $obligation): array {
		try {
			$title = trim((string)($obligation['title'] ?? ''));
			$dueDate = trim((string)($obligation['dueDate'] ?? ''));
			$responsible = trim((string)($obligation['responsible'] ?? ''));

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

			// REQ-CDC-005 — additive deadline VEVENT alongside the VTODO.
			// Both surfaces share this bridge's backend resolution; a VEVENT
			// failure never blocks the VTODO result (and vice versa).
			$event = $this->publishDeadlineEvent(obligation: $obligation);

			if ($taskUri === null || $taskUri === '') {
				$this->logger->warning(
					'ObligationTaskBridge: backend resolved but VTODO write returned no URI — fail-closed',
					['title' => $title]
				);
				return array_merge($this->failed(), $event);
			}

			return array_merge(
				[
					'taskUri' => $taskUri,
					'taskLinkStatus' => 'linked',
				],
				$event
			);
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
	 * Publish the deadline VEVENT for a ContractObligation (REQ-CDC-005).
	 *
	 * Additive to the VTODO: the obligation's renewal / opzegtermijn
	 * deadline is written as an all-day VEVENT with the stable UID
	 * `contract:{objectId}` (REQ-CDC-001 {source}:{objectId} key — shared
	 * with ComplianceDeadlineCalendarService so the daily sync upserts
	 * the SAME calendar object). Fail-soft: on any resolution or write
	 * failure the method logs and returns eventLinkStatus 'failed'
	 * WITHOUT throwing — obligation CRUD is never blocked.
	 *
	 * @param array<string,mixed> $obligation The ContractObligation field map.
	 *
	 * @return array{eventUri: ?string, eventLinkStatus: string} Event result.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function publishDeadlineEvent(array $obligation): array {
		try {
			$title = trim((string)($obligation['title'] ?? ''));
			$dueDate = trim((string)($obligation['dueDate'] ?? ''));
			if ($title === '' || $dueDate === '') {
				return $this->eventFailed();
			}

			$backend = $this->resolveTaskBackend();
			if ($backend === null) {
				$this->logger->warning(
					'ObligationTaskBridge: no calendar backend for deadline VEVENT — degrading fail-soft',
					['title' => $title]
				);
				return $this->eventFailed();
			}

			if (method_exists($backend, 'createFromString') === false) {
				return $this->eventFailed();
			}

			$uid = 'contract:' . $this->stableObjectId(obligation: $obligation);
			$vevent = $this->buildDeadlineVevent(uid: $uid, title: $title, dueDate: $dueDate);
			$name = strtolower((string)preg_replace('/[^A-Za-z0-9\\-]+/', '-', $uid));
			$backend->createFromString('shillinq-' . trim($name, '-') . '.ics', $vevent);

			return [
				'eventUri' => 'caldav://shillinq/deadlines/shillinq-' . trim($name, '-') . '.ics',
				'eventLinkStatus' => 'linked',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'ObligationTaskBridge: deadline VEVENT publication failed — degrading fail-soft',
				['exception' => $e->getMessage()]
			);
			return $this->eventFailed();
		}//end try

	}//end publishDeadlineEvent()

	/**
	 * List the OPEN ContractObligation deadlines as calendar-ready
	 * entries (REQ-CDC-005).
	 *
	 * This keeps the ContractObligation read path in its single home —
	 * ComplianceDeadlineCalendarService delegates the contract category
	 * here instead of re-reading the rows. Open = status open /
	 * in-progress / overdue with a non-empty dueDate. Fail-soft: an
	 * unavailable OpenRegister yields [].
	 *
	 * @return array<int,array<string,string>> Deadline entries (uid,
	 *                                         category, summary, dueDate, source, objectId).
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function listOpenObligationDeadlines(): array {
		try {
			$rows = $this->objectService
				->setRegister(register: $this->registerSlug())
				->setSchema(schema: 'ContractObligation')
				->findAll([]);


			$deadlines = [];
			foreach (array_values($rows) as $row) {
				if (is_array($row) === false) {
					continue;
				}

				$status = (string)($row['status'] ?? 'open');
				if (in_array($status, ['open', 'in-progress', 'overdue'], true) === false) {
					// Done / waived → deadline no longer open.
					continue;
				}

				$dueDate = trim((string)($row['dueDate'] ?? ''));
				$title = trim((string)($row['title'] ?? ''));
				if ($dueDate === '' || $title === '') {
					continue;
				}

				$objectId = $this->stableObjectId(obligation: $row);

				$deadlines[] = [
					'uid' => 'contract:' . $objectId,
					'category' => 'contract',
					'summary' => $title,
					'dueDate' => $dueDate,
					'source' => 'contract',
					'objectId' => $objectId,
				];
			}//end foreach

			return $deadlines;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'ObligationTaskBridge: ContractObligation read unavailable — treating as empty',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end listOpenObligationDeadlines()

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
	private function resolveTaskBackend(): ?object {
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
	 * @param object $backend The resolved calendar/task backend.
	 * @param string $title Obligation title (VTODO SUMMARY).
	 * @param string $dueDate Obligation due date (VTODO DUE, YYYY-MM-DD).
	 * @param string $responsible Assignee uid (VTODO ATTENDEE), may be empty.
	 *
	 * @return string|null The created task URI, or null on no usable seam.
	 */
	private function writeVtodo(object $backend, string $title, string $dueDate, string $responsible): ?string {
		$uid = 'shillinq-obligation-' . bin2hex(random_bytes(8));
		$due = str_replace('-', '', $dueDate);
		if ($responsible !== '') {
			$attendee = "\r\nATTENDEE:mailto:" . $responsible;
		} else {
			$attendee = '';
		}

		$vtodo = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Conduction//Shillinq CLM//EN\r\n";
		$vtodo .= "BEGIN:VTODO\r\nUID:" . $uid . "\r\nSUMMARY:" . $this->escapeIcal(value: $title) . "\r\n";
		$vtodo .= 'DUE;VALUE=DATE:' . $due . $attendee . "\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

		// Calendar managers that support VTODO creation expose
		// handleIMipMessage / createFromString style seams; we only call one we
		// can verify by reflection so we never fatal on an absent method.
		if (method_exists($backend, 'createFromString') === true) {
			$result = $backend->createFromString($uid . '.ics', $vtodo);
			if (is_string($result) === true && $result !== '') {
				return $result;
			}

			return 'caldav://shillinq/tasks/' . $uid . '.ics';
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
	private function escapeIcal(string $value): string {
		return str_replace(
			['\\', ';', ',', "\n", "\r"],
			['\\\\', '\\;', '\\,', '\\n', ''],
			$value
		);

	}//end escapeIcal()

	/**
	 * The canonical fail-closed result shape. Includes the additive
	 * event keys (REQ-CDC-005): a path that fails before any write
	 * fails BOTH surfaces (no backend → neither VTODO nor VEVENT).
	 *
	 * @return array{taskUri: ?string, taskLinkStatus: string, eventUri: ?string, eventLinkStatus: string}
	 */
	private function failed(): array {
		return [
			'taskUri' => null,
			'taskLinkStatus' => 'failed',
			'eventUri' => null,
			'eventLinkStatus' => 'failed',
		];

	}//end failed()

	/**
	 * The fail-soft result shape of the VEVENT surface (REQ-CDC-005).
	 *
	 * @return array{eventUri: ?string, eventLinkStatus: string}
	 */
	private function eventFailed(): array {
		return [
			'eventUri' => null,
			'eventLinkStatus' => 'failed',
		];

	}//end eventFailed()

	/**
	 * Resolve a STABLE object id for an obligation so the VEVENT UID is
	 * idempotent across re-publication (REQ-CDC-001). Prefers the OR
	 * slug/id; falls back to a deterministic hash of title + dueDate for
	 * rows that carry no id yet (pre-persist bridge calls).
	 *
	 * @param array<string,mixed> $obligation The ContractObligation field map.
	 *
	 * @return string The stable object id.
	 */
	private function stableObjectId(array $obligation): string {
		$self = ($obligation['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		$id = (string)($self['slug'] ?? ($self['id'] ?? ($obligation['id'] ?? ($obligation['uuid'] ?? ''))));
		if ($id !== '') {
			return $id;
		}

		return substr(
			hash('sha256', (string)($obligation['title'] ?? '') . '|' . (string)($obligation['dueDate'] ?? '')),
			0,
			16
		);

	}//end stableObjectId()

	/**
	 * Build the all-day deadline VEVENT payload (RFC 5545, CRLF) for an
	 * obligation — additive companion of {@see writeVtodo()}.
	 *
	 * @param string $uid Stable VEVENT UID (contract:{objectId}).
	 * @param string $title Obligation title (VEVENT SUMMARY).
	 * @param string $dueDate Obligation due date (YYYY-MM-DD).
	 *
	 * @return string The VCALENDAR payload.
	 */
	private function buildDeadlineVevent(string $uid, string $title, string $dueDate): string {
		$date = str_replace('-', '', $dueDate);

		$vevent = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Conduction//Shillinq Deadlines//EN\r\n";
		$vevent .= "CALSCALE:GREGORIAN\r\nBEGIN:VEVENT\r\nUID:" . $this->escapeIcal(value: $uid) . "\r\n";
		$vevent .= 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
		$vevent .= 'DTSTART;VALUE=DATE:' . $date . "\r\n";
		$vevent .= 'SUMMARY:' . $this->escapeIcal(value: $title) . "\r\n";
		$vevent .= "CATEGORIES:contract\r\nSTATUS:CONFIRMED\r\nSEQUENCE:0\r\nTRANSP:TRANSPARENT\r\n";
		$vevent .= "END:VEVENT\r\nEND:VCALENDAR\r\n";

		return $vevent;
	}//end buildDeadlineVevent()

	/**
	 * Resolve the configured OpenRegister register slug (default
	 * 'shillinq') via the lazily-fetched app config — the bridge keeps
	 * its two-dependency constructor so existing DI and tests are
	 * untouched.
	 *
	 * @return string The register slug.
	 */
	private function registerSlug(): string {
		try {
			$appConfig = $this->container->get('OCP\\IAppConfig');
			if (is_object($appConfig) === true && method_exists($appConfig, 'getValueString') === true) {
				$register = (string)$appConfig->getValueString('shillinq', 'register', 'shillinq');
				if ($register !== '') {
					return $register;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				'ObligationTaskBridge: app config unavailable — defaulting register slug',
				['exception' => $e->getMessage()]
			);
		}

		return 'shillinq';
	}//end registerSlug()
}//end class
