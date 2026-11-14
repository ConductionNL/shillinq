<?php

/**
 * Programma Link Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use DateTimeImmutable;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Shillinq\Util\ObjectIdentifier;

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
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
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
			// Unmapped line — ordinary GL posting, never gated (REQ-BBL-001).
			$programme = ($glLine['programmeStructure'] ?? null);
			if ($programme === null || $programme === '') {
				return true;
			}

			$refusal = $this->refusal(glLine: $glLine);
			if ($refusal === null) {
				return true;
			}

			$this->logger->warning($refusal[0], $refusal[1]);
			return false;
		} catch (\Throwable $e) {
			$this->logger->error('ProgrammaLinkGuard: denying save on unexpected error (fail-closed)', ['exception' => $e->getMessage()]);
			return false;
		}
	}//end validateOnSave()

	/**
	 * The first rule a mapped line breaks, in order: a canonical programme, a
	 * non-future effective date, no conflicting prior assignment (REQ-BBL-002/005).
	 *
	 * @param array<string, mixed> $glLine GLLine object array with a programme set.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null The warning and its context, or null when the line may be saved.
	 */
	private function refusal(array $glLine): ?array {
		$programme = $glLine['programmeStructure'];
		if ($this->isCanonicalProgramme(programme: (string)$programme) === false) {
			return ['ProgrammaLinkGuard: rejecting non-canonical BBV provincie programme', ['programmeStructure' => $programme]];
		}

		$assignedAt = ($glLine['programmeAssignedAt'] ?? null);
		if ($this->isEffectiveDateValid(assignedAt: $assignedAt) === false) {
			return ['ProgrammaLinkGuard: rejecting future programmaAssignedAt', ['programmeAssignedAt' => $assignedAt]];
		}

		if ($this->hasConflictingPriorAssignment(glLine: $glLine, programme: (string)$programme) === true) {
			return ['ProgrammaLinkGuard: rejecting conflicting programme re-assignment (double-mapping)', ['target' => $programme]];
		}

		return null;
	}//end refusal()

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
			return self::programmeOf(record: $this->storedLine(id: $id));
		} catch (\Throwable $e) {
			$this->logger->debug(
				'ProgrammaLinkGuard: GLLine lookup unavailable — treating as unmapped',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end fetchStoredProgramme()

	/**
	 * The persisted GL line, as find() answers it.
	 *
	 * @param string $id GL line object id.
	 *
	 * @return mixed
	 */
	private function storedLine(string $id): mixed {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		return ObjectIdentifier::findScoped(
			scoped: $objectService->setRegister(register: $this->getRegisterSlug())->setSchema(schema: 'GLLine'),
			id: $id
		);
	}//end storedLine()

	/**
	 * A stored line's programmaStructure: read through jsonSerialize() for an entity; null when absent.
	 *
	 * @param mixed $record The stored line.
	 *
	 * @return string|null
	 */
	private static function programmeOf(mixed $record): ?string {
		$record = self::serialized(record: $record);
		if (is_array($record) === false || isset($record['programmeStructure']) === false) {
			return null;
		}

		return (string)$record['programmeStructure'];
	}//end programmeOf()

	/**
	 * An entity's jsonSerialize() payload; any other value unchanged.
	 *
	 * @param mixed $record The stored line.
	 *
	 * @return mixed
	 */
	private static function serialized(mixed $record): mixed {
		if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {
			return $record->jsonSerialize();
		}

		return $record;
	}//end serialized()
}//end class
