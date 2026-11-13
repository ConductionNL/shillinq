<?php

/**
 * Recurring Invoice Generator
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The thin ADR-031 exception-path executor behind the recurring-invoicing
 * change. Generation is declared on the RecurringInvoiceProfile schema
 * (lib/Settings/register.d/recurring-invoicing.json) — schedule, lifecycle,
 * nextRunDate calculation and notification rules all live in the register
 * fragment. This service only does the one thing the declarative layer
 * cannot express on its own: compose an ordinary ARInvoice record for a due
 * profile and advance the profile's bookkeeping (REQ-RIN-002/004).
 *
 * It contains NO numbering, tax or delivery logic of its own — generated
 * invoices are ORDINARY ARInvoice records (no parallel invoice type). Per-line
 * BTW, no-gap numbering and Peppol delivery are owned by
 * bookkeeping-quote-order-invoice (CHAINED — REQ-RIN-010); until that change
 * lands generation targets the AR-core ARInvoice surface, computing the
 * standard inclusive BTW per line, and `deliveryChannel = peppol` is not
 * selectable. SEPA collection routing is owned by
 * bookkeeping-sepa-direct-debit (CHAINED — REQ-RIN-011).
 *
 * All reads/writes go through OpenRegister's real ObjectService API
 * (findAll / saveObject) — `findObject` / `createFromArray` / `deleteFromId`
 * do NOT exist and are never used ([[or-objectservice-api]]). The
 * (profile, billingPeriod) key makes regeneration idempotent: a run for a
 * period that already produced a non-cancelled invoice is a no-op
 * (REQ-RIN-004).
 *
 * Date arithmetic (nextRunDate, period keys, indexation anniversaries) is
 * civil-date arithmetic — DateTimeImmutable on Y-m-d with month-length
 * clamping — never UTC timestamps, so a DST shift never moves an invoice day
 * (REQ-RIN-003). The pure date/token helpers are static so they can be
 * unit-tested without OR.
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
 * @spec openspec/specs/recurring-invoicing/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Generates ordinary ARInvoice records from due RecurringInvoiceProfile
 * records and advances their schedule.
 *
 * @spec openspec/specs/recurring-invoicing/spec.md
 */
class RecurringInvoiceGenerator {

	/**
	 * English month names for the {month} token (the document language
	 * resolves the localized name via expandTokens()'s $monthNames map).
	 *
	 * @var array<int,string>
	 */
	private const MONTHS_EN = [
		1 => 'January',
		2 => 'February',
		3 => 'March',
		4 => 'April',
		5 => 'May',
		6 => 'June',
		7 => 'July',
		8 => 'August',
		9 => 'September',
		10 => 'October',
		11 => 'November',
		12 => 'December',
	];

