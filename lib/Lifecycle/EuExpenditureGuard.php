<?php

/**
 * EU Expenditure Guard
 *
 * ADR-031 exception-path lifecycle guards for the EuExpenditure register
 * (bookkeeping-single-audit-eu-fondsen, T3 regulatory + compliance). Two
 * preconditions are referenced from the EuExpenditure schema's
 * x-openregister-lifecycle transitions because they require cross-schema
 * lookups (EligibilityRule, SupportingDocument) that OpenRegister's
 * declarative `requires:` clause cannot yet express:
 *
 *  - canDeclare(): the expenditure's cost_category must be eligible for the
 *                  project's fonds per an active EligibilityRule, and the
 *                  expenditure must be marked eligibilityConfirmed before it
 *                  may be declared (REQ-EUF-011).
 *  - canSubmit():  every verplicht bewijsstuk for the cost_category must be
 *                  present, and — when the expenditure is aanbestedingsplichtig
 *                  (procurementRequired) — an aanbestedingsdossier
 *                  SupportingDocument must be linked, before the declaration
 *                  may be submitted to the managementautoriteit (REQ-EUF-004,
 *                  REQ-EUF-005).
 *
 * ADR-031 exception reason: cross-schema membership checks and verplichte-
 * stukken completeness are not yet expressible in the declarative lifecycle
 * DSL. When the engine gains those capabilities, replace these references with
 * declarative conditions and delete this file.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for EuExpenditure declare and submit transitions.
 *
 * Referenced from the EuExpenditure schema (register.d fragment)
 * x-openregister-lifecycle transitions.declare.requires as
 * OCA\Shillinq\Lifecycle\EuExpenditureGuard::canDeclare and
 * transitions.submit.requires as
 * OCA\Shillinq\Lifecycle\EuExpenditureGuard::canSubmit.
 *
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */
class EuExpenditureGuard {

