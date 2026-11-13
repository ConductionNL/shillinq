<?php

/**
 * Fiscal Year Context Service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Slice 09 of the bookkeeping-waterschappen-bbv-variant chain (ADR-032).
 * Resolves the *active fiscal year* for an administration so every BBV
 * query (dashboard, mapping index, mapping detail, ComplianceService
 * aggregation read) can be scoped to a single fiscal year without each
 * caller re-deriving the boundary (REQ-BBVW-006).
 *
 * The fiscal year is derived from the Administration record's
 * `fiscalYearStartMonth` + `fiscalYearStartDay` (REQ-MA-002) — for the
 * default calendar-year configuration (`startMonth=1`, `startDay=1`) the
 * fiscal year is the calendar year; for waterschappen with a non-calendar
 * boekjaar (e.g. start July 1) the fiscal year shifts so July-December
 * belong to the upcoming year (matching the Dutch waterschap practice).
 *
 * This service NEVER reaches into GL transactions itself. It is a pure
 * date-window resolver: callers receive `{fiscalYear, startDate, endDate}`
 * and pass those into their own OR filters. The window is inclusive on
 * `startDate` and exclusive on the next fiscal year's start — that gives
 * callers a half-open range they can apply as `date >= startDate AND
 * date < endDate` regardless of whether the schema field is a date or a
 * datetime.
 *
 * Multi-administration isolation (ADR-005) is enforced by delegating
 * accessibility checks to {@see AdministrationContextService::canAccess()}
 * before the window is resolved — a cross-tenant probe returns null
 * (the caller masks the response as 404 per the canonical IDOR pattern).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Active-fiscal-year resolver for the BBV consumers (slices 05–08).
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */
class FiscalYearContextService {
	/**
	 * Construct the service with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy OR ObjectService resolution.
	 * @param AdministrationContextService $administrationContext Admin RBAC + accessibility checks (ADR-005).
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly AdministrationContextService $administrationContext,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the active fiscal-year window for an administration.
	 *
	 * The "current" fiscal year is the one whose half-open
	 * `[startDate, endDate)` window contains the reference instant (the
	 * service "now" when null). For the default calendar-year config the
	 * active FY is the current calendar year; for a July-start non-
	 * calendar boekjaar the active FY rolls over on July 1.
	 *
	 * Returns null when the administration cannot be accessed by the
	 * authenticated user (ADR-005 / IDOR mask) or when the administration
	 * record cannot be loaded — the caller treats that exactly the same
	 * as "no data" and masks the response as 404 / empty envelope.
	 *
	 * @param string $administrationId Administration id (REQ-MA-001).
	 * @param DateTimeInterface|null $now Reference instant; defaults to "now" UTC.
	 *
	 * @return array{fiscalYear:int,startDate:string,endDate:string,administrationId:string}|null
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function resolveActiveWindow(string $administrationId, ?DateTimeInterface $now = null): ?array {
		if ($this->isReadable(administrationId: $administrationId) === false) {
			return null;
		}

		$administration = $this->loadAdministration(administrationId: $administrationId);
		if ($administration === null) {
			return null;
		}

		$reference = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

		return ['administrationId' => $administrationId] + $this->windowFor(administration: $administration, reference: $reference);

	}//end resolveActiveWindow()

	/**
	 * Whether an administration id is given and the caller may read it.
	 *
	 * @param string $administrationId Administration id (REQ-MA-001).
	 *
	 * @return boolean
	 */
	private function isReadable(string $administrationId): bool {
		return $administrationId !== '' && $this->administrationContext->canAccess(administrationId: $administrationId) === true;

	}//end isReadable()

