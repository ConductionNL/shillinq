<?php

/**
 * Cashflow Export Service
 *
 * Gathers a CashflowForecastHorizon and everything REQ-CF-016 says the export
 * must contain out of OpenRegister, and hands it to
 * {@see CashflowPdfRenderer::renderPdf()}. This is the caller the renderer
 * never had (#865): the renderer existed with ZERO call sites, no route and no
 * button, so a statutory-reporting capability was written and unreachable.
 *
 * ## Scoping, and why the horizon is not addressable
 *
 * `buildHorizonExport()` takes NO identifier. It resolves the most recently
 * rolled horizon belonging to an administration the authenticated caller holds
 * a valid AdministrationMembership for (REQ-MA-001), through
 * {@see AdministrationContextService::accessibleAdministrationIds()}.
 *
 * That is a deliberate design choice rather than an omission: an endpoint that
 * accepted a caller-supplied `horizonId` would need its own per-object
 * authorisation guard, and the dashboard the button lives on
 * (`/cashflow/dashboard`) carries no object context to supply one from. With
 * no identifier crossing the boundary there is no IDOR surface to guard. When
 * a per-horizon export is wanted later it belongs on the horizon DETAIL page,
 * where the guard has an object to check against.
 *
 * ## `filters` addresses JSON properties, and that is the correct use here
 *
 * Every lookup below filters on a declared schema property
 * (`administrationId`, `horizonId`) — never on `id`. `findAll(['filters' =>
 * ['id' => …]])` matches NOTHING for every value, silently, because the
 * entity's `id` is its own column and not a property the filter can see; see
 * {@see \OCA\Shillinq\Util\ObjectIdentifier::findOne()} for the shape to use
 * when a lookup really is by identifier.
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
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Assembles the REQ-CF-016 cashflow PDF export for the caller's current horizon.
 *
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
 */
class CashflowExportService {
	/**
	 * CashflowForecastHorizon schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_HORIZON = 'CashflowForecastHorizon';

	/**
	 * CashflowWeek schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_WEEK = 'CashflowWeek';

	/**
	 * CashflowARProjection schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_AR_PROJECTION = 'CashflowARProjection';

	/**
	 * CashflowRecurring schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_RECURRING = 'CashflowRecurring';

	/**
	 * How many customers REQ-CF-016 §3 asks for ("top 5 by AR balance").
	 *
	 * @var integer
	 */
	private const TOP_CUSTOMERS = 5;

	/**
	 * Construct the export service.
	 *
	 * @param IAppConfig $appConfig App config (OpenRegister register slug).
	 * @param LoggerInterface $logger Logger — never receives a record body.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param AdministrationContextService $administrationContext Membership guard (REQ-MA-001).
	 * @param CashflowPdfRenderer $renderer The document renderer.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AdministrationContextService $administrationContext,
		private readonly CashflowPdfRenderer $renderer,
	) {

	}//end __construct()

	/**
	 * Build the PDF export for the caller's current cashflow horizon (REQ-CF-016).
	 *
	 * @return array{filename:string,mimeType:string,payload:string}|null The
	 *         download envelope, or null when the caller has no accessible
	 *         administration or that administration has no horizon yet. Null is
	 *         NOT an empty document on purpose: a blank export of an
	 *         administration that has never been forecast reads, to a bank, as
	 *         a business with no cashflow.
	 *
	 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
	 */
	public function buildHorizonExport(): ?array {
		$administrationIds = $this->administrationContext->accessibleAdministrationIds();
		if ($administrationIds === []) {
			return null;
		}

		$horizon = $this->currentHorizon(administrationIds: $administrationIds);
		if ($horizon === null) {
			return null;
		}

		$horizonId = (string)($horizon['horizonId'] ?? '');
		$administrationId = (string)($horizon['administrationId'] ?? '');

		return $this->renderer->renderPdf(
			horizon: $horizon,
			weeks: $this->weeksFor(horizonId: $horizonId),
			scenario: null,
			topCustomers: $this->topCustomersFor(horizonId: $horizonId),
			recurringBreakdown: $this->recurringFor(administrationId: $administrationId)
		);

	}//end buildHorizonExport()

