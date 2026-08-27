<?php

/**
 * Annual Budget Default Guard
 *
 * ADR-031 exception-path lifecycle guard for the `AnnualBudget` schema's
 * `activate` transition (`draft` -> `active`,
 * `lib/Settings/register.d/budget-core-schema.json`, REQ-BCS-006). Enforces
 * the one-default invariant `budget-core-schema`'s design aligns now rather
 * than retrofits (`openspec/changes/budget-core-schema/design.md` §4b): at
 * most one `AnnualBudget` may carry `isDefault=true` for a given
 * `administrationId` + `fiscalYear` pair. The follow-up `budget-scenarios`
 * change (multiple non-default `AnnualBudget`s per fiscal year, what-if
 * deltas) depends on this invariant already holding rather than needing to
 * retrofit it.
 *
 * Registered under its literal `Class::method` tag in `Application.php`
 * (`register()`, via the shared `RegisterRequiresGuardAdapter`) — a
 * `requires` string containing `::` can never resolve through Nextcloud's
 * container without an explicit alias (shillinq#425/#433's documented
 * defect class); this guard is registered the same way every other guard
 * added since that fix has been.
 *
 * ADR-031 exception reason: the check is a cross-object uniqueness
 * constraint (does any OTHER sibling `AnnualBudget` for the same
 * administration + fiscal year already claim `isDefault`) — a declarative
 * lifecycle DSL cannot express a query across sibling objects, matching
 * every other guard already in this codebase's exception-path inventory
 * (e.g. `BudgetBlocker`, `DualGaapGuard`, `RateScheduleOverlapGuard`).
 *
 * @category Guard
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards `AnnualBudget.activate` — no other `AnnualBudget` with
 * `isDefault=true` may already exist for the same `administrationId` +
 * `fiscalYear` (REQ-BCS-006).
 *
 * Fail-closed: any lookup exception denies activation (CWE-863 / OWASP
 * A01:2021).
 *
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-006
 */
class AnnualBudgetDefaultGuard {
	/**
	 * The AnnualBudget schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_ANNUAL_BUDGET = 'AnnualBudget';

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Precondition for `activate`: no OTHER `AnnualBudget` with
	 * `isDefault=true` may already exist for the same `administrationId` +
	 * `fiscalYear`.
	 *
	 * A budget not claiming `isDefault` needs no uniqueness check at all —
	 * `state` (editability) and `isDefault` (which one wins) are
	 * deliberately separate axes (`design.md` §4b), so a non-default budget
	 * may always activate regardless of how many siblings exist.
	 *
	 * @param array<string, mixed> $budget The AnnualBudget object being transitioned.
	 *
	 * @return bool True when activation may proceed.
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-006
	 */
	public function isUniqueDefault(array $budget): bool {
		$claimsDefault = (($budget['isDefault'] ?? false) === true);
		if ($claimsDefault === false) {
			return true;
		}

		$administrationId = (string)($budget['administrationId'] ?? '');
		$fiscalYear = (int)($budget['fiscalYear'] ?? 0);

		try {
			$siblings = $this->objectService
				->setRegister($this->register())
				->setSchema(self::SCHEMA_ANNUAL_BUDGET)
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'fiscalYear' => $fiscalYear,
							'isDefault' => true,
						],
					]
				);

			$currentId = ($budget['id'] ?? $budget['@self']['id'] ?? null);
			foreach ($siblings as $sibling) {
				$siblingId = ($sibling['id'] ?? $sibling['@self']['id'] ?? null);
				if ($currentId !== null && $siblingId === $currentId) {
					// The record being activated itself — not a conflict.
					continue;
				}

				$this->logger->info(
					'AnnualBudgetDefaultGuard: another default AnnualBudget already exists — denying activate',
					[
						'administrationId' => $administrationId,
						'fiscalYear' => $fiscalYear,
						'conflictingId' => $siblingId,
					]
				);
				return false;
			}

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'AnnualBudgetDefaultGuard: isUniqueDefault check failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isUniqueDefault()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