	/**
	 * Dutch month names for the {month} token when the customer document
	 * language is nl.
	 *
	 * @var array<int,string>
	 */
	private const MONTHS_NL = [
		1 => 'januari',
		2 => 'februari',
		3 => 'maart',
		4 => 'april',
		5 => 'mei',
		6 => 'juni',
		7 => 'juli',
		8 => 'augustus',
		9 => 'september',
		10 => 'oktober',
		11 => 'november',
		12 => 'december',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is
	 *                                      fetched lazily so unit tests can
	 *                                      swap an in-memory stub.
	 * @param IAppConfig $appConfig App config for the OR register slug.
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Generate the due period's ordinary ARInvoice for a single profile and
	 * advance the profile's schedule (REQ-RIN-002/004).
	 *
	 * The method is idempotent on the (profileId, billingPeriod) key: if a
	 * non-cancelled ARInvoice already carries this profile id + billing period
	 * the existing record is returned unchanged and the profile is NOT
	 * re-advanced. A cancelled invoice for the same key unblocks regeneration.
	 *
	 * @param array<string,mixed> $profile The RecurringInvoiceProfile object.
	 *
	 * @return array<string,mixed> Result: ['invoice' => array|null,
	 *                             'profile' => array, 'created' => bool,
	 *                             'billingPeriod' => string].
	 *
	 * @throws \RuntimeException When the profile is malformed.
	 *
	 * @spec openspec/specs/recurring-invoicing/spec.md
	 * @spec openspec/changes/billing-inherited-defects/specs/recurring-invoicing/spec.md (REQ-RIN-010)
	 */
	public function generateForProfile(array $profile): array {
		$profileId = (string)($profile['id'] ?? $profile['uuid'] ?? '');
		if ($profileId === '') {
			throw new RuntimeException('RecurringInvoiceProfile is missing an id');
		}

		$billingPeriod = self::dueBillingPeriod(profile: $profile);

		// Idempotency: a non-cancelled invoice for this (profile, period)
		// makes the run a no-op (REQ-RIN-004).
		$generated = $this->findGeneratedInvoices(profileId: $profileId, billingPeriod: $billingPeriod);
		$existing = self::liveInvoice(rows: $generated);
		if ($existing !== null) {
			return ['invoice' => $existing, 'profile' => $profile, 'created' => false, 'billingPeriod' => $billingPeriod];
		}

		$period = self::periodFor(profile: $profile, billingPeriod: $billingPeriod);
		$period->sequence = (count($generated) + 1);
		$invoice = $this->saveGeneratedInvoice(period: $period);

		return [
			'invoice' => $invoice,
			'profile' => $this->saveObject(schema: 'RecurringInvoiceProfile', object: self::advancedProfile(period: $period)),
			'created' => true,
			'billingPeriod' => $billingPeriod,
		];

	}//end generateForProfile()

	/**
	 * The period a profile generates for: its start, the clamped issue date,
	 * the due date after the payment terms and the document language.
	 *
	 * @param array<string,mixed> $profile       The profile.
	 * @param string              $billingPeriod The due billing-period key.
	 *
	 * @return RecurringInvoicePeriod The period.
	 */
	private static function periodFor(array $profile, string $billingPeriod): RecurringInvoicePeriod {
		$period = new RecurringInvoicePeriod(profile: $profile, profileId: (string)($profile['id'] ?? $profile['uuid'] ?? ''));
		$period->billingPeriod = $billingPeriod;
		$period->language = (string)($profile['documentLanguage'] ?? 'en');
		$period->periodStart = self::periodStartDate(billingPeriod: $billingPeriod);
		$period->issueDate = self::clampedInvoiceDate(profile: $profile, billingPeriod: $billingPeriod);
		$period->dueDate = self::addDays(date: $period->issueDate, days: (int)($profile['paymentTermsDays'] ?? 30));
		return $period;
	}//end periodFor()

	/**
	 * Save the period's ARInvoice; on failure mark the profile failed and
	 * refuse.
	 *
	 * @param RecurringInvoicePeriod $period The period.
	 *
	 * @return array<string,mixed> The saved invoice.
	 *
	 * @throws RuntimeException When the invoice cannot be saved.
	 */
	private function saveGeneratedInvoice(RecurringInvoicePeriod $period): array {
		try {
			return $this->saveObject(schema: 'ARInvoice', object: self::arInvoicePayload(period: $period));
		} catch (\Throwable $e) {
			$this->logger->error(
				'RecurringInvoiceGenerator: failed to generate invoice',
				['profileId' => $period->profileId, 'exception' => $e->getMessage()]
			);
			$profile = $period->profile;
			$profile['lastRunStatus'] = 'failed';
			$profile['lastRunError'] = 'Invoice generation failed.';
			$this->saveObject(schema: 'RecurringInvoiceProfile', object: $profile);
			throw new RuntimeException('Recurring invoice generation failed');
		}
	}//end saveGeneratedInvoice()

	/**
	 * The profile advanced past the generated period (REQ-RIN-002/005): last
	 * period and run status, the next run date and the occurrence count.
	 *
	 * @param RecurringInvoicePeriod $period The generated period.
	 *
	 * @return array<string,mixed> The advanced profile.
	 */
	private static function advancedProfile(RecurringInvoicePeriod $period): array {
		$profile = $period->profile;
		$profile['lastBillingPeriod'] = $period->billingPeriod;
		$profile['lastRunStatus'] = 'ok';
		$profile['lastRunError'] = null;
		$profile['nextRunDate'] = self::nextRunDate(
			frequency: (string)($profile['frequency'] ?? 'monthly'),
			interval: (int)($profile['interval'] ?? 1),
			invoiceDay: (int)($profile['invoiceDay'] ?? 1),
			startDate: (string)($profile['startDate'] ?? ''),
			lastBillingPeriod: $period->billingPeriod,
		);

		return self::countedOccurrence(profile: $profile);
	}//end advancedProfile()

	/**
	 * Occurrence-count ending (REQ-RIN-005): one occurrence fewer, and the
	 * profile ends at zero. A profile without a count is unlimited.
	 *
	 * @param array<string,mixed> $profile The profile.
	 *
	 * @return array<string,mixed> The profile with its count advanced.
	 */
	private static function countedOccurrence(array $profile): array {
		if (array_key_exists('remainingOccurrences', $profile) === false || $profile['remainingOccurrences'] === null) {
			return $profile;
		}

		$profile['remainingOccurrences'] = max(0, ((int)$profile['remainingOccurrences'] - 1));
		if ($profile['remainingOccurrences'] === 0) {
			$profile['status'] = 'ended';
		}

		return $profile;
	}//end countedOccurrence()

	/**
	 * Scheduled entry point referenced by the register fragment's
	 * x-openregister-scheduled-workflows handler. Selects all active,
	 * due profiles and generates each (REQ-RIN-002).
	 *
	 * Auto-issue profiles catch up at most ONE missed period per run; older
	 * missed periods are surfaced for manual generation. draft-for-review
	 * profiles catch up all missed periods as drafts (handled by repeated
	 * daily runs — each run advances exactly one period) (REQ-RIN-004).
	 *
	 * @param string|null $asOf Override "today" (Y-m-d) for tests.
	 *
	 * @return array<int,array<string,mixed>> Per-profile results.
	 *
	 * @spec openspec/specs/recurring-invoicing/spec.md
	 */
	public function runScheduled(?string $asOf = null): array {
		$today = $asOf ?? (new DateTimeImmutable('today'))->format('Y-m-d');
		$profiles = $this->findAll(schema: 'RecurringInvoiceProfile', filters: ['status' => 'active']);

		$results = [];
		foreach ($profiles as $profile) {
			$nextRun = (string)($profile['nextRunDate'] ?? '');
			if ($nextRun === '' || $nextRun > $today) {
				continue;
			}

			try {
				$results[] = $this->generateForProfile(profile: $profile);
			} catch (\Throwable $e) {
				$this->logger->error(
					'RecurringInvoiceGenerator: scheduled run failed for a profile',
					['exception' => $e->getMessage()]
				);
			}
		}

		return $results;
	}//end runScheduled()

	/**
	 * Build the ordinary ARInvoice payload for a generated period. Computes
	 * inclusive per-line BTW with the standard Dutch rates and stamps the
	 * recurringProfileId + billingPeriod provenance fields. issueMode
	 * draft-for-review yields lifecycleState draft; auto-issue yields issued.
	 *
	 * @param array<string,mixed> $profile The profile.
	 * @param string $profileId The profile id.
	 * @param string $billingPeriod The billing-period key.
	 * @param string $periodStart Period start date (Y-m-d).
	 * @param string $issueDate Invoice issue date (Y-m-d).
	 * @param string $dueDate Invoice due date (Y-m-d).
	 * @param string $language Document language for tokens.
	 * @param int $sequence The invoice's number inside its profile and period, from 1.
	 *
	 * @return array<string,mixed> The ARInvoice payload.
	 *
	 * @spec openspec/specs/recurring-invoicing/spec.md
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/recurring-invoicing/spec.md#requirement-req-rin-009-a-generated-invoice-shall-carry-its-lines-as-invoicelines
	 * @spec openspec/changes/billing-inherited-defects/specs/recurring-invoicing/spec.md (REQ-RIN-010)
	 */
	public static function buildArInvoicePayload(
		array $profile,
		string $profileId,
		string $billingPeriod,
		string $periodStart,
		string $issueDate,
		string $dueDate,
		string $language,
		int $sequence = 1,
	): array {
		$period = new RecurringInvoicePeriod(profile: $profile, profileId: $profileId);
		$period->billingPeriod = $billingPeriod;
		$period->periodStart = $periodStart;
		$period->issueDate = $issueDate;
		$period->dueDate = $dueDate;
		$period->language = $language;
		$period->sequence = $sequence;
		return self::arInvoicePayload(period: $period);

	}//end buildArInvoicePayload()

	/**
	 * The ordinary ARInvoice payload of a generated period: the per-line BTW
	 * totals, the provisional invoice number and the recurringProfileId +
	 * billingPeriod provenance. issueMode draft-for-review yields lifecycleState
	 * draft; auto-issue yields issued.
	 *
	 * @param RecurringInvoicePeriod $period The period.
	 *
	 * @return array<string,mixed> The ARInvoice payload.
	 */
	private static function arInvoicePayload(RecurringInvoicePeriod $period): array {
		$profile = $period->profile;
		$lines = self::invoiceLines(period: $period);
		$net = round($lines['net'], 2);
		$vat = round($lines['vat'], 2);

		$lifecycleState = 'draft';
		if (($profile['issueMode'] ?? 'draft-for-review') === 'auto-issue') {
			$lifecycleState = 'issued';
		}

		return [
			'invoiceNumber' => self::provisionalNumber(period: $period),
			'periodId' => $period->billingPeriod,
			'customerId' => self::customerIdOf(profile: $profile),
			'administrationId' => ($profile['administrationId'] ?? null),
			'invoiceDate' => $period->issueDate,
			'dueDate' => $period->dueDate,
			'currency' => (string)($profile['currency'] ?? 'EUR'),
			'netAmount' => $net,
			'vatAmount' => $vat,
			'grossAmount' => round(($net + $vat), 2),
			'lifecycleState' => $lifecycleState,
			'recurringProfileId' => $period->profileId,
			'billingPeriod' => $period->billingPeriod,
			'invoiceLines' => $lines['lines'],
		];
	}//end arInvoicePayload()

	/**
	 * The customer a generated invoice bills: the profile's CustomerMaster
	 * once it has one (sales-cancellation, REQ-SCX-003), else its contact
	 * reference as before.
	 *
	 * @param array<string, mixed> $profile The recurring profile.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md
	 */
	private static function customerIdOf(array $profile): string {
		$customerMasterId = trim((string)($profile['customerMasterId'] ?? ''));
		if ($customerMasterId !== '') {
			return $customerMasterId;
		}

		return (string)($profile['customerReference'] ?? '');
	}//end customerIdOf()

	/**
	 * ARInvoice requires an invoice number and a period. The number is
	 * provisional and unique per profile, period and attempt, so a period
	 * regenerated after a cancellation gets its own (REQ-RIN-010).
	 *
	 * @param RecurringInvoicePeriod $period The period.
	 *
	 * @return string The provisional invoice number.
	 */
	private static function provisionalNumber(RecurringInvoicePeriod $period): string {
		return sprintf(
			'REC-%s-%s-%02d',
			str_replace('-', '', $period->billingPeriod),
			strtoupper(substr(str_replace('-', '', $period->profileId), 0, 8)),
			$period->sequence
		);
	}//end provisionalNumber()

	/**
	 * The invoice lines of the profile with the unrounded net and VAT totals
	 * (inclusive per-line BTW with the standard Dutch rates).
	 *
	 * @param RecurringInvoicePeriod $period The period.
	 *
	 * @return array{lines:list<array<string,mixed>>,net:float,vat:float}
	 */
	private static function invoiceLines(RecurringInvoicePeriod $period): array {
		$totals = ['lines' => [], 'net' => 0.0, 'vat' => 0.0];
		$rawLines = [];
		if (is_array($period->profile['lines'] ?? null) === true) {
			$rawLines = $period->profile['lines'];
		}

		foreach ($rawLines as $raw) {
			$line = self::invoiceLine(period: $period, raw: $raw);
			$lineNet = ((float)($raw['quantity'] ?? 1) * (float)($raw['unitPrice'] ?? 0));
			$totals['lines'][] = ['lineId' => (string)(count($totals['lines']) + 1)] + $line;
			$totals['net'] += $lineNet;
			$totals['vat'] += ($lineNet * ($line['vatRate'] / 100));
		}

		return $totals;
	}//end invoiceLines()

	/**
	 * One invoice line in the EN 16931 BG-25 shape ARInvoice declares as
	 * `invoiceLines` (the former `lines` was dropped by OpenRegister, so every
	 * generated invoice arrived without lines — REQ-RIN-009). The line
	 * declares `glAccount` since ARInvoice 0.16.0, so the profile line's
	 * revenue account is booked per line (REQ-RIN-010).
	 *
	 * @param RecurringInvoicePeriod $period The period (tokens, language).
	 * @param array<string,mixed>    $raw    The profile line.
	 *
	 * @return array<string,mixed> The line without its lineId.
	 */
	private static function invoiceLine(RecurringInvoicePeriod $period, array $raw): array {
		$quantity = (float)($raw['quantity'] ?? 1);
		$unitPrice = (float)($raw['unitPrice'] ?? 0);
		$vatRate = (int)($raw['vatCode'] ?? 21);

		return [
			'itemName' => self::expandTokens(
				description: (string)($raw['description'] ?? ''),
				periodStart: $period->periodStart,
				language: $period->language
			),
			'quantity' => $quantity,
			'unitCode' => 'C62',
			'netPrice' => $unitPrice,
			'netAmount' => round(($quantity * $unitPrice), 2),
			'vatRate' => $vatRate,
			'vatCategory' => self::vatCategory(vatRate: $vatRate),
			'glAccount' => ($raw['revenueAccount'] ?? null),
		];
	}//end invoiceLine()

	/**
	 * The EN 16931 VAT category of a line: S for a standard rate, Z at zero.
	 *
	 * @param int $vatRate The line's VAT rate in percent.
	 *
	 * @return string The category code.
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/recurring-invoicing/spec.md#requirement-req-rin-009-a-generated-invoice-shall-carry-its-lines-as-invoicelines
	 */
	private static function vatCategory(int $vatRate): string {
		if ($vatRate > 0) {
			return 'S';
		}

		return 'Z';
	}//end vatCategory()

	/**
	 * Expand {period}/{month}/{year} tokens in a line description, localized
	 * to the document language (REQ-RIN-002).
	 *
	 * @param string $description Raw description with tokens.
	 * @param string $periodStart Period start date (Y-m-d).
	 * @param string $language Document language (en/nl).
	 *
	 * @return string The expanded description.
	 *
	 * @spec openspec/specs/recurring-invoicing/spec.md
	 */
	public static function expandTokens(string $description, string $periodStart, string $language): string {
		$date = self::immutable(value: $periodStart);
		if ($date === null) {
			return $description;
		}

		$monthNum = (int)$date->format('n');
		$year = $date->format('Y');
		$monthName = self::MONTHS_EN[$monthNum];
		if ($language === 'nl') {
			$monthName = self::MONTHS_NL[$monthNum];
		}

		$period = $monthName . ' ' . $year;

		return strtr(
			$description,
			[
				'{period}' => $period,
				'{month}' => $monthName,
				'{year}' => $year,
			]
		);

	}//end expandTokens()

	/**
	 * The billing-period key (YYYY-MM) currently due for a profile: the period
	 * after lastBillingPeriod, or the start period when none generated yet.
	 *
	 * @param array<string,mixed> $profile The profile.
	 *
	 * @return string The period key (Y-m).
	 *
	 * @spec openspec/specs/recurring-invoicing/spec.md
	 */
	public static function dueBillingPeriod(array $profile): string {
		$frequency = (string)($profile['frequency'] ?? 'monthly');
		$interval = max(1, (int)($profile['interval'] ?? 1));
		$last = (string)($profile['lastBillingPeriod'] ?? '');

		if ($last === '') {
			$start = self::immutable(value: (string)($profile['startDate'] ?? ''));
			if ($start === null) {
				return (new DateTimeImmutable('today'))->format('Y-m');
			}

			return $start->format('Y-m');
		}

		$lastStart = self::immutable(value: $last . '-01');
		if ($lastStart === null) {
			return $last;
		}

		$next = self::addPeriod(date: $lastStart, frequency: $frequency, interval: $interval);
		return $next->format('Y-m');
	}//end dueBillingPeriod()

	/**
	 * Period start date (Y-m-d, first of the period) for a billing-period key.
	 *
	 * @param string $billingPeriod The period key (Y-m).
	 *
	 * @return string Period start date (Y-m-d).
	 *
	 * @spec openspec/specs/recurring-invoicing/spec.md
	 */
	public static function periodStartDate(string $billingPeriod): string {
		$start = self::immutable(value: $billingPeriod . '-01');
		if ($start === null) {
			return (new DateTimeImmutable('today'))->format('Y-m-d');
		}

		return $start->format('Y-m-d');
	}//end periodStartDate()

	/**
	 * The actual invoice date for a period: invoiceDay clamped to the period
	 * month's length (REQ-RIN-003).
	 *
	 * @param array<string,mixed> $profile The profile.
	 * @param string $billingPeriod The period key (Y-m).
	 *
	 * @return string Invoice date (Y-m-d).
	 *
	 * @spec openspec/specs/recurring-invoicing/spec.md
	 */
	public static function clampedInvoiceDate(array $profile, string $billingPeriod): string {
		$invoiceDay = max(1, min(31, (int)($profile['invoiceDay'] ?? 1)));
		$base = self::immutable(value: $billingPeriod . '-01');
		if ($base === null) {
			return (new DateTimeImmutable('today'))->format('Y-m-d');
		}

		$daysInMonth = (int)$base->format('t');
		$day = min($invoiceDay, $daysInMonth);

		return $base->setDate(
			(int)$base->format('Y'),
			(int)$base->format('n'),
			$day
		)->format('Y-m-d');

	}//end clampedInvoiceDate()

	/**
	 * Compute nextRunDate from frequency/interval/invoiceDay and the last
	 * generated billing period, with month-end clamping and Feb-29 →
	 * Feb-28 anniversary fallback (REQ-RIN-003). Civil dates only.
	 *
	 * @param string $frequency weekly|monthly|quarterly|semi-annually|annually.
	 * @param int $interval Cadence multiplier.
	 * @param int $invoiceDay Target day of period (1-31).
	 * @param string $startDate Profile start date (Y-m-d).
	 * @param string $lastBillingPeriod Last generated period (Y-m) or ''.
	 *
	 * @return string nextRunDate (Y-m-d), or '' when undeterminable.
	 *
	 * @spec openspec/specs/recurring-invoicing/spec.md
	 */
	public static function nextRunDate(
		string $frequency,
		int $interval,
		int $invoiceDay,
		string $startDate,
		string $lastBillingPeriod,
	): string {
		$interval = max(1, $interval);
		$invoiceDay = max(1, min(31, $invoiceDay));

		if ($lastBillingPeriod === '') {
			// No period generated yet — next run is the start period's
			// clamped invoice day. Weekly cadences advance from the literal
			// start date and ignore invoiceDay (a day-of-month concept).
			$start = self::immutable(value: $startDate);
			if ($start === null) {
				return '';
			}

			if ($frequency === 'weekly') {
				return $start->format('Y-m-d');
			}

			return self::clampDay(date: $start, day: $invoiceDay)->format('Y-m-d');
		}

		if ($frequency === 'weekly') {
			// Weekly advances 7 * interval days from the last period's start;
			// the lastBillingPeriod for weekly is the start date itself, so
			// resolve it from startDate when the key is month-shaped.
			$weekBase = self::immutable(value: $startDate);
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastBillingPeriod) === 1) {
				$weekBase = self::immutable(value: $lastBillingPeriod);
			}

			if ($weekBase === null) {
				return '';
			}

			return self::addPeriod(date: $weekBase, frequency: 'weekly', interval: $interval)->format('Y-m-d');
		}

