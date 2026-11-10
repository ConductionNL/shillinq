<?php

/**
 * Compliance Deadline Calendar Service
 *
 * Publishes Shillinq's compliance and operational deadlines as RFC 5545
 * VEVENTs into a Nextcloud calendar per REQ-CDC-001..004 and REQ-CDC-006,
 * and raises NC Notifications ahead of each deadline per REQ-CDC-007.
 *
 * Deadline sources (existing, read-only — this service NEVER stores a
 * parallel deadline, REQ-CDC-002):
 *  - VATReturn   — BTW filing deadline derived from the period endDate
 *                  (last day of the month following the period end — the
 *                  standard NL BTW-aangifte rule). Open while statusCode
 *                  is 'draft'; removed once submitted/verified/filed.
 *  - IcpOpgaaf   — ICP-opgaaf deadline derived from the `period` field
 *                  ('YYYY-Qn' / 'YYYY-MM'), same following-month rule.
 *                  Open while status is draft/finalized/rejected.
 *  - TaxDeadline — VPB deadlines carry their own deadlineDate (the period
 *                  data of the VPB capability). Open while 'pending'.
 *  - PaymentRun  — executionDate. Scheduled while draft/approved; removed
 *                  once exported/reconciled (executed) per REQ-CDC-003.
 *  - ARInvoice   — dueDate, per-user OPT-IN category (default off,
 *                  REQ-CDC-004). Open while issued/overdue/disputed;
 *                  removed on paid/written-off.
 *  - ContractObligation — delegated to {@see ObligationTaskBridge}
 *                  (REQ-CDC-005: one home for the contract path; this
 *                  service does NOT re-read ContractObligation rows).
 *
 * Calendar seam: OCP\Calendar\IManager is resolved lazily via the DI
 * container (the same backend-resolution approach proven in
 * {@see ObligationTaskBridge}). The dedicated app calendar is the user
 * calendar with URI 'shillinq-deadlines' when present; because OCP exposes
 * no public create-calendar API, publication falls back to the user's
 * first writable (ICreateFromString) calendar — the documented
 * design.md fallback ("Fallback to the ObligationTaskBridge seam if a
 * dedicated calendar cannot be created"). When nothing resolves the
 * publication logs the concrete reason and returns status 'failed'
 * WITHOUT throwing (REQ-CDC-001 fail-soft — mirrors REQ-CLM-003).
 *
 * Removal semantics: OCP's public calendar surface exposes write-only
 * upsert (ICreateFromString::createFromString); there is no public delete
 * seam. A deadline that leaves the open set (filing submitted, run
 * executed, invoice paid, category toggled off) is therefore overwritten
 * in place with STATUS:CANCELLED + a bumped SEQUENCE — calendar clients
 * drop/strike cancelled events, which is the strongest removal available
 * through the public API (documented deviation).
 *
 * VEVENT summaries use the official NL fiscal proper nouns
 * ('BTW-aangifte', 'ICP-opgaaf', 'Betaalrun', …) exactly as the
 * REQ-CDC-002 scenario prescribes ("BTW-aangifte 2026-Q1"); they are data
 * labels (obligation + period), not prose, so they are built without a
 * request-scoped l10n. Notification prose IS localised in
 * {@see \OCA\Shillinq\Notification\DeadlineReminderNotifier}.
 *
 * ADR-031: calendar/notification publication is external integration and
 * scheduled bulk work — allowed imperative surfaces (see design.md).
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
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Publishes compliance deadlines as calendar VEVENTs + NC notifications.
 *
 * All public entry points are fail-soft: a missing calendar backend, an
 * unresolvable OpenRegister, or a write failure is logged and reported as
 * a 'failed' status — it never throws into a CRUD path or crashes cron
 * (REQ-CDC-001).
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * Pre-existing debt (issue #506): this service enumerates every compliance
 * deadline family the app tracks, so its size/complexity scale with the
 * domain's regulatory surface; splitting is out of scope for a mechanical
 * phpcs/phpmd cleanup. Deferred to a follow-up.
 */
class ComplianceDeadlineCalendarService {

	/**
	 * The RFC 5545 line-ending sequence (CRLF, §3.1) — same convention as
	 * {@see IcsService}.
	 */
	private const CRLF = "\r\n";

	/**
	 * PRODID for VEVENTs published by this service.
	 */
	private const PRODID = '-//Conduction//Shillinq Deadlines//EN';

