<?php

/**
 * Compliance Export Service.
 *
 * Backs the REQ-RAP-005 Compliance Export API surface — the manifest
 * entry `BookkeepingComplianceExport` (form binding) and the
 * `ComplianceExportController` (GET /api/audit/export) both call this
 * service to query the OpenRegister audit-trail in a date range,
 * strip PII fields per the REQ-RAP-005 exclusion list, and render the
 * filtered events as CSV / JSON / XLSX-shaped JSON.
 *
 * The service NEVER writes audit events — every event flows through
 * OpenRegister's audit-trail-immutable channel per ADR-022. This
 * service is a READ-FILTER-RENDER pipeline; logging of the export
 * request itself (per REQ-RAP-005 scenario 3 — "Export audit trail
 * is itself audited") is performed by the controller via the OR
 * audit-trail API so the export operation is captured on the same
 * chain it queries.
 *
 * The service also supports REQ-RAP-009 (GDPR / AVG subject-access
 * filtering): when called with `scope=subject` and an `actorFilter`,
 * the result set is narrowed to events where the actor matches the
 * filter. PII exclusion is identical in both scopes — there is no
 * "give me everything" escape hatch.
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
 * @spec openspec/specs/bookkeeping-rekenkamer-audit-pack/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Read-filter-render pipeline over the OR audit-trail.
 *
 * Public methods:
 *  - generateExport(): query OR audit-trail in [from, to] and render
 *    as CSV / JSON. The return shape is a list of PII-filtered rows
 *    plus a header for CSV rendering.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ElseExpression)         Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 *
 * @spec openspec/specs/bookkeeping-rekenkamer-audit-pack/spec.md
 */
class ComplianceExportService {

	/**
	 * PII field names that MUST be stripped before rendering per
	 * REQ-RAP-005 + REQ-RAP-009 — applied recursively to before/after
	 * snapshots.
	 *
	 * @var array<int,string>
	 */
	public const PII_FIELDS = [
		'email',
		'phone',
		'address',
		'displayName',
		'firstName',
		'lastName',
		'birthDate',
		'socialSecurityNumber',
		'taxId',
		'personId',
		'ipAddress',
	];

	/**
	 * Default scope when the caller omits the parameter.
	 *
	 * @var string
	 */
	public const SCOPE_ALL = 'all';

	/**
	 * GDPR subject-access scope per REQ-RAP-009.
	 *
	 * @var string
	 */
	public const SCOPE_SUBJECT = 'subject';

	/**
	 * Default format when the caller omits the parameter.
	 *
	 * @var string
	 */
	public const FORMAT_CSV = 'csv';

