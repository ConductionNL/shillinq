<?php

/**
 * Programma Link Guard
 *
 * ADR-031 exception-path save-precondition guard for the GLLine schema's
 * BBV provincie programme assignment (REQ-BBL-002 / REQ-BBL-005). It runs only
 * when a GL line carries a non-null programmaStructure (i.e. it is being mapped
 * to a programme via the Budget-to-Programme Linker or a manual detail edit) and
 * otherwise passes through unchanged so that ordinary GL postings — which never
 * set programmaStructure at posting time — are never gated.
 *
 * The guard enforces three rules that the declarative lifecycle DSL cannot yet
 * express without cross-record / temporal comparisons:
 *   1. programmaStructure MUST be one of the seven canonical BBV provincie
 *      programmes (REQ-BBL-002).
 *   2. programmaAssignedAt MUST NOT be a future date (REQ-BBL-002).
 *   3. A GL line already assigned to a different programme MUST be unmapped
 *      before re-assignment — no silent overwrite to a conflicting programme
 *      (REQ-BBL-005 "no double-mapping").
 *
 * Referenced from the GLLine schema's x-openregister-lifecycle.preconditions.save
 * in lib/Settings/register.d/bookkeeping-provincies-bbv-variant.json.
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-provincies-bbv-variant/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Save precondition guard for BBV provincie programme assignment on GLLine.
 *
 * Fail-closed: a malformed programme value, a future effective date, or a
 * conflicting re-assignment denies the save. Lines without a programmaStructure
 * always pass (ordinary GL postings are never affected).
 *
 * @spec openspec/changes/bookkeeping-provincies-bbv-variant/tasks.md#task-15
 */
class ProgrammaLinkGuard {

	/**
	 * The seven canonical BBV provincie programmes (design D3 / REQ-BBL-002).
	 *
	 * @var array<int, string>
	 */
	private const CANONICAL_PROGRAMMES = [
		'ruimte',
		'mobiliteit',
		'water',
		'milieu',
		'cultuur',
		'economie',
		'bestuur',
	];

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
	 * Save precondition for the GLLine schema (BBV provincie programme rules).
	 *
	 * Returns true when the line may be saved. Lines whose programmaStructure is
	 * null/absent always pass (REQ-BBL-001: unmapped is the default state). When a
	 * programme is set, the three REQ-BBL-002/005 rules are enforced.
	 *
	 * Fail-closed: returns false on any unexpected exception (CWE-863).
	 *
	 * @param array<string, mixed> $glLine GLLine object array supplied by OR.
	 *
	 * @return bool True when the GL line may be saved.
	 *
	 * @spec openspec/changes/bookkeeping-provincies-bbv-variant/tasks.md#task-15
	 */
	public function validateOnSave(array $glLine): bool {
		try {
			$programme = ($glLine['programmeStructure'] ?? null);
			if ($programme === null || $programme === '') {
				// Unmapped line — ordinary GL posting, never gated (REQ-BBL-001).
				return true;
			}

			if ($this->isCanonicalProgramme(programme: (string)$programme) === false) {
				$this->logger->warning(
					'ProgrammaLinkGuard: rejecting non-canonical BBV provincie programme',
					['programmeStructure' => $programme]
				);
				return false;
			}

			if ($this->isEffectiveDateValid(assignedAt: ($glLine['programmeAssignedAt'] ?? null)) === false) {
				$this->logger->warning(
					'ProgrammaLinkGuard: rejecting future programmaAssignedAt',
					['programmeAssignedAt' => ($glLine['programmeAssignedAt'] ?? null)]
				);
				return false;
			}

			if ($this->hasConflictingPriorAssignment(glLine: $glLine, programme: (string)$programme) === true) {
				$this->logger->warning(
					'ProgrammaLinkGuard: rejecting conflicting programme re-assignment (double-mapping)',
					['target' => $programme]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ProgrammaLinkGuard: denying save on unexpected error (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end validateOnSave()

	/**
	 * Whether the supplied value is one of the seven canonical provincie programmes.
	 *
	 * @param string $programme Candidate programme code.
	 *
	 * @return bool
	 */
	private function isCanonicalProgramme(string $programme): bool {
		return in_array($programme, self::CANONICAL_PROGRAMMES, true);
	}//end isCanonicalProgramme()

	/**
	 * Whether the effective date is absent or not in the future (REQ-BBL-002).
	 *
	 * A null/empty date passes (the engine may default it). A malformed date is
	 * rejected. A date strictly after today is rejected.
	 *
	 * @param mixed $assignedAt The programmaAssignedAt value (date string or null).
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromFormat is a date factory.
	 */
	private function isEffectiveDateValid(mixed $assignedAt): bool {
		if ($assignedAt === null || $assignedAt === '') {
			return true;
		}

		$assigned = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$assignedAt);
		if ($assigned === false) {
			return false;
		}

		$today = new DateTimeImmutable('today');
		return ($assigned <= $today);
	}//end isEffectiveDateValid()

	/**
	 * Whether the persisted GL line is already mapped to a different programme.
	 *
	 * Implements the REQ-BBL-005 "no double-mapping" rule: a line currently
	 * carrying programme A may not be silently re-saved with programme B; it must
	 * be unmapped (programmaStructure cleared) first. Re-saving with the SAME
	 * programme (idempotent) is allowed.
	 *
	 * @param array<string, mixed> $glLine Incoming GL line array.
	 * @param string $programme Target programme being assigned.
	 *
	 * @return bool True when a conflicting prior assignment exists.
	 */
	private function hasConflictingPriorAssignment(array $glLine, string $programme): bool {
		$id = ($glLine['id'] ?? ($glLine['@self']['id'] ?? null));
		if ($id === null || $id === '') {
			// New / unsaved line: no prior assignment to conflict with.
			return false;
		}

		$stored = $this->fetchStoredProgramme(id: (string)$id);
		if ($stored === null || $stored === '') {
			// Was unmapped — assigning a programme is allowed.
			return false;
		}

		// Conflict only when the stored programme differs from the target.
		return ($stored !== $programme);
	}//end hasConflictingPriorAssignment()

	/**
	 * Fetch the currently-persisted programmaStructure for a GL line by id.
	 *
	 * Returns null when the line cannot be resolved (treated as no prior
	 * assignment so the guard never blocks on a transient lookup failure for a
	 * line that has never been mapped).
	 *
	 * @param string $id GL line object id.
	 *
	 * @return string|null The stored programmaStructure, or null.
	 */
	private function fetchStoredProgramme(string $id): ?string {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$result = $objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: 'GLLine')
				->find($id);

			if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
				$result = $result->jsonSerialize();
			}

			if (is_array($result) === false) {
				return null;
			}

			$stored = ($result['programmeStructure'] ?? null);
			if ($stored === null) {
				return null;
			}

			return (string)$stored;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'ProgrammaLinkGuard: GLLine lookup unavailable — treating as unmapped',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end fetchStoredProgramme()
}//end class