	/**
	 * The dedicated app-calendar URI looked up on the user's principal.
	 */
	public const CALENDAR_URI = 'shillinq-deadlines';

	/**
	 * Deadline category: BTW / ICP / VPB filing deadlines (REQ-CDC-002).
	 */
	public const CATEGORY_FILING = 'filing';

	/**
	 * Deadline category: payment-run execution dates (REQ-CDC-003).
	 */
	public const CATEGORY_PAYMENT_RUN = 'payment-run';

	/**
	 * Deadline category: AR invoice due dates — per-user opt-in (REQ-CDC-004).
	 */
	public const CATEGORY_AR_DUE = 'ar-due';

	/**
	 * Deadline category: contract renewal / opzegtermijn (REQ-CDC-005).
	 */
	public const CATEGORY_CONTRACT = 'contract';

	/**
	 * All known categories in publication order.
	 *
	 * @var array<int,string>
	 */
	public const CATEGORIES = [
		self::CATEGORY_FILING,
		self::CATEGORY_PAYMENT_RUN,
		self::CATEGORY_AR_DUE,
		self::CATEGORY_CONTRACT,
	];

	/**
	 * Per-user preference defaults: filing / payment-run / contract ON,
	 * AR due dates OFF (REQ-CDC-004 default-off; design.md Seed Data).
	 *
	 * @var array<string,string>
	 */
	private const CATEGORY_DEFAULTS = [
		self::CATEGORY_FILING => '1',
		self::CATEGORY_PAYMENT_RUN => '1',
		self::CATEGORY_AR_DUE => '0',
		self::CATEGORY_CONTRACT => '1',
	];

	/**
	 * Default reminder lead times in days per category (design.md Open
	 * Question: 10 for filing, 7 for payment/contract/AR — tunable
	 * per user).
	 *
	 * @var array<string,int>
	 */
	private const LEAD_DAYS_DEFAULTS = [
		self::CATEGORY_FILING => 10,
		self::CATEGORY_PAYMENT_RUN => 7,
		self::CATEGORY_AR_DUE => 7,
		self::CATEGORY_CONTRACT => 7,
	];

	/**
	 * User-preference key prefix for the category toggles.
	 */
	private const PREF_CATEGORY_PREFIX = 'deadline_calendar_';

	/**
	 * User-preference key prefix for the per-category reminder lead time.
	 */
	private const PREF_LEAD_PREFIX = 'deadline_lead_days_';

	/**
	 * What publishForUser() answers when it cannot publish.
	 *
	 * @var array{status: string, published: int, removed: int}
	 */
	private const PUBLICATION_FAILED = [
		'status' => 'failed',
		'published' => 0,
		'removed' => 0,
	];