	/**
	 * JSON output format.
	 *
	 * @var string
	 */
	public const FORMAT_JSON = 'json';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR's
	 *                                      ObjectService is fetched
	 *                                      lazily so unit tests can
	 *                                      swap an in-memory stub.
	 * @param IAppConfig $appConfig App config for the OR
	 *                              register slug.
	 * @param IUserSession $userSession Session used as the
	 *                                  default actor when
	 *                                  no explicit actorFilter
	 *                                  is supplied with scope=subject.
	 * @param LoggerInterface $logger Logger (no PII payloads).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Build the PII-filtered audit-trail export envelope.
	 *
	 * @param string $from ISO date YYYY-MM-DD (inclusive).
	 * @param string $to ISO date YYYY-MM-DD (inclusive).
	 * @param string $scope ComplianceExportService::SCOPE_ALL
	 *                      or ::SCOPE_SUBJECT.
	 * @param string $format ::FORMAT_CSV or ::FORMAT_JSON.
	 * @param string|null $actorFilter When scope=subject, restrict
	 *                                 to events where the actor
	 *                                 matches. Defaults to the
	 *                                 current session UID.
	 *
	 * @return array{
	 *     from:string,
	 *     to:string,
	 *     scope:string,
	 *     format:string,
	 *     actorFilter:?string,
	 *     generatedAt:string,
	 *     generatedBy:string,
	 *     eventCount:int,
	 *     headers:array<int,string>,
	 *     rows:array<int,array<string,mixed>>
	 * }
	 *
	 * @throws RuntimeException When [from, to] is invalid.
	 */
	public function generateExport(
		string $from,
		string $to,
		string $scope = self::SCOPE_ALL,
		string $format = self::FORMAT_CSV,
		?string $actorFilter = null,
	): array {
		$this->assertDateRange(from: $from, to: $to);

		if ($scope !== self::SCOPE_ALL && $scope !== self::SCOPE_SUBJECT) {
			throw new RuntimeException(message: 'Invalid scope (must be all or subject)');
		}

		if ($format !== self::FORMAT_CSV && $format !== self::FORMAT_JSON) {
			throw new RuntimeException(message: 'Invalid format (must be csv or json)');
		}

		if ($scope === self::SCOPE_SUBJECT && $actorFilter === null) {
			$actorFilter = $this->currentUserId();
		}

		$rawEvents = $this->queryAuditTrail(from: $from, to: $to);

		$rows = [];
		foreach ($rawEvents as $event) {
			if (is_array($event) === false) {
				continue;
			}

			if ($scope === self::SCOPE_SUBJECT
				&& $actorFilter !== null
				&& (string)($event['user'] ?? ($event['actor'] ?? '')) !== $actorFilter
			) {
				continue;
			}

			$rows[] = $this->renderRow(event: $event);
		}

		$generatedAt = date(format: 'c');
		$generatedBy = $this->currentUserId();

		return [
			'from' => $from,
			'to' => $to,
			'scope' => $scope,
			'format' => $format,
			'actorFilter' => $actorFilter,
			'generatedAt' => $generatedAt,
			'generatedBy' => $generatedBy,
			'eventCount' => count($rows),
			'headers' => [
				'timestamp',
				'objectType',
				'objectId',
				'action',
				'actor',
				'fields_changed',
				'beforeValue',
				'afterValue',
			],
			'rows' => $rows,
		];

	}//end generateExport()