		$lastStart = self::immutable(value: $lastBillingPeriod . '-01');
		if ($lastStart === null) {
			return '';
		}

		$nextPeriod = self::addPeriod(date: $lastStart, frequency: $frequency, interval: $interval);
		return self::clampDay(date: $nextPeriod, day: $invoiceDay)->format('Y-m-d');
	}//end nextRunDate()

	/**
	 * Add one cadence step (frequency × interval) to a civil date, landing on
	 * the first of the resulting period for month-based cadences.
	 *
	 * @param DateTimeImmutable $date Base civil date.
	 * @param string $frequency Cadence.
	 * @param int $interval Multiplier.
	 *
	 * @return DateTimeImmutable The advanced date.
	 */
	private static function addPeriod(DateTimeImmutable $date, string $frequency, int $interval): DateTimeImmutable {
		$months = match ($frequency) {
			'weekly' => 0,
			'monthly' => (1 * $interval),
			'quarterly' => (3 * $interval),
			'semi-annually' => (6 * $interval),
			'annually' => (12 * $interval),
			default => (1 * $interval),
		};

		if ($frequency === 'weekly') {
			return $date->add(new DateInterval('P' . (7 * $interval) . 'D'));
		}

		// Add months on the first-of-month to avoid PHP's month-overflow
		// (e.g. Jan 31 + 1 month = Mar 03); clamping to invoiceDay happens
		// separately in clampDay().
		$firstOfMonth = $date->setDate((int)$date->format('Y'), (int)$date->format('n'), 1);
		return $firstOfMonth->add(new DateInterval('P' . $months . 'M'));
	}//end addPeriod()

	/**
	 * Clamp a date to a target day-of-month, capped at the month's length
	 * (invoiceDay 31 in February → 28/29).
	 *
	 * @param DateTimeImmutable $date Base date (any day).
	 * @param int $day Target day (1-31).
	 *
	 * @return DateTimeImmutable The clamped date.
	 */
	private static function clampDay(DateTimeImmutable $date, int $day): DateTimeImmutable {
		$firstOfMonth = $date->setDate((int)$date->format('Y'), (int)$date->format('n'), 1);
		$daysInMonth = (int)$firstOfMonth->format('t');
		$clamped = min($day, $daysInMonth);

		return $firstOfMonth->setDate(
			(int)$firstOfMonth->format('Y'),
			(int)$firstOfMonth->format('n'),
			$clamped
		);

	}//end clampDay()

	/**
	 * Add civil days to a Y-m-d date string.
	 *
	 * @param string $date Base date (Y-m-d).
	 * @param int $days Days to add (>= 0).
	 *
	 * @return string The resulting date (Y-m-d).
	 *
	 * @spec openspec/specs/recurring-invoicing/spec.md
	 */
	public static function addDays(string $date, int $days): string {
		$base = self::immutable(value: $date);
		if ($base === null) {
			return $date;
		}

		return $base->add(new DateInterval('P' . max(0, $days) . 'D'))->format('Y-m-d');
	}//end addDays()

	/**
	 * Parse a Y-m-d (or Y-m) date string into a DateTimeImmutable, or null on
	 * a malformed value. Strictly civil — no timezone surprises.
	 *
	 * @param string $value The date string.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function immutable(string $value): ?DateTimeImmutable {
		if (preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $value) !== 1) {
			return null;
		}

		$normalised = $value;
		if (strlen($value) === 7) {
			$normalised = $value . '-01';
		}

		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $normalised);
		if ($date === false) {
			return null;
		}

		return $date;
	}//end immutable()

	/**
	 * Every ARInvoice already generated for the (profile, billingPeriod) key,
	 * cancelled ones included (REQ-RIN-004 idempotency probe). Both filter
	 * fields are declared on ARInvoice since 0.16.0; before that OpenRegister
	 * dropped them and this probe never found an earlier invoice (REQ-RIN-010).
	 *
	 * @param string $profileId The profile id.
	 * @param string $billingPeriod The billing-period key.
	 *
	 * @return array<int,array<string,mixed>> The invoices for the key.
	 *
	 * @spec openspec/changes/billing-inherited-defects/specs/recurring-invoicing/spec.md (REQ-RIN-010)
	 */
	private function findGeneratedInvoices(string $profileId, string $billingPeriod): array {
		return $this->findAll(
			schema: 'ARInvoice',
			filters: [
				'recurringProfileId' => $profileId,
				'billingPeriod' => $billingPeriod,
			]
		);
	}//end findGeneratedInvoices()

	/**
	 * The first invoice of a key that is neither cancelled nor credited.
	 *
	 * @param array<int,array<string,mixed>> $rows The invoices for the key.
	 *
	 * @return array<string,mixed>|null The live invoice, or null.
	 *
	 * @spec openspec/specs/recurring-invoicing/spec.md
	 */
	private static function liveInvoice(array $rows): ?array {
		foreach ($rows as $row) {
			$state = (string)($row['lifecycleState'] ?? '');
			if ($state !== 'cancelled' && $state !== 'credited') {
				return $row;
			}
		}

		return null;
	}//end liveInvoice()

	/**
	 * Persist an object via the real ObjectService API (saveObject).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object The object payload.
	 *
	 * @return array<string,mixed> The persisted object.
	 *
	 * @throws \RuntimeException On persistence failure.
	 */
	private function saveObject(string $schema, array $object): array {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$result = $objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($object);

		if (is_array($result) === true) {
			return $result;
		}

		return $object;
	}//end saveObject()

	/**
	 * Fetch all matching records via the real ObjectService API (findAll).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RecurringInvoiceGenerator: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OR register slug from app config (defaults to "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
