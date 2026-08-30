<?php

/**
 * Audit Trail Guard
 *
 * ADR-031 exception-path lifecycle guard enforcing append-only immutability on
 * the AuditTrail register (bookkeeping-single-audit-eu-fondsen, T3 regulatory +
 * compliance). REQ-EUF-009 requires the audit-trail to be reconstructible 5+
 * years after project-sluiting with no tampering: records may only be inserted,
 * never modified or deleted.
 *
 *  - canModify(): always false — AuditTrail records are immutable.
 *  - canDelete(): always false — AuditTrail records are append-only.
 *
 * The guard also offers a convenience builder, buildEvent(), that constructs a
 * well-formed AuditTrail object array (with before/after snapshots) for callers
 * appending events on EuExpenditure state transitions. BSN / bijzondere
 * persoonsgegevens are NEVER captured (ADR-005); only role + UID-style actor
 * references are recorded.
 *
 * ADR-031 exception reason: append-only enforcement on a schema is not yet
 * expressible in the declarative lifecycle DSL. When the engine gains an
 * `append-only` schema constraint, replace these references with the
 * declarative flag and delete this file.
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

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Append-only immutability guard for the AuditTrail register.
 *
 * Referenced from the AuditTrail schema (register.d fragment); canModify and
 * canDelete return false so the lifecycle engine denies any mutation/deletion
 * of an AuditTrail record (REQ-EUF-009).
 *
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */
class AuditTrailGuard {

	/**
	 * Closed set of recognised audit-trail event types (REQ-EUF-009).
	 *
	 * @var array<string>
	 */
	public const EVENT_TYPES = [
		'booking',
		'declaration',
		'certification',
		'payment',
		'correction',
		'audit',
	];

	/**
	 * Optional event fields copied verbatim from the options array into the
	 * built event when present (keeps buildEvent() flat — no per-field branch).
	 *
	 * @var array<string>
	 */
	private const OPTIONAL_EVENT_FIELDS = [
		'euExpenditureId',
		'beforeState',
		'afterState',
		'justification',
		'auditEvidenceUri',
		'actorNaturalPerson',
		'actorOrganisation',
	];

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Always false — AuditTrail records are immutable (REQ-EUF-009).
	 *
	 * @param string $auditTrailId The AuditTrail.id (call-signature parity).
	 * @param array<string,mixed>|null $object The record being mutated (ignored).
	 *
	 * @return bool Always false.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
	 */
	public function canModify(string $auditTrailId, ?array $object = null): bool {
		$this->logger->warning(
			'AuditTrailGuard: modification denied — AuditTrail is append-only (REQ-EUF-009)',
			['auditTrailId' => $auditTrailId]
		);
		return false;
	}//end canModify()

	/**
	 * Always false — AuditTrail records are append-only (REQ-EUF-009).
	 *
	 * @param string $auditTrailId The AuditTrail.id (call-signature parity).
	 * @param array<string,mixed>|null $object The record being deleted (ignored).
	 *
	 * @return bool Always false.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
	 */
	public function canDelete(string $auditTrailId, ?array $object = null): bool {
		$this->logger->warning(
			'AuditTrailGuard: deletion denied — AuditTrail is append-only (REQ-EUF-009)',
			['auditTrailId' => $auditTrailId]
		);
		return false;
	}//end canDelete()

	/**
	 * Build a well-formed AuditTrail object array for an EU-fondsen transition.
	 *
	 * Captures before/after JSON snapshots and the acting role + organisation.
	 * No BSN / bijzondere persoonsgegevens are ever recorded (ADR-005); the
	 * actorNaturalPerson is expected to be a UID-style reference, not a BSN.
	 *
	 * Required keys in $options: administrationId, euProjectId, eventType,
	 * actorRole. Optional keys (see OPTIONAL_EVENT_FIELDS): euExpenditureId,
	 * beforeState, afterState, justification, auditEvidenceUri,
	 * actorNaturalPerson, actorOrganisation.
	 *
	 * @param array<string,mixed> $options The event fields.
	 *
	 * @return array<string,mixed> The AuditTrail object array, ready for saveObject.
	 *
	 * @throws InvalidArgumentException When a required key is absent or the eventType is unknown.
	 *
	 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
	 */
	public function buildEvent(array $options): array {
		foreach (['administrationId', 'euProjectId', 'eventType', 'actorRole'] as $requiredKey) {
			if (isset($options[$requiredKey]) === false || (string)$options[$requiredKey] === '') {
				throw new InvalidArgumentException('Missing required AuditTrail field: ' . $requiredKey);
			}
		}

		$eventType = (string)$options['eventType'];
		if (in_array($eventType, self::EVENT_TYPES, true) === false) {
			throw new InvalidArgumentException('Unknown AuditTrail eventType: ' . $eventType);
		}

		$event = [
			'administrationId' => (string)$options['administrationId'],
			'euProjectId' => (string)$options['euProjectId'],
			'eventType' => $eventType,
			'actorRole' => (string)$options['actorRole'],
			'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		foreach (self::OPTIONAL_EVENT_FIELDS as $field) {
			if (array_key_exists($field, $options) === true && $options[$field] !== null) {
				$event[$field] = $options[$field];
			}
		}

		return $event;
	}//end buildEvent()
}//end class