	/**
	 * Render the in-memory export as a single CSV blob (RFC 4180).
	 *
	 * @param array{headers:array<int,string>,rows:array<int,array<string,mixed>>} $envelope The envelope from generateExport().
	 *
	 * @return string The CSV blob.
	 */
	public function renderCsv(array $envelope): string {
		$output = fopen(filename: 'php://temp', mode: 'r+');
		if ($output === false) {
			return '';
		}

		fputcsv(stream: $output, fields: $envelope['headers']);
		foreach ($envelope['rows'] as $row) {
			$line = [];
			foreach ($envelope['headers'] as $header) {
				$value = ($row[$header] ?? '');
				if (is_array($value) === true) {
					$line[] = (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				} else {
					$line[] = (string)$value;
				}
			}

			fputcsv(stream: $output, fields: $line);
		}

		rewind(stream: $output);
		$blob = (string)stream_get_contents(stream: $output);
		fclose(stream: $output);

		return $blob;
	}//end renderCsv()

	/**
	 * Strip every PII field from a value (recursive for arrays).
	 * Public so the controller can re-apply the rule on any free-form
	 * metadata it surfaces (defence in depth).
	 *
	 * @param mixed $value The value (scalar | array | nested array).
	 *
	 * @return mixed The stripped value.
	 */
	public function stripPii(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$clean = [];
		foreach ($value as $key => $sub) {
			if (is_string($key) === true && in_array($key, self::PII_FIELDS, true) === true) {
				continue;
			}

			$clean[$key] = $this->stripPii(value: $sub);
		}

		return $clean;
	}//end stripPii()

	/**
	 * Query the OR audit-trail API (lazily resolved) for events in [from, to].
	 *
	 * The query intentionally targets the audit-trail endpoint that the
	 * BookkeepingAuditTrail manifest page already consumes; this keeps
	 * the pipeline aligned with the UI's view and avoids divergent
	 * filter semantics.
	 *
	 * @param string $from ISO date YYYY-MM-DD.
	 * @param string $to ISO date YYYY-MM-DD.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function queryAuditTrail(string $from, string $to): array {
		try {
			$auditService = $this->container->get('OCA\OpenRegister\Service\AuditTrailService');
		} catch (\Throwable $e) {
			$this->logger->info(
				'ComplianceExportService: OR AuditTrailService not resolvable — returning empty result',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		try {
			if (method_exists($auditService, 'findInRange') === true) {
				// @phpstan-ignore-next-line - cross-app OR AuditTrailService; method exists at runtime
				$rows = $auditService->findInRange(
					$this->register(),
					$from . 'T00:00:00Z',
					$to . 'T23:59:59Z'
				);
			} elseif (method_exists($auditService, 'findAll') === true) {
				$rows = $auditService->findAll(
					[
						'filters' => [
							'register' => $this->register(),
							'created' => ['>=' => $from . 'T00:00:00Z', '<=' => $to . 'T23:59:59Z'],
						],
					]
				);
			} else {
				$this->logger->warning(
					'ComplianceExportService: OR AuditTrailService has no findInRange/findAll method'
				);
				return [];
			}//end if
		} catch (\Throwable $e) {
			$this->logger->error(
				'ComplianceExportService: failed to query OR audit-trail',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try

		$result = [];
		foreach ((array)$rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$result[] = (array)$row->jsonSerialize();
				continue;
			}

			if (is_object($row) === true) {
				$result[] = json_decode((string)json_encode($row), true) ?? [];
			}
		}

		return $result;
	}//end queryAuditTrail()

	/**
	 * Convert one OR audit-trail event into the PII-filtered export row.
	 *
	 * @param array<string,mixed> $event Raw OR audit-trail event.
	 *
	 * @return array<string,mixed>
	 */
	private function renderRow(array $event): array {
		$before = $this->stripPii(value: ($event['before'] ?? ($event['beforeValue'] ?? [])));
		$after = $this->stripPii(value: ($event['after'] ?? ($event['afterValue'] ?? [])));

		$fieldsChanged = [];
		if (is_array($before) === true && is_array($after) === true) {
			$keys = array_unique(array_merge(array_keys($before), array_keys($after)));
			foreach ($keys as $key) {
				if (in_array($key, self::PII_FIELDS, true) === true) {
					continue;
				}

				if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
					$fieldsChanged[] = (string)$key;
				}
			}
		}

		return [
			'timestamp' => (string)($event['created'] ?? ($event['timestamp'] ?? '')),
			'objectType' => (string)($event['schema'] ?? ($event['objectType'] ?? '')),
			'objectId' => (string)($event['objectId'] ?? ($event['object'] ?? '')),
			'action' => (string)($event['action'] ?? ''),
			'actor' => (string)($event['user'] ?? ($event['actor'] ?? '')),
			'fields_changed' => $fieldsChanged,
			'beforeValue' => $before,
			'afterValue' => $after,
		];

	}//end renderRow()

	/**
	 * Validate the [from, to] window — both dates required, ISO format,
	 * `from <= to`.
	 *
	 * @param string $from ISO date YYYY-MM-DD.
	 * @param string $to ISO date YYYY-MM-DD.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the window is invalid.
	 */
	private function assertDateRange(string $from, string $to): void {
		if ($from === '' || $to === '') {
			throw new RuntimeException(message: 'from and to are required (YYYY-MM-DD)');
		}

		if (preg_match(pattern: '/^\d{4}-\d{2}-\d{2}$/', subject: $from) !== 1
			|| preg_match(pattern: '/^\d{4}-\d{2}-\d{2}$/', subject: $to) !== 1
		) {
			throw new RuntimeException(message: 'from and to MUST be ISO dates (YYYY-MM-DD)');
		}

		if (strcmp(string1: $from, string2: $to) > 0) {
			throw new RuntimeException(message: 'from MUST be <= to');
		}

	}//end assertDateRange()

	/**
	 * Resolve the current user id, falling back to `system` when no
	 * session is bound.
	 *
	 * @return string
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * Resolve the OR register slug from app config (defaults to "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