	/**
	 * Construct the service.
	 *
	 * @param ContainerInterface $container DI container — OR ObjectService
	 *                                      and OCP\Calendar\IManager are
	 *                                      resolved lazily (fail-soft, mirrors
	 *                                      ObligationTaskBridge).
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param IConfig $config User preferences (category toggles).
	 * @param IUserManager $userManager Iterates seen users for bulk publication.
	 * @param INotificationManager $notificationMgr Nextcloud notification manager.
	 * @param ObligationTaskBridge $obligationTaskBridge The single home of the
	 *                                                   contract-obligation path (REQ-CDC-005).
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
		private readonly INotificationManager $notificationMgr,
		private readonly ObligationTaskBridge $obligationTaskBridge,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Whether a deadline category is enabled for a user (REQ-CDC-006).
	 *
	 * @param string $userId The NC user id.
	 * @param string $category One of the CATEGORY_* constants.
	 *
	 * @return bool True when the category is enabled.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function isCategoryEnabled(string $userId, string $category): bool {
		$default = (self::CATEGORY_DEFAULTS[$category] ?? '0');
		$value = $this->config->getUserValue(
			userId: $userId,
			appName: AppIdentity::APP_ID,
			key: self::PREF_CATEGORY_PREFIX . $category,
			default: $default
		);

		return $value === '1';
	}//end isCategoryEnabled()

	/**
	 * Enable / disable a deadline category for a user (REQ-CDC-006).
	 *
	 * @param string $userId The NC user id.
	 * @param string $category One of the CATEGORY_* constants.
	 * @param bool $enabled The new toggle state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function setCategoryEnabled(string $userId, string $category, bool $enabled): void {
		if (in_array($category, self::CATEGORIES, true) === false) {
			return;
		}

		if ($enabled === true) {
			$value = '1';
		} else {
			$value = '0';
		}

		$this->config->setUserValue(
			userId: $userId,
			appName: AppIdentity::APP_ID,
			key: self::PREF_CATEGORY_PREFIX . $category,
			value: $value
		);

	}//end setCategoryEnabled()

	/**
	 * The reminder lead time in days for a user + category (REQ-CDC-007).
	 *
	 * @param string $userId The NC user id.
	 * @param string $category One of the CATEGORY_* constants.
	 *
	 * @return int Lead time in days (>= 0).
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function leadTimeDays(string $userId, string $category): int {
		$default = (string)(self::LEAD_DAYS_DEFAULTS[$category] ?? 7);
		$value = $this->config->getUserValue(
			userId: $userId,
			appName: AppIdentity::APP_ID,
			key: self::PREF_LEAD_PREFIX . $category,
			default: $default
		);

		$days = (int)$value;
		if ($days < 0) {
			return 0;
		}

		return $days;
	}//end leadTimeDays()

	/**
	 * Set the reminder lead time in days for a user + category.
	 *
	 * @param string $userId The NC user id.
	 * @param string $category One of the CATEGORY_* constants.
	 * @param int $days Lead time in days (clamped to >= 0).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function setLeadTimeDays(string $userId, string $category, int $days): void {
		if (in_array($category, self::CATEGORIES, true) === false) {
			return;
		}

		if ($days < 0) {
			$days = 0;
		}

		$this->config->setUserValue(
			userId: $userId,
			appName: AppIdentity::APP_ID,
			key: self::PREF_LEAD_PREFIX . $category,
			value: (string)$days
		);

	}//end setLeadTimeDays()

	/**
	 * Collect every OPEN deadline from the existing sources (REQ-CDC-002..005).
	 *
	 * Each entry: uid ({source}:{objectId}, REQ-CDC-001), category, summary,
	 * dueDate (Y-m-d), source, objectId. Sources whose read fails are
	 * skipped fail-soft (logged at debug) so one unavailable schema never
	 * hides the others.
	 *
	 * @return array<int,array<string,string>> Open deadlines.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function collectDeadlines(): array {
		$deadlines = array_merge(
			$this->sourceDeadlines(schema: 'BtwAangifte', mapper: $this->btwFilingDeadline(...)),
			$this->sourceDeadlines(schema: 'IcpOpgaaf', mapper: $this->icpFilingDeadline(...)),
			$this->sourceDeadlines(schema: 'TaxDeadline', mapper: $this->vpbFilingDeadline(...)),
			$this->sourceDeadlines(schema: 'PaymentRun', mapper: $this->paymentRunDeadline(...)),
			$this->sourceDeadlines(schema: 'ARInvoice', mapper: $this->arInvoiceDeadline(...)),
		);

		// REQ-CDC-005 — contract deadlines come from the extended bridge
		// (single home for the ContractObligation path; NOT re-read here).
		foreach ($this->obligationTaskBridge->listOpenObligationDeadlines() as $deadline) {
			$deadlines[] = $deadline;
		}

		return $deadlines;
	}//end collectDeadlines()

	/**
	 * Map every row of one source schema to its open deadline.
	 *
	 * @param string $schema The source schema.
	 * @param callable(array<string,mixed>):(array<string,string>|null) $mapper Row to deadline, null when not open.
	 *
	 * @return array<int,array<string,string>> The open deadlines of the source.
	 */
	private function sourceDeadlines(string $schema, callable $mapper): array {
		$deadlines = [];
		foreach ((new ComplianceSourceRows($this->objectService, $this->appConfig, $this->logger))->fetch(schema: $schema) as $row) {
			$deadline = $mapper($row);
			if ($deadline !== null) {
				$deadlines[] = $deadline;
			}
		}

		return $deadlines;
	}//end sourceDeadlines()

	/**
	 * REQ-CDC-002: a draft BTW return's filing deadline, from its period end.
	 *
	 * @param array<string,mixed> $row The BtwAangifte row.
	 *
	 * @return array<string,string>|null The deadline, null when submitted, verified or filed.
	 */
	private function btwFilingDeadline(array $row): ?array {
		if ((string)($row['statusCode'] ?? 'draft') !== 'draft') {
			return null;
		}

		$summary = 'BTW-aangifte ' . $this->vatPeriodLabel(row: $row);
		$due     = $this->filingDueDateFromPeriodEnd(periodEnd: (string)($row['endDate'] ?? ''));
		$entry   = ['source' => 'btw-filing', 'category' => self::CATEGORY_FILING, 'summary' => $summary, 'dueDate' => $due];

		return $this->openDeadline(row: $row, entry: $entry);
	}//end btwFilingDeadline()