	/**
	 * Verplichte bewijsstukken per cost-category when no EligibilityRule
	 * evidenceRequired override is found. Mirrors design.md D6.
	 *
	 * @var array<string,array<string>>
	 */
	private const DEFAULT_REQUIRED_EVIDENCE = [
		'personeel' => ['contract', 'salaris_specificatie', 'urenstaat'],
		'kapitaal' => ['factuur', 'betaalbewijs'],
		'externe_dienstverlening' => ['contract', 'factuur', 'betaalbewijs'],
		'reis_verblijf' => ['factuur', 'presentielijst'],
		'indirecte_kosten' => [],
	];

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
	 * Returns true iff the expenditure may be declared.
	 *
	 * REQ-EUF-011: the cost_category must be eligible for the project's fonds
	 * per an active EligibilityRule, and eligibilityConfirmed must be set.
	 * Non-eligible cost-categories (or unconfirmed eligibility) are blocked.
	 *
	 * Fail-closed: returns false on any exception or malformed input
	 * (REQ-EUF-011 / CWE-863).
	 *
	 * @param string $euExpenditureId The EuExpenditure.id (call-signature parity).
	 * @param array<string,mixed>|null $object The EuExpenditure object being transitioned.
	 *
	 * @return bool True when the expenditure may be declared.
	 *
	 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
	 */
	public function canDeclare(string $euExpenditureId, ?array $object = null): bool {
		try {
			$expenditure = $this->resolveExpenditure(euExpenditureId: $euExpenditureId, object: $object);
			if ($expenditure === null) {
				return false;
			}

			$costCategory = (string)($expenditure['costCategory'] ?? '');
			$euProjectId = (string)($expenditure['euProjectId'] ?? '');
			if ($costCategory === '' || $euProjectId === '') {
				return false;
			}

			// The eligibilityConfirmed flag is set when the cost-category passed
			// the booking-time validation against the fund's eligibility-rules.
			if (($expenditure['eligibilityConfirmed'] ?? false) !== true) {
				return false;
			}

			$fonds = $this->resolveProjectFonds(euProjectId: $euProjectId);
			if ($fonds === null) {
				return false;
			}

			return $this->isCostCategoryEligible(fonds: $fonds, costCategory: $costCategory);
		} catch (\Throwable $e) {
			$this->logger->error(
				'EuExpenditureGuard: declare eligibility check failed — denying declare transition (fail-closed)',
				['euExpenditureId' => $euExpenditureId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canDeclare()

	/**
	 * Returns true iff the declaration may be submitted to the MA.
	 *
	 * REQ-EUF-004: every verplicht bewijsstuk document-type for the
	 * cost_category must be present as a linked SupportingDocument.
	 * REQ-EUF-005: when procurementRequired is set, an aanbestedingsdossier
	 * SupportingDocument must additionally be present.
	 *
	 * Fail-closed: returns false on any exception (REQ-EUF-004 / CWE-863).
	 *
	 * @param string $euExpenditureId The EuExpenditure.id.
	 * @param array<string,mixed>|null $object The EuExpenditure object being transitioned.
	 *
	 * @return bool True when the declaration may be submitted.
	 *
	 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
	 */
	public function canSubmit(string $euExpenditureId, ?array $object = null): bool {
		try {
			$expenditure = $this->resolveExpenditure(euExpenditureId: $euExpenditureId, object: $object);
			if ($expenditure === null) {
				return false;
			}

			$costCategory = (string)($expenditure['costCategory'] ?? '');
			$expenditureId = (string)($expenditure['id'] ?? $euExpenditureId);
			if ($costCategory === '' || $expenditureId === '') {
				return false;
			}

			$presentTypes = $this->resolvePresentDocumentTypes(euExpenditureId: $expenditureId);

			$required = $this->resolveRequiredEvidence(
				fonds: $this->resolveProjectFonds(euProjectId: (string)($expenditure['euProjectId'] ?? '')),
				costCategory: $costCategory
			);
			foreach ($required as $type) {
				if (in_array($type, $presentTypes, true) === false) {
					return false;
				}
			}

			// REQ-EUF-005: aanbestedingsplichtige uitgave vereist een aanbestedingsdossier.
			if (($expenditure['procurementRequired'] ?? false) === true
				&& in_array('aanbestedingsdossier', $presentTypes, true) === false
			) {
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'EuExpenditureGuard: submit completeness check failed — denying submit transition (fail-closed)',
				['euExpenditureId' => $euExpenditureId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canSubmit()

	/**
	 * Resolve the EuExpenditure object, preferring the supplied object and
	 * falling back to an ObjectService lookup by id.
	 *
	 * @param string $euExpenditureId The EuExpenditure.id to look up.
	 * @param array<string,mixed>|null $object The in-flight object, if provided.
	 *
	 * @return array<string,mixed>|null The expenditure, or null when unresolved.
	 */
	private function resolveExpenditure(string $euExpenditureId, ?array $object): ?array {
		if ($object !== null) {
			return $object;
		}

		if ($euExpenditureId === '') {
			return null;
		}

		$entries = $this->objectService
			->setRegister($this->resolveRegister())
			->setSchema('EuExpenditure')
			->findAll(['filters' => ['id' => $euExpenditureId], 'limit' => 1]);

		foreach ($entries as $entry) {
			if (is_array($entry) === true) {
				return $entry;
			}
		}

		return null;
	}//end resolveExpenditure()

	/**
	 * Resolve the fonds of an EuProject by id.
	 *
	 * @param string $euProjectId The EuProject.id.
	 *
	 * @return string|null The fonds enum value, or null when unresolved.
	 */
	private function resolveProjectFonds(string $euProjectId): ?string {
		if ($euProjectId === '') {
			return null;
		}

		$projects = $this->objectService
			->setRegister($this->resolveRegister())
			->setSchema('EuProject')
			->findAll(['filters' => ['id' => $euProjectId], 'limit' => 1]);

		foreach ($projects as $project) {
			if (is_array($project) === true && isset($project['fonds']) === true) {
				return (string)$project['fonds'];
			}
		}

		return null;
	}//end resolveProjectFonds()

	/**
	 * Whether the cost-category is eligible for the fonds per an active rule.
	 *
	 * @param string $fonds The fonds enum value.
	 * @param string $costCategory The cost-category enum value.
	 *
	 * @return bool True when an active EligibilityRule lists the category.
	 */
	private function isCostCategoryEligible(string $fonds, string $costCategory): bool {
		$rules = $this->objectService
			->setRegister($this->resolveRegister())
			->setSchema('EligibilityRule')
			->findAll(['filters' => ['fonds' => $fonds, 'state' => 'active']]);

		foreach ($rules as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			$categories = ($rule['applicableCostCategories'] ?? []);
			if (is_array($categories) === true && in_array($costCategory, $categories, true) === true) {
				return true;
			}
		}

		return false;
	}//end isCostCategoryEligible()

	/**
	 * Resolve the verplichte bewijsstuk document-types for a cost-category,
	 * preferring the fonds' EligibilityRule.evidenceRequired override and
	 * falling back to the DEFAULT_REQUIRED_EVIDENCE table.
	 *
	 * @param string|null $fonds The fonds enum value, if known.
	 * @param string $costCategory The cost-category enum value.
	 *
	 * @return array<string> The required document-type enums.
	 */
	private function resolveRequiredEvidence(?string $fonds, string $costCategory): array {
		if ($fonds !== null) {
			$rules = $this->objectService
				->setRegister($this->resolveRegister())
				->setSchema('EligibilityRule')
				->findAll(['filters' => ['fonds' => $fonds, 'state' => 'active']]);

			foreach ($rules as $rule) {
				if (is_array($rule) === false) {
					continue;
				}

				$evidence = ($rule['evidenceRequired'] ?? null);
				if (is_array($evidence) === true
					&& isset($evidence[$costCategory]) === true
					&& is_array($evidence[$costCategory]) === true
				) {
					return array_values($evidence[$costCategory]);
				}
			}
		}//end if

		return (self::DEFAULT_REQUIRED_EVIDENCE[$costCategory] ?? []);
	}//end resolveRequiredEvidence()

	/**
	 * Resolve the document-types of every SupportingDocument linked to an expenditure.
	 *
	 * @param string $euExpenditureId The EuExpenditure.id.
	 *
	 * @return array<string> The present document-type enums.
	 */
	private function resolvePresentDocumentTypes(string $euExpenditureId): array {
		$documents = $this->objectService
			->setRegister($this->resolveRegister())
			->setSchema('SupportingDocument')
			->findAll(['filters' => ['euExpenditureId' => $euExpenditureId]]);

		$types = [];
		foreach ($documents as $document) {
			if (is_array($document) === true && isset($document['documentType']) === true) {
				$types[] = (string)$document['documentType'];
			}
		}

		return $types;
	}//end resolvePresentDocumentTypes()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