	/**
	 * Resolve the active fiscal-year window for the user's default administration.
	 *
	 * Returns null when the user has no accessible administrations.
	 *
	 * @param DateTimeInterface|null $now Reference instant; defaults to "now".
	 *
	 * @return array{fiscalYear:int,startDate:string,endDate:string,administrationId:string}|null
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function resolveDefaultWindow(?DateTimeInterface $now = null): ?array {
		$context = $this->administrationContext->buildContext();
		$active = $context['activeAdministrationId'] ?? null;
		if (is_string($active) === false || $active === '') {
			return null;
		}

		return $this->resolveActiveWindow(administrationId: $active, now: $now);
	}//end resolveDefaultWindow()

	/**
	 * Compute the fiscal year integer for a given reference instant + administration record.
	 *
	 * Exposed for callers that already have the Administration record loaded (e.g.
	 * the dashboard envelope builder iterating mapped admins) so they do not
	 * re-fetch it. The returned integer matches the *end* calendar year of the
	 * fiscal year for non-calendar boekjaren (matches the "FY 2026" UI label
	 * convention).
	 *
	 * @param array<string,mixed> $administration Administration record.
	 * @param DateTimeInterface|null $now Reference instant.
	 *
	 * @return int Fiscal year integer (e.g. 2026).
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function fiscalYearFor(array $administration, ?DateTimeInterface $now = null): int {
		$reference = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

		return $this->computeFiscalYear(reference: $reference, start: $this->fiscalYearStart(administration: $administration));

	}//end fiscalYearFor()

	/**
	 * Build the half-open `[startDate, endDate)` window for a given FY integer + administration.
	 *
	 * @param array<string,mixed> $administration Administration record.
	 * @param int $fiscalYear FY integer (end calendar year for non-calendar boekjaren).
	 *
	 * @return array{fiscalYear:int,startDate:string,endDate:string}
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function windowForFiscalYear(array $administration, int $fiscalYear): array {
		[$startMonth, $startDay] = $this->fiscalYearStart(administration: $administration);

		// For calendar-year (1/1), startDate=FY-01-01, endDate=(FY+1)-01-01.
		// For July-start, startMonth=7: the FY "ends" in $fiscalYear so it
		// started on (FY-1)-07-01 and ends on FY-07-01 (exclusive).
		$startYear = $fiscalYear;
		if ($startMonth > 1 || ($startMonth === 1 && $startDay > 1)) {
			$startYear = ($fiscalYear - 1);
		}

		return [
			'fiscalYear' => $fiscalYear,
			'startDate' => sprintf('%04d-%02d-%02d', $startYear, $startMonth, $startDay),
			'endDate' => sprintf('%04d-%02d-%02d', ($startYear + 1), $startMonth, $startDay),
		];

	}//end windowForFiscalYear()

	/**
	 * The administration's fiscal-year start month and day, each falling back
	 * to 1 when absent or out of range.
	 *
	 * @param array<string,mixed> $administration Administration record.
	 *
	 * @return array{0:int,1:int} [month, day].
	 */
	private function fiscalYearStart(array $administration): array {
		return [
			$this->coerceBounded(value: ($administration['fiscalYearStartMonth'] ?? 1), max: 12),
			$this->coerceBounded(value: ($administration['fiscalYearStartDay'] ?? 1), max: 31),
		];

	}//end fiscalYearStart()

	/**
	 * Build the active window for an administration record.
	 *
	 * @param array<string,mixed> $administration Administration record.
	 * @param DateTimeInterface $reference Reference instant.
	 *
	 * @return array{fiscalYear:int,startDate:string,endDate:string}
	 */
	private function windowFor(array $administration, DateTimeInterface $reference): array {
		$fiscalYear = $this->fiscalYearFor(administration: $administration, now: $reference);

		return $this->windowForFiscalYear(administration: $administration, fiscalYear: $fiscalYear);

	}//end windowFor()

	/**
	 * Compute the fiscal-year integer for a reference instant and start month/day.
	 *
	 * Calendar-year (startMonth=1, startDay=1): returns the reference's
	 * calendar year. Non-calendar (e.g. July 1): months >= startMonth belong
	 * to the *upcoming* fiscal year (which ends in calendar year+1).
	 *
	 * @param DateTimeInterface $reference Reference instant.
	 * @param array{0:int,1:int} $start Fiscal year start [month (1-12), day (1-31)].
	 *
	 * @return int
	 */
	private function computeFiscalYear(DateTimeInterface $reference, array $start): int {
		$year = (int)$reference->format('Y');
		if ($start === [1, 1]) {
			return $year;
		}

		// Non-calendar fiscal year: if reference is past the start date this
		// calendar year, the FY ends next year; otherwise it ends this year.
		if ($this->reachedStart(reference: $reference, start: $start) === true) {
			return ($year + 1);
		}

		return $year;
	}//end computeFiscalYear()

	/**
	 * Whether the reference lies on or after this calendar year's start date.
	 *
	 * @param DateTimeInterface  $reference Reference instant.
	 * @param array{0:int,1:int} $start     Fiscal year start [month, day].
	 *
	 * @return boolean
	 */
	private function reachedStart(DateTimeInterface $reference, array $start): bool {
		$month = (int)$reference->format('n');
		$day = (int)$reference->format('j');

		return $month > $start[0] || ($month === $start[0] && $day >= $start[1]);
	}//end reachedStart()

