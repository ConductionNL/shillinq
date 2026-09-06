<?php

/**
 * Mandate Enforcer
 *
 * ADR-031 exception-path lifecycle guard for the Commitment commitment
 * transitions. Mandate-checking is context-specific (amount + soort + effective
 * date + second-signature threshold), which the declarative lifecycle DSL cannot
 * express, so it lives in this thin guard referenced from the schema's
 * x-openregister-lifecycle transitions in
 * lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json.
 *
 * Responsibilities (REQ-VPL-002):
 *   - requiresApproval(): true when no valid mandaat covers the commitment, so the
 *     `indienen` transition routes the verplichting to in_approval.
 *   - hasSufficientMandate(): true when a valid mandaat covers the commitment, so the
 *     `aangaan` transition may proceed (within budget, checked by BudgetBlocker).
 *   - resolveApplicableMandate(): the matching mandaat, or null.
 *   - requiresSecondSignature(): whether a second signature is needed above the
 *     mandaat's threshold.
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
 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Mandate-check precondition helpers for the Commitment schema (REQ-VPL-002).
 *
 * Fail-closed: when mandate sufficiency cannot be established the commitment is
 * treated as NOT sufficiently mandated (it must go through approval), never
 * silently signed (CWE-863 / OWASP A01:2021).
 *
 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.3
 */