	/**
	 * REQ-CDC-002: an open ICP-opgaaf's filing deadline, from its period field.
	 *
	 * @param array<string,mixed> $row The IcpOpgaaf row.
	 *
	 * @return array<string,string>|null The deadline, null when submitted, accepted or corrected.
	 */
	private function icpFilingDeadline(array $row): ?array {
		if (in_array((string)($row['status'] ?? 'draft'), ['draft', 'finalized', 'rejected'], true) === false) {
			return null;
		}

		$period    = (string)($row['period'] ?? '');
		$periodEnd = $this->icpPeriodEnd(period: $period);
		$due       = null;
		if ($periodEnd !== null) {
			$due = $this->filingDueDateFromPeriodEnd(periodEnd: $periodEnd);
		}

		$entry = ['source' => 'icp-filing', 'category' => self::CATEGORY_FILING, 'summary' => 'ICP-opgaaf ' . $period, 'dueDate' => $due];

		return $this->openDeadline(row: $row, entry: $entry);
	}//end icpFilingDeadline()

	/**
	 * REQ-CDC-002: a pending VPB deadline, which carries its own deadlineDate.
	 *
	 * @param array<string,mixed> $row The TaxDeadline row.
	 *
	 * @return array<string,string>|null The deadline, null when no longer pending.
	 */
	private function vpbFilingDeadline(array $row): ?array {
		if ((string)($row['status'] ?? 'pending') !== 'pending') {
			return null;
		}

		$label = trim((string)($row['deadlineType'] ?? '') . ' ' . (string)($row['fiscalYear'] ?? ''));

		$summary = trim('VPB ' . $label);
		$due     = $this->normaliseDate(date: (string)($row['deadlineDate'] ?? ''));
		$entry   = ['source' => 'vpb-filing', 'category' => self::CATEGORY_FILING, 'summary' => $summary, 'dueDate' => $due];

		return $this->openDeadline(row: $row, entry: $entry);
	}//end vpbFilingDeadline()

	/**
	 * REQ-CDC-003: a payment run's execution date while it is not yet executed.
	 *
	 * @param array<string,mixed> $row The PaymentRun row.
	 *
	 * @return array<string,string>|null The deadline, null once exported or reconciled.
	 */
	private function paymentRunDeadline(array $row): ?array {
		$state = (string)($row['lifecycleState'] ?? ($row['status'] ?? 'draft'));
		if (in_array($state, ['draft', 'approved'], true) === false) {
			return null;
		}

		$summary = trim('Betaalrun ' . $this->labelOrObjectId(row: $row, field: 'runNumber'));
		$due     = $this->normaliseDate(date: (string)($row['executionDate'] ?? ''));
		$entry   = ['source' => 'payment-run', 'category' => self::CATEGORY_PAYMENT_RUN, 'summary' => $summary, 'dueDate' => $due];

		return $this->openDeadline(row: $row, entry: $entry);
	}//end paymentRunDeadline()

	/**
	 * REQ-CDC-004: an issued AR invoice's due date. The per-user opt-in is
	 * applied at publication time, so the source set stays shared.
	 *
	 * @param array<string,mixed> $row The ARInvoice row.
	 *
	 * @return array<string,string>|null The deadline, null while draft or once paid or written off.
	 */
	private function arInvoiceDeadline(array $row): ?array {
		$state = (string)($row['lifecycleState'] ?? 'draft');
		if (in_array($state, ['issued', 'overdue', 'disputed'], true) === false) {
			return null;
		}

		$summary = trim('Factuur ' . $this->labelOrObjectId(row: $row, field: 'invoiceNumber') . ' vervalt');
		$due     = $this->normaliseDate(date: (string)($row['dueDate'] ?? ''));
		$entry   = ['source' => 'ar-invoice', 'category' => self::CATEGORY_AR_DUE, 'summary' => $summary, 'dueDate' => $due];

		return $this->openDeadline(row: $row, entry: $entry);
	}//end arInvoiceDeadline()