	/**
	 * Resolve the most recently rolled horizon across the caller's administrations.
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 *
	 * @return array<string,mixed>|null The horizon, or null when there is none.
	 */
	private function currentHorizon(array $administrationIds): ?array {
		$candidates = [];
		foreach ($administrationIds as $administrationId) {
			foreach ($this->query(schema: self::SCHEMA_HORIZON, filters: ['administrationId' => $administrationId]) as $row) {
				$candidates[] = $row;
			}
		}

		if ($candidates === []) {
			return null;
		}

		usort(
			$candidates,
			static function (array $left, array $right): int {
				return strcmp((string)($right['rolledOn'] ?? ''), (string)($left['rolledOn'] ?? ''));
			}
		);

		return $candidates[0];
	}//end currentHorizon()

	/**
	 * Load a horizon's weeks, ordered by weeknummer (REQ-CF-016 §1).
	 *
	 * The sort is done here rather than being asked of OpenRegister because a
	 * horizon is thirteen rows: the ordering is a property of the report, and
	 * a caller that silently depended on storage order would render the table
	 * out of sequence the first time a week was recomputed.
	 *
	 * @param string $horizonId The horizon's business identifier.
	 *
	 * @return list<array<string,mixed>> The weeks in weeknummer order.
	 */
	private function weeksFor(string $horizonId): array {
		if ($horizonId === '') {
			return [];
		}

		$weeks = $this->query(schema: self::SCHEMA_WEEK, filters: ['horizonId' => $horizonId]);
		usort(
			$weeks,
			static function (array $left, array $right): int {
				return ((int)($left['weekNumber'] ?? 0) <=> (int)($right['weekNumber'] ?? 0));
			}
		);

		return $weeks;
	}//end weeksFor()

	/**
	 * Build the top-5-by-AR-balance customer section (REQ-CF-016 §3).
	 *
	 * ⚠️ The key mapping below is not cosmetic. `CashflowARProjection` declares
	 * `payment_history_average_deviation`, while the renderer — written against
	 * the design document, and never called until now — reads
	 * `gemiddeldeAfwijking`. Adapting here is the narrow fix: renaming the
	 * renderer's read key changes a published input contract that its own tests
	 * pin, and renaming the schema property is a data migration.
	 *
	 * @param string $horizonId The horizon's business identifier.
	 *
	 * @return list<array<string,mixed>> Up to five rows in the renderer's input shape.
	 */
	private function topCustomersFor(string $horizonId): array {
		if ($horizonId === '') {
			return [];
		}

		$projections = $this->query(schema: self::SCHEMA_AR_PROJECTION, filters: ['horizonId' => $horizonId]);

		$byCustomer = [];
		foreach ($projections as $projection) {
			$customerId = (string)($projection['customerId'] ?? '');
			if ($customerId === '') {
				continue;
			}

			if (isset($byCustomer[$customerId]) === false) {
				$byCustomer[$customerId] = [
					'customerId' => $customerId,
					'outstandingAmount' => 0.0,
					'gemiddeldeAfwijking' => (string)($projection['payment_history_average_deviation'] ?? '?'),
					'reliabilityScore' => (float)($projection['reliabilityScore'] ?? 0),
				];
			}

			$byCustomer[$customerId]['outstandingAmount'] += (float)($projection['outstandingAmount'] ?? 0);
		}

		$rows = array_values($byCustomer);
		usort(
			$rows,
			static function (array $left, array $right): int {
				return ($right['outstandingAmount'] <=> $left['outstandingAmount']);
			}
		);

		return array_slice($rows, 0, self::TOP_CUSTOMERS);
	}//end topCustomersFor()

	/**
	 * Load the administration's recurring cost registry (REQ-CF-016 §3).
	 *
	 * @param string $administrationId The horizon's administration.
	 *
	 * @return list<array<string,mixed>> The recurring rows.
	 */
	private function recurringFor(string $administrationId): array {
		if ($administrationId === '') {
			return [];
		}

		return $this->query(schema: self::SCHEMA_RECURRING, filters: ['administrationId' => $administrationId]);
	}//end recurringFor()

	/**
	 * Run one property-filtered query against the shillinq register.
	 *
	 * A failure is logged and answered as an empty result set rather than
	 * propagated: a missing recurring registry must not stop the horizon
	 * summary from reaching the bank.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Property filters (never `id`).
	 *
	 * @return list<array<string,mixed>> The matching records as plain arrays.
	 */
	private function query(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (Throwable $e) {
			$this->logger->error(
				'CashflowExportService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$payload = $row->getObject();
				if (is_array($payload) === true) {
					$result[] = $payload;
				}
			}
		}

		return $result;
	}//end query()

	/**
	 * Resolve the OpenRegister register slug from app config.
	 *
	 * @return string The register slug, defaulting to `shillinq`.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
