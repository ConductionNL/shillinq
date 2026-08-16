<?php

/**
 * Intercompany Tolerance Guard
 *
 * ADR-031 exception-path lifecycle guard for the IntercompanyMatch create
 * transition (REQ-ICE-004). Evaluates a match's mismatch against the configured
 * ToleranceRule for the relation type and administration, returning true when the
 * mismatch is within tolerance (perfect-match or within-tolerance) and false when
 * it falls outside tolerance and must be escalated to the exception queue.
 *
 * ADR-031 exception reason: the tolerance decision combines the match's own
 * mismatchAmount/mismatchPercentage with a cross-schema lookup of the applicable
 * ToleranceRule and a per-rule combination strategy (max-of / min-of / absolute-only),
 * which is not yet expressible in OpenRegister's declarative lifecycle DSL. When the
 * engine gains that capability, replace this reference with a declarative condition
 * and delete this file.
 *
 * Uses the real OpenRegister ObjectService API only (findAll). The methods
 * findObject/createFromArray are not part of the API (ADR-022).
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-13
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for intercompany-match tolerance evaluation.
 *
 * Referenced from the IntercompanyMatch schema's x-openregister-lifecycle
 * transitions.create.requires as
 * OCA\Shillinq\Lifecycle\IntercompanyToleranceGuard::isWithinTolerance.
 *
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-13
 */
class IntercompanyToleranceGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
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
	 * Return the configured register slug, falling back to 'shillinq' if unset.
	 *
	 * @return string The register slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Returns true iff the match's mismatch is within the applicable tolerance.
	 *
	 * A perfect match (mismatchAmount == 0) is always within tolerance. A one-sided
	 * match (matchStatus one-sided-A/one-sided-B) is never within tolerance — it must
	 * wait for the counterparty booking. Otherwise the applicable ToleranceRule is
	 * resolved (relation-type-specific rule first, then the all-types rule for the
	 * administration) and the mismatch is evaluated against its absolute and relative
	 * thresholds using the rule's combination method. When no rule is configured, a
	 * conservative default is applied (absolute EUR 10, relative 0.5%, max-of).
	 *
	 * Fail-closed: returns false on any exception so a match is never silently
	 * stamped within-tolerance during an infrastructure outage (REQ-ICE-004 / CWE-863).
	 *
	 * @param array<string, mixed> $match The IntercompanyMatch object array.
	 *
	 * @return bool True when the mismatch is within tolerance.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-13
	 */
	public function isWithinTolerance(array $match): bool {
		$status = (string)($match['matchStatus'] ?? '');
		if ($status === 'one-sided-A' || $status === 'one-sided-B') {
			return false;
		}

		$mismatchAmount = (float)($match['mismatchAmount'] ?? 0.0);
		if (abs($mismatchAmount) < 0.005) {
			// Perfect match within half a cent — always acceptable.
			return true;
		}

		try {
			$rule = $this->resolveToleranceRule(
				relationId: (string)($match['relationId'] ?? ''),
				administrationId: (string)($match['administrationId'] ?? '')
			);

			$absolute = (float)($rule['toleranceAbsolute'] ?? 10.0);
			$relative = (float)($rule['toleranceRelative'] ?? 0.5);
			$method = (string)($rule['toleranceMethod'] ?? 'max-of-absolute-relative');

			$largerSide = max(
				abs((float)($match['totalAmountA'] ?? 0.0)),
				abs((float)($match['totalAmountB'] ?? 0.0))
			);

			$relativePercentage = (float)($match['mismatchPercentage'] ?? 0.0);
			if ($relativePercentage === 0.0 && $largerSide > 0.0) {
				$relativePercentage = (abs($mismatchAmount) / $largerSide) * 100.0;
			}

			$absolutePass = abs($mismatchAmount) <= $absolute;
			$relativePass = $relativePercentage <= $relative;

			return $this->combine(
				method: $method,
				absolutePass: $absolutePass,
				relativePass: $relativePass
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'IntercompanyToleranceGuard: tolerance evaluation failed — denying within-tolerance (fail-closed)',
				['matchId' => ($match['matchId'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isWithinTolerance()

	/**
	 * Combine the absolute and relative pass flags per the rule's method.
	 *
	 * @param string $method The tolerance combination method.
	 * @param bool $absolutePass Whether the absolute threshold passed.
	 * @param bool $relativePass Whether the relative threshold passed.
	 *
	 * @return bool True when the combined tolerance passes.
	 */
	private function combine(string $method, bool $absolutePass, bool $relativePass): bool {
		if ($method === 'absolute-only') {
			return $absolutePass;
		}

		if ($method === 'min-of-absolute-relative') {
			// Both thresholds must pass (the stricter interpretation).
			return $absolutePass === true && $relativePass === true;
		}

		// Default max-of-absolute-relative: either threshold passing is enough.
		return $absolutePass === true || $relativePass === true;
	}//end combine()

	/**
	 * Resolve the applicable ToleranceRule for a relation within an administration.
	 *
	 * Looks up the IntercompanyRelation to learn its relation type, then prefers a
	 * ToleranceRule whose relationTypeFilter matches that type; failing that, an
	 * all-types rule (no relationTypeFilter) for the administration. Returns an empty
	 * array when nothing is configured (callers apply conservative defaults).
	 *
	 * @param string $relationId The IntercompanyRelation id.
	 * @param string $administrationId The administration (tenant) id.
	 *
	 * @return array<string, mixed> The matching rule, or an empty array.
	 */
	private function resolveToleranceRule(string $relationId, string $administrationId): array {
		if ($administrationId === '') {
			return [];
		}


		$relationType = $this->lookupRelationType(
			objectService: $this->objectService,
			relationId: $relationId,
			administrationId: $administrationId
		);

		$rules = $this->objectService
			->setRegister($this->getRegisterSlug())
			->setSchema('ToleranceRule')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		return $this->selectRule(rules: $rules, relationType: $relationType);
	}//end resolveToleranceRule()

	/**
	 * Look up the relation type for a relation within an administration.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $relationId The IntercompanyRelation id.
	 * @param string $administrationId The administration (tenant) id.
	 *
	 * @return string The relation type, or an empty string when unknown.
	 */
	private function lookupRelationType(object $objectService, string $relationId, string $administrationId): string {
		if ($relationId === '') {
			return '';
		}

		$relations = $objectService
			->setRegister($this->getRegisterSlug())
			->setSchema('IntercompanyRelation')
			->findAll(['filters' => ['relationId' => $relationId, 'administrationId' => $administrationId]]);

		$relation = ($relations[0] ?? null);
		if ($relation === null) {
			return '';
		}

		return (string)($relation['relationType'] ?? '');
	}//end lookupRelationType()

	/**
	 * Select the applicable rule: a relation-type-specific rule wins over an all-types rule.
	 *
	 * @param array<int, array<string, mixed>> $rules The candidate ToleranceRule rows.
	 * @param string $relationType The relation type to match.
	 *
	 * @return array<string, mixed> The selected rule, or an empty array when none match.
	 */
	private function selectRule(array $rules, string $relationType): array {
		$allTypes = null;
		foreach ($rules as $rule) {
			$filter = ($rule['relationTypeFilter'] ?? null);
			if ($relationType !== '' && $filter === $relationType) {
				return $rule;
			}

			if (($filter === null || $filter === '') && $allTypes === null) {
				$allTypes = $rule;
			}
		}

		return ($allTypes ?? []);
	}//end selectRule()
}//end class