	/**
	 * Build the deadline entry (uid {source}:{objectId}, REQ-CDC-001) for a
	 * row that has both a due date and an object id.
	 *
	 * @param array<string,mixed> $row The source row.
	 * @param array{source: string, category: string, summary: string, dueDate: string|null} $entry The derived fields.
	 *
	 * @return array<string,string>|null The deadline, null without a due date or an object id.
	 */
	private function openDeadline(array $row, array $entry): ?array {
		$objectId = $this->objectId(row: $row);
		if ($entry['dueDate'] === null || $objectId === '') {
			return null;
		}

		return [
			'uid' => $entry['source'] . ':' . $objectId,
			'category' => $entry['category'],
			'summary' => $entry['summary'],
			'dueDate' => $entry['dueDate'],
			'source' => $entry['source'],
			'objectId' => $objectId,
		];
	}//end openDeadline()

	/**
	 * A row's label field, or its object id when the field is absent.
	 *
	 * @param array<string,mixed> $row The source row.
	 * @param string $field The label field.
	 *
	 * @return string The label.
	 */
	private function labelOrObjectId(array $row, string $field): string {
		return (string)($row[$field] ?? $this->objectId(row: $row));
	}//end labelOrObjectId()

	/**
	 * Publish the deadline VEVENTs for one user, honouring the user's
	 * category toggles (REQ-CDC-001 / REQ-CDC-006).
	 *
	 * Desired state = the open deadlines of every ENABLED category. Stale
	 * app-owned VEVENTs (disabled category, closed source) are overwritten
	 * with STATUS:CANCELLED (see class docblock — OCP exposes no public
	 * delete seam).
	 *
	 * @param string $userId The NC user id whose calendar is targeted.
	 *
	 * @return array{status: string, published: int, removed: int} Outcome —
	 *                                                             status 'ok' or 'failed' (fail-soft, never throws).
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function publishForUser(string $userId): array {
		try {
			$calendar = $this->resolveUserCalendar(userId: $userId);
			if ($calendar === null) {
				$this->logger->warning(
					'ComplianceDeadlineCalendarService: no writable calendar backend for user — degrading fail-soft',
					['userId' => $userId]
				);
				return self::PUBLICATION_FAILED;
			}

			$desired = $this->desiredDeadlines(userId: $userId);
			return [
				'status' => 'ok',
				'published' => $this->publishDesired(calendar: $calendar, desired: $desired),
				'removed' => $this->cancelUndesired(calendar: $calendar, desired: $desired),
			];
		} catch (Throwable $e) {
			// Never throw into a CRUD path or cron: log and degrade.
			$this->logger->error(
				'ComplianceDeadlineCalendarService: publication failed — degrading fail-soft',
				['userId' => $userId, 'exception' => $e->getMessage()]
			);
			return self::PUBLICATION_FAILED;
		}//end try

	}//end publishForUser()

	/**
	 * The open deadlines in the categories the user enabled, keyed by uid.
	 *
	 * @param string $userId The NC user id.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function desiredDeadlines(string $userId): array {
		$desired = [];
		foreach ($this->collectDeadlines() as $deadline) {
			if ($this->isCategoryEnabled(userId: $userId, category: $deadline['category']) === true) {
				$desired[$deadline['uid']] = $deadline;
			}
		}

		return $desired;
	}//end desiredDeadlines()

	/**
	 * Upsert one VEVENT per desired deadline.
	 *
	 * @param object                             $calendar The resolved calendar.
	 * @param array<string,array<string,string>> $desired  The desired deadlines by uid.
	 *
	 * @return int The number published.
	 */
	private function publishDesired(object $calendar, array $desired): int {
		$published = 0;
		foreach ($desired as $deadline) {
			$vevent = $this->buildVevent(deadline: $deadline, cancelled: false);
			$calendar->createFromString($this->uidToFilename(uid: $deadline['uid']), $vevent);
			$published++;
		}

		return $published;
	}//end publishDesired()

	/**
	 * Cancel app-owned VEVENTs that are no longer desired (category toggled
	 * off / source reached a closed state).
	 *
	 * @param object                             $calendar The resolved calendar.
	 * @param array<string,array<string,string>> $desired  The desired deadlines by uid.
	 *
	 * @return int The number cancelled.
	 */
	private function cancelUndesired(object $calendar, array $desired): int {
		$removed = 0;
		foreach ((new DeadlineCalendarSweep($this->logger))->existingAppUids(calendar: $calendar) as $uid) {
			if (isset($desired[$uid]) === false) {
				$calendar->createFromString($this->uidToFilename(uid: $uid), $this->cancelledVevent(uid: $uid));
				$removed++;
			}
		}

		return $removed;
	}//end cancelUndesired()