	/**
	 * Load an Administration record by id from OpenRegister.
	 *
	 * ⚠️ NOT `['filters' => ['id' => ...]]`.
	 *
	 * `filters` addresses the object's JSON PROPERTIES. The ObjectEntity's
	 * `id` is the entity's own column and is merged into the serialised
	 * output afterwards, so it is not a property the filter can see: that
	 * shape matched zero rows for every value, uuids included, and returned
	 * an empty array rather than raising — so this method returned null for
	 * an administration that exists. `resolveActiveWindow()` then returned
	 * null, the BBV dashboard envelope reported `fiscalYear: null`, and the
	 * FY label (`v-if="scope.fiscalYear"`) never rendered, silently breaking
	 * the fiscal-year inheritance REQ-BBVW-006 specifies.
	 *
	 * The confusing part is that the HTTP list API DOES answer `?id=ADM-001`
	 * — it maps that onto the entity column — so the same lookup looks
	 * healthy when probed over the wire and returns nothing in PHP.
	 *
	 * Resolution mirrors AdministrationContextService::findAdministration():
	 * try the single-object lookup first (which handles a real uuid), then
	 * fall back to the `administrationCode` property, which is the id space
	 * the rest of the app and the e2e fixtures actually use.
	 *
	 * @param string $administrationId Administration id or administrationCode.
	 *
	 * @return array<string,mixed>|null Record or null when unavailable.
	 */
	private function loadAdministration(string $administrationId): ?array {
		try {
			$scoped = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService')->setRegister($this->register())->setSchema('Administration');
			$single = $this->findByUuid(scoped: $scoped, administrationId: $administrationId);
			if ($single !== null) {
				return $single;
			}

			$matches = $scoped->findAll(['filters' => ['administrationCode' => $administrationId], 'limit' => 1]);
		} catch (Throwable $e) {
			$context = ['administrationId' => $administrationId, 'exception' => $e->getMessage()];
			$this->logger->error('FiscalYearContextService: failed to load administration', $context);
			return null;
		}

		return ($this->firstRecord(matches: $matches) ?? $this->unmatched(administrationId: $administrationId));
	}//end loadAdministration()

	/**
	 * The single-object lookup, which handles a real uuid.
	 *
	 * Its own try/catch, deliberately: find() THROWS DoesNotExistException for
	 * anything that is not a uuid — it does not return null — so a shared
	 * catch would swallow the administrationCode fallback.
	 *
	 * @param object $scoped           ObjectService set to the Administration schema.
	 * @param string $administrationId Administration id or administrationCode.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findByUuid(object $scoped, string $administrationId): array|null {
		try {
			return $this->asRecord(candidate: ObjectIdentifier::findScoped(scoped: $scoped, id: $administrationId));
		} catch (Throwable $notAUuid) {
			return null;
		}
	}//end findByUuid()

	/**
	 * The first usable record among the administrationCode matches.
	 *
	 * @param iterable<mixed> $matches findAll() result.
	 *
	 * @return array<string,mixed>|null
	 */
	private function firstRecord(iterable $matches): array|null {
		foreach ($matches as $match) {
			$record = $this->asRecord(candidate: $match);
			if ($record !== null) {
				return $record;
			}
		}

		return null;
	}//end firstRecord()

	/**
	 * Log that the administration genuinely is not resolvable; the caller
	 * turns this into a null fiscal-year window, which the UI renders as a
	 * missing label rather than as an error.
	 *
	 * @param string $administrationId Administration id or administrationCode.
	 *
	 * @return null
	 */
	private function unmatched(string $administrationId): null {
		$this->logger->warning(
			'FiscalYearContextService: no Administration matched; fiscal-year scope will be null',
			['administrationId' => $administrationId]
		);

		return null;
	}//end unmatched()

	/**
	 * Normalise an OpenRegister result entry to a plain record array.
	 *
	 * Results arrive as either plain arrays or ObjectEntity instances
	 * depending on the call shape: findAll() yields a list of either, and
	 * find() returns a single one of either.
	 *
	 * @param mixed $candidate Result entry or single object.
	 *
	 * @return array<string,mixed>|null Record, or null when not usable.
	 */
	private function asRecord(mixed $candidate): ?array {
		$record = $candidate;
		if (is_array($candidate) === false) {
			$record = $this->payloadOf(candidate: $candidate);
		}

		if (is_array($record) === true && $record !== []) {
			return $record;
		}

		return null;
	}//end asRecord()

	/**
	 * An ObjectEntity's getObject() payload, or null for anything else.
	 *
	 * @param mixed $candidate Result entry or single object.
	 *
	 * @return mixed
	 */
	private function payloadOf(mixed $candidate): mixed {
		if (is_object($candidate) === true && method_exists($candidate, 'getObject') === true) {
			return $candidate->getObject();
		}

		return null;
	}//end payloadOf()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
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

	/**
	 * Coerce a value to a whole number in 1..$max (a fiscal-year start month
	 * or day), falling back to 1.
	 *
	 * @param mixed $value Raw input.
	 * @param int   $max   Upper bound (12 for a month, 31 for a day).
	 *
	 * @return int
	 */
	private function coerceBounded(mixed $value, int $max): int {
		$number = $this->wholeNumber(value: $value);
		if ($number !== null && $number >= 1 && $number <= $max) {
			return $number;
		}

		return 1;
	}//end coerceBounded()

	/**
	 * An int, or a string of digits read as one; null for anything else.
	 *
	 * @param mixed $value Raw input.
	 *
	 * @return integer|null
	 */
	private function wholeNumber(mixed $value): int|null {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === true && ctype_digit($value) === true) {
			return (int)$value;
		}

		return null;
	}//end wholeNumber()

}//end class