class MandateEnforcer {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Precondition for the `aangaan` transition: does a valid mandaat cover this
	 * commitment (REQ-VPL-002)?
	 *
	 * Fail-closed: returns false on any exception or when no mandaat applies.
	 *
	 * @param string $commitmentNumber The verplichting identifier (lifecycle-engine call parity).
	 * @param array<string,mixed>|null $object The Commitment object being transitioned.
	 *
	 * @return bool True when a valid mandaat covers the commitment amount and soort.
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.3
	 */
	public function hasSufficientMandate(string $commitmentNumber, ?array $object = null): bool {
		try {
			$commitment = ($object ?? $this->findOne(schema: 'Commitment', filters: ['commitmentNumber' => $commitmentNumber]));
			if ($commitment === null) {
				return false;
			}

			return $this->resolveApplicableMandate(commitment: $commitment) !== null;
		} catch (\Throwable $e) {
			$this->logger->error(
				'MandateEnforcer: hasSufficientMandate failed — treating as not mandated (fail-closed)',
				['commitment' => $commitmentNumber, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end hasSufficientMandate()

	/**
	 * Precondition for the `indienen` transition: does this commitment need to go
	 * through an approval chain because no valid mandaat covers it (REQ-VPL-002)?
	 *
	 * The inverse of {@see self::hasSufficientMandate()}. Fail-open toward approval:
	 * any error routes the commitment to in_approval rather than letting it skip
	 * authorization.
	 *
	 * @param string $commitmentNumber The verplichting identifier.
	 * @param array<string,mixed>|null $object The Commitment object being transitioned.
	 *
	 * @return bool True when approval is required (mandate insufficient or absent).
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.3
	 */
	public function requiresApproval(string $commitmentNumber, ?array $object = null): bool {
		return $this->hasSufficientMandate(commitmentNumber: $commitmentNumber, object: $object) === false;
	}//end requiresApproval()

	/**
	 * Resolve the mandaat that validly covers a commitment, or null.
	 *
	 * A mandaat applies when ALL hold (REQ-VPL-002):
	 *   - it is currently valid (geldig_van <= today <= geldig_tot, where set);
	 *   - its soort_verplichting array contains the commitment's soort (empty array = any soort);
	 *   - its maximumbedrag is >= the commitment's totaalbedrag_excl_btw;
	 *   - administrationId matches (tenant isolation).
	 *
	 * Among applicable mandaten the one with the lowest sufficient ceiling is
	 * returned (least-privilege), preferring non-override mandates.
	 *
	 * @param array<string,mixed> $commitment The commitment being signed.
	 *
	 * @return array<string,mixed>|null The applicable mandaat record, or null.
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.3
	 */
	public function resolveApplicableMandate(array $commitment): ?array {
		$kind = (string)($commitment['kind'] ?? '');
		$amount = (int)($commitment['total_amount_excl_vat'] ?? 0);
		$admin = (string)($commitment['administrationId'] ?? '');

		$mandaten = $this->findMany(schema: 'SpendingMandate', filters: ['administrationId' => $admin]);

		$best = null;
		foreach ($mandaten as $mandate) {
			if ($this->mandateApplies(mandate: $mandate, kind: $kind, amount: $amount) === false) {
				continue;
			}

			if ($best === null) {
				$best = $mandate;
				continue;
			}

			// Prefer non-override, then the lowest sufficient ceiling (least-privilege).
			$bestOverride = (bool)($best['is_override'] ?? false);
			$candOverride = (bool)($mandate['is_override'] ?? false);
			if ($bestOverride !== $candOverride) {
				if ($candOverride === false) {
					$best = $mandate;
				}

				continue;
			}

			if ((int)($mandate['maximumAmount'] ?? 0) < (int)($best['maximumAmount'] ?? 0)) {
				$best = $mandate;
			}
		}//end foreach

		return $best;
	}//end resolveApplicableMandate()

	/**
	 * Whether a commitment requires a second signature under the applicable mandaat
	 * (REQ-VPL-002).
	 *
	 * True when the applicable mandaat declares vereist_tweede_handtekening_boven and
	 * the commitment amount meets or exceeds it.
	 *
	 * @param array<string,mixed> $commitment The commitment being signed.
	 *
	 * @return bool True when a second signature is required.
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.3
	 */
	public function requiresSecondSignature(array $commitment): bool {
		$mandate = $this->resolveApplicableMandate(commitment: $commitment);
		if ($mandate === null) {
			return false;
		}

		$threshold = ($mandate['required_second_signature_above'] ?? null);
		if ($threshold === null) {
			return false;
		}

		return (int)($commitment['total_amount_excl_vat'] ?? 0) >= (int)$threshold;
	}//end requiresSecondSignature()

	/**
	 * Evaluate whether a single mandaat covers a commitment of the given soort and amount.
	 *
	 * @param array<string,mixed> $mandate The mandaat record.
	 * @param string $kind The commitment soort.
	 * @param int $amount The commitment amount in minor units.
	 *
	 * @return bool True when the mandaat is valid, covers the soort, and the ceiling suffices.
	 */
	private function mandateApplies(array $mandate, string $kind, int $amount): bool {
		if ($this->isCurrentlyValid(mandate: $mandate) === false) {
			return false;
		}

		$soorten = ($mandate['kind_commitment'] ?? []);
		if (is_array($soorten) === true && count($soorten) > 0 && in_array($kind, $soorten, true) === false) {
			return false;
		}

		return (int)($mandate['maximumAmount'] ?? 0) >= $amount;
	}//end mandateApplies()

	/**
	 * Whether a mandaat is valid as of today (REQ-VPL-002). Expired or not-yet-valid
	 * mandates are treated as absent.
	 *
	 * @param array<string,mixed> $mandate The mandaat record.
	 *
	 * @return bool True when today falls within geldig_van..geldig_tot (inclusive).
	 */
	private function isCurrentlyValid(array $mandate): bool {
		$today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');

		$from = (string)($mandate['valid_from'] ?? '');
		if ($from !== '' && $from > $today) {
			return false;
		}

		$tot = (string)($mandate['valid_to'] ?? '');
		if ($tot !== '' && $tot < $today) {
			return false;
		}

		return true;
	}//end isCurrentlyValid()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Find a single record by exact-match filters in the configured register.
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $filters Exact-match filters.
	 *
	 * @return array<string, mixed>|null First matching record, or null.
	 */
	private function findOne(string $schema, array $filters): ?array {
		$result = $this->findMany(schema: $schema, filters: $filters, limit: 1);
		if (count($result) === 0) {
			return null;
		}

		return reset($result);
	}//end findOne()

	/**
	 * Find records by exact-match filters in the configured register.
	 *
	 * Returns an empty array when the schema is not yet available (dependency not
	 * seeded), keeping the guard usable before sibling registers are merged.
	 * Uses the real OpenRegister ObjectService fluent API (ADR-022):
	 * setRegister/setSchema/findAll — findObjects()/findObject() do not exist.
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $filters Exact-match filters.
	 * @param int $limit Maximum records to return (0 = no explicit limit).
	 *
	 * @return array<int, array<string, mixed>> Matching records (possibly empty).
	 */
	private function findMany(string $schema, array $filters, int $limit = 0): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$query = ['filters' => $filters];
			if ($limit > 0) {
				$query['limit'] = $limit;
			}

			$result = $objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: $schema)
				->findAll($query);

			if (is_array($result) === false) {
				return [];
			}

			return array_values($result);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'MandateEnforcer: schema lookup unavailable — treating as absent',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end findMany()
}//end class