	/**
	 * The cancelling VEVENT for an app-owned uid, dated today.
	 *
	 * @param string $uid The VEVENT UID.
	 *
	 * @return string
	 */
	private function cancelledVevent(string $uid): string {
		return $this->buildVevent(
			deadline: [
				'uid' => $uid,
				'category' => $this->categoryForUid(uid: $uid),
				'summary' => 'Cancelled',
				'dueDate' => (new DateTimeImmutable('today'))->format('Y-m-d'),
				'source' => '',
				'objectId' => '',
			],
			cancelled: true
		);
	}//end cancelledVevent()

	/**
	 * Publish deadline VEVENTs for every seen user (bulk, daily job).
	 *
	 * @return int The number of users processed.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function publishAll(): int {
		$processed = 0;
		try {
			$this->userManager->callForSeenUsers(
				function (IUser $user) use (&$processed): ?bool {
					$this->publishForUser(userId: $user->getUID());
					$processed++;
					return null;
				}
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ComplianceDeadlineCalendarService: bulk publication failed — degrading fail-soft',
				['exception' => $e->getMessage()]
			);
		}

		return $processed;
	}//end publishAll()

	/**
	 * Raise NC Notifications for deadlines inside each user's lead time —
	 * exactly one per deadline per user (REQ-CDC-007).
	 *
	 * Dedup is a per-user JSON map (uid => dueDate) in user preferences;
	 * entries for deadlines that left the open set are pruned so the map
	 * stays bounded. A changed dueDate re-arms the reminder (the deadline
	 * genuinely moved).
	 *
	 * @param DateTimeInterface|null $now Reference "today" (injectable for tests).
	 *
	 * @return int Number of notifications raised.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function dispatchDueReminders(?DateTimeInterface $now = null): int {
		$today = ($now ?? new DateTimeImmutable('today'));
		$dispatched = 0;

		try {
			$deadlines = $this->collectDeadlines();
		} catch (Throwable $e) {
			$this->logger->error(
				'ComplianceDeadlineCalendarService: deadline collection failed — no reminders raised',
				['exception' => $e->getMessage()]
			);
			return 0;
		}

		$reminders = new DeadlineReminders($this->config, $this->notificationMgr, $this->logger, $this, $today);
		try {
			$this->userManager->callForSeenUsers(
				function (IUser $user) use ($deadlines, $reminders, &$dispatched): ?bool {
					$dispatched += $reminders->remindUser(userId: $user->getUID(), deadlines: $deadlines);
					return null;
				}
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ComplianceDeadlineCalendarService: reminder dispatch failed — degrading fail-soft',
				['exception' => $e->getMessage()]
			);
		}

		return $dispatched;
	}//end dispatchDueReminders()

	/**
	 * Resolve the target calendar for a user: the dedicated
	 * 'shillinq-deadlines' calendar when present, else the first
	 * writable (createFromString-capable) calendar — the documented
	 * design.md fallback. Null when nothing resolves (degrade trigger).
	 *
	 * The OCP calendar manager is fetched lazily via the container so a
	 * calendar-less instance degrades instead of failing DI — the same
	 * approach as {@see ObligationTaskBridge::resolveTaskBackend()}.
	 *
	 * @param string $userId The NC user id.
	 *
	 * @return object|null A createFromString-capable calendar, or null.
	 */
	private function resolveUserCalendar(string $userId): ?object {
		try {
			$manager = $this->getCalendarManager();
			if ($manager === null) {
				return null;
			}

			return $this->pickCalendar(calendars: $manager->getCalendarsForPrincipal('principals/users/' . $userId));
		} catch (Throwable $e) {
			$this->logger->debug(
				'ComplianceDeadlineCalendarService: calendar backend not resolvable',
				['userId' => $userId, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end resolveUserCalendar()

	/**
	 * The OCP calendar manager, null when the container has none or it
	 * cannot list a principal's calendars.
	 *
	 * @return object|null The calendar manager.
	 */
	private function getCalendarManager(): ?object {
		if ($this->container->has('OCP\\Calendar\\IManager') === false) {
			return null;
		}

		$manager = $this->container->get('OCP\\Calendar\\IManager');
		if (is_object($manager) === true && method_exists($manager, 'getCalendarsForPrincipal') === true) {
			return $manager;
		}

		return null;
	}//end getCalendarManager()

	/**
	 * Pick the dedicated deadline calendar, else the first writable one.
	 *
	 * @param mixed $calendars The principal's calendars.
	 *
	 * @return object|null The calendar, null when none is writable.
	 */
	private function pickCalendar(mixed $calendars): ?object {
		if (is_array($calendars) === false) {
			return null;
		}

		$writable = array_values(array_filter($calendars, $this->isWritableCalendar(...)));
		foreach ($writable as $calendar) {
			if ($this->isDeadlineCalendar(calendar: $calendar) === true) {
				return $calendar;
			}
		}

		return ($writable[0] ?? null);
	}//end pickCalendar()

	/**
	 * Whether a calendar accepts createFromString() upserts.
	 *
	 * @param mixed $calendar The candidate calendar.
	 *
	 * @return bool True when writable.
	 */
	private function isWritableCalendar(mixed $calendar): bool {
		return is_object($calendar) === true && method_exists($calendar, 'createFromString') === true;
	}//end isWritableCalendar()

	/**
	 * Whether a calendar is the app's dedicated deadline calendar.
	 *
	 * @param object $calendar The writable calendar.
	 *
	 * @return bool True for the 'shillinq-deadlines' calendar.
	 */
	private function isDeadlineCalendar(object $calendar): bool {
		return method_exists($calendar, 'getUri') === true && (string)$calendar->getUri() === self::CALENDAR_URI;
	}//end isDeadlineCalendar()

	/**
	 * The category a UID belongs to (derived from its source prefix).
	 *
	 * @param string $uid The VEVENT UID.
	 *
	 * @return string One of the CATEGORY_* constants (best-effort).
	 */
	private function categoryForUid(string $uid): string {
		if (str_starts_with($uid, 'payment-run:') === true) {
			return self::CATEGORY_PAYMENT_RUN;
		}

		if (str_starts_with($uid, 'ar-invoice:') === true) {
			return self::CATEGORY_AR_DUE;
		}

		if (str_starts_with($uid, 'contract:') === true) {
			return self::CATEGORY_CONTRACT;
		}

		return self::CATEGORY_FILING;
	}//end categoryForUid()

	/**
	 * Build the all-day VEVENT payload for one deadline (RFC 5545, CRLF).
	 *
	 * The UID is the stable {source}:{objectId} key (REQ-CDC-001) so the
	 * same deadline always maps to the same calendar object (idempotent
	 * upsert via a UID-derived filename). Cancellation is expressed as
	 * STATUS:CANCELLED + SEQUENCE:1 (see class docblock).
	 *
	 * @param array<string,string> $deadline The deadline entry.
	 * @param bool $cancelled Whether to emit a cancelled VEVENT.
	 *
	 * @return string The VCALENDAR payload.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	private function buildVevent(array $deadline, bool $cancelled): string {
		$date = str_replace('-', '', $deadline['dueDate']);

		$lines = [];
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:' . self::PRODID;
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $this->escapeIcal(value: $deadline['uid']);
		$lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
		$lines[] = 'DTSTART;VALUE=DATE:' . $date;
		$lines[] = 'SUMMARY:' . $this->escapeIcal(value: $deadline['summary']);
		if ($deadline['category'] !== '') {
			$lines[] = 'CATEGORIES:' . $this->escapeIcal(value: $deadline['category']);
		}

		if ($cancelled === true) {
			$lines[] = 'STATUS:CANCELLED';
			$lines[] = 'SEQUENCE:1';
		} else {
			$lines[] = 'STATUS:CONFIRMED';
			$lines[] = 'SEQUENCE:0';
		}

		$lines[] = 'TRANSP:TRANSPARENT';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode(self::CRLF, $lines) . self::CRLF;
	}//end buildVevent()

	/**
	 * Derive the calendar-object filename for a UID (stable, filesystem-safe).
	 *
	 * @param string $uid The VEVENT UID ({source}:{objectId}).
	 *
	 * @return string The .ics object name.
	 */
	private function uidToFilename(string $uid): string {
		$safe = strtolower((string)preg_replace('/[^A-Za-z0-9\\-]+/', '-', $uid));
		return 'shillinq-' . trim($safe, '-') . '.ics';
	}//end uidToFilename()

	/**
	 * Escape a string for inclusion as an iCalendar text value (RFC 5545)
	 * — same convention as {@see IcsService::escape()}.
	 *
	 * @param string $value The raw text value.
	 *
	 * @return string The escaped value.
	 */
	private function escapeIcal(string $value): string {
		return str_replace(
			['\\', "\r\n", "\n", ',', ';'],
			['\\\\', '\\n', '\\n', '\\,', '\\;'],
			$value
		);

	}//end escapeIcal()

	/**
	 * Derive the NL filing due date from a period end date: the last day
	 * of the month FOLLOWING the period end (standard BTW-aangifte / ICP
	 * rule). Pure derivation from existing period data — no parallel
	 * deadline is stored (REQ-CDC-002).
	 *
	 * @param string $periodEnd The period end (Y-m-d-ish).
	 *
	 * @return string|null The due date (Y-m-d), or null when unparseable.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	private function filingDueDateFromPeriodEnd(string $periodEnd): ?string {
		$end = $this->normaliseDate(date: $periodEnd);
		if ($end === null) {
			return null;
		}

		try {
			return (new DateTimeImmutable($end))
				->modify('first day of next month')
				->modify('last day of this month')
				->format('Y-m-d');
		} catch (Throwable $e) {
			return null;
		}

	}//end filingDueDateFromPeriodEnd()

	/**
	 * Resolve the period end date of an IcpOpgaaf `period` value
	 * ('YYYY-Qn' quarterly or 'YYYY-MM' monthly).
	 *
	 * @param string $period The IcpOpgaaf period string.
	 *
	 * @return string|null The period end (Y-m-d), or null when unparseable.
	 */
	private function icpPeriodEnd(string $period): ?string {
		if (preg_match('/^(\\d{4})-Q([1-4])$/i', $period, $matches) === 1) {
			$year = (int)$matches[1];
			$endMonth = ((int)$matches[2] * 3);
			return $this->lastDayOf(year: $year, month: $endMonth);
		}

		if (preg_match('/^(\\d{4})-(\\d{2})$/', $period, $matches) === 1) {
			$year = (int)$matches[1];
			$month = (int)$matches[2];
			if ($month >= 1 && $month <= 12) {
				return $this->lastDayOf(year: $year, month: $month);
			}
		}

		return null;
	}//end icpPeriodEnd()

	/**
	 * The last day of a year+month as Y-m-d.
	 *
	 * @param int $year The year.
	 * @param int $month The month (1-12).
	 *
	 * @return string|null The date, or null on failure.
	 */
	private function lastDayOf(int $year, int $month): ?string {
		try {
			return (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
				->modify('last day of this month')
				->format('Y-m-d');
		} catch (Throwable $e) {
			return null;
		}

	}//end lastDayOf()

	/**
	 * Human period label for a VATReturn row ("2026-Q1" / "2026-03" /
	 * "2026") — matches the REQ-CDC-002 scenario naming.
	 *
	 * @param array<string,mixed> $row The VATReturn record.
	 *
	 * @return string The period label.
	 */
	private function vatPeriodLabel(array $row): string {
		$year = (string)($row['periodYear'] ?? '');
		$number = (int)($row['periodNumber'] ?? 0);
		$unit = (string)($row['period'] ?? 'quarter');

		if ($unit === 'quarter') {
			return $year . '-Q' . $number;
		}

		if ($unit === 'month') {
			return $year . '-' . str_pad((string)$number, 2, '0', STR_PAD_LEFT);
		}

		return $year;
	}//end vatPeriodLabel()

	/**
	 * Normalise a date-ish string to Y-m-d, or null when unparseable.
	 *
	 * @param string $date The raw date value.
	 *
	 * @return string|null Y-m-d, or null.
	 */
	private function normaliseDate(string $date): ?string {
		if ($date === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($date))->format('Y-m-d');
		} catch (Throwable $e) {
			return null;
		}

	}//end normaliseDate()

	/**
	 * The stable object id of an OR record (slug preferred, then ids) —
	 * same resolution order as {@see TaxNotificationService}.
	 *
	 * @param array<string,mixed> $row The OR record.
	 *
	 * @return string The object id, or '' when the row carries none.
	 */
	private function objectId(array $row): string {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		return (string)($self['slug'] ?? ($self['id'] ?? ($row['id'] ?? ($row['uuid'] ?? ''))));
	}//end objectId()
}//end class
