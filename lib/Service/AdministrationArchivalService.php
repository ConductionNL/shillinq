<?php

/**
 * Administration Archival Service
 *
 * Pure rule + ObjectService-backed enforcement for the administratie archival
 * write-block (REQ-MA-007 / Task 17). An administratie in a read-only
 * lifecycle state (`gearchiveerd`, `opgeheven`) MUST reject every mutation
 * (POST/PUT/DELETE) on any financial record that carries its administrationId.
 * Read-only states still serve historical reads — the `dataRetentionYears`
 * counter starts on archival, but the audit trail and ledger remain queryable.
 *
 * The pure rule (`writesAllowed`, `assertWritable`) is side-effect-free and
 * unit-testable without OpenRegister; the storage-backed wrapper
 * (`assertWritableById`) resolves the administration via the real ObjectService
 * API (`find`) and is used by controllers/services that only have an
 * administrationId on hand. Lifecycle state-transition validation
 * (`isTransitionAllowed`) mirrors the `x-openregister-lifecycle` declared on
 * the Administration schema so the guard cannot drift from the data model.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-17
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Enforces the administratie archival write-block (REQ-MA-007).
 *
 * Default-secure: any administratie whose status this service cannot resolve is
 * treated as writable only when the caller explicitly opts in via the
 * `assertWritable` overload that accepts the loaded record; the id-based
 * helper rejects unknown ids so missing data cannot bypass the block.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-17
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class AdministrationArchivalService {
	/**
	 * Administration lifecycle states that reject all mutations (REQ-MA-007).
	 *
	 * @var array<int,string>
	 */
	public const READ_ONLY_STATES = ['gearchiveerd', 'opgeheven'];

	/**
	 * Administration lifecycle states that allow new mutations.
	 *
	 * `in_liquidatie` still allows closing entries per the declared lifecycle —
	 * a consumer of this service can apply stricter checks if needed; the
	 * write-block itself only blocks the two read-only states (REQ-MA-007).
	 *
	 * ⚠️ This paragraph previously named `JournalPostingGuard` as the consumer.
	 * That class had zero callers and has been deleted; the live journal
	 * posting seam is `OCA\Shillinq\Lifecycle\JournalEntryGuard`. Do not read
	 * a class name in a docblock as evidence of a wiring — in this app a
	 * stated wiring is a hypothesis until grepped.
	 *
	 * @var array<int,string>
	 */
	public const WRITABLE_STATES = ['actief', 'in_liquidatie'];

	/**
	 * Allowed Administration.status transitions, mirroring the declared
	 * x-openregister-lifecycle on the Administration schema.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const TRANSITIONS = [
		'actief' => ['gearchiveerd', 'in_liquidatie'],
		'in_liquidatie' => ['opgeheven', 'gearchiveerd'],
		'gearchiveerd' => [],
		'opgeheven' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether writes are allowed against an administration record (REQ-MA-007).
	 *
	 * Pure rule: a record in `actief` or `in_liquidatie` accepts writes; a
	 * record in `gearchiveerd` or `opgeheven` rejects them. Default-secure for
	 * unknown/empty states (returns false so unrecognised lifecycle slugs
	 * never silently allow writes).
	 *
	 * @param array<string,mixed> $administration The Administration record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-17
	 */
	public function writesAllowed(array $administration): bool {
		$status = (string)($administration['status'] ?? '');
		return in_array(needle: $status, haystack: self::WRITABLE_STATES, strict: true);
	}//end writesAllowed()

	/**
	 * Raise a runtime exception when an administration's status blocks writes.
	 *
	 * The caller catches RuntimeException and translates it to the API-layer
	 * error response (typically 409 "administratie gearchiveerd"). The message
	 * is deliberately stable so controllers can match on it.
	 *
	 * @param array<string,mixed> $administration The Administration record.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the administration is in a read-only state.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-17
	 */
	public function assertWritable(array $administration): void {
		if ($this->writesAllowed(administration: $administration) === true) {
			return;
		}

		$status = (string)($administration['status'] ?? '');
		if ($status === '') {
			$statusLabel = 'onbekend';
		} else {
			$statusLabel = $status;
		}

		throw new RuntimeException(
			sprintf('administratie gearchiveerd (status=%s)', $statusLabel)
		);

	}//end assertWritable()

	/**
	 * Storage-backed write-block assertion (REQ-MA-007).
	 *
	 * Resolves the administration by id via the real ObjectService API
	 * (`find` / `findAll`) and rejects when the record is missing or in a
	 * read-only state. Used by services/controllers that only carry an
	 * administrationId at the call site.
	 *
	 * @param string $administrationId The administration id to check.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the administration is missing or read-only.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-17
	 */
	public function assertWritableById(string $administrationId): void {
		if ($administrationId === '') {
			throw new RuntimeException('administratie ontbreekt');
		}

		$record = $this->findAdministration(administrationId: $administrationId);
		if ($record === null) {
			// Default-secure: an unresolvable administrationId blocks the write.
			throw new RuntimeException(
				sprintf('administratie niet gevonden (id=%s)', $administrationId)
			);
		}

		$this->assertWritable(administration: $record);

	}//end assertWritableById()

	/**
	 * Whether an Administration.status transition is permitted (REQ-MA-007).
	 *
	 * Mirrors the x-openregister-lifecycle declared on the Administration
	 * schema; same-state "transitions" are tolerated as a no-op.
	 *
	 * @param string $from Current status.
	 * @param string $to Requested target status.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-17
	 */
	public function isTransitionAllowed(string $from, string $to): bool {
		if ($from === $to) {
			return true;
		}

		return in_array(
			needle: $to,
			haystack: (self::TRANSITIONS[$from] ?? []),
			strict: true
		);

	}//end isTransitionAllowed()

	/**
	 * Whether moving an administration to a read-only state should start the
	 * retention clock (REQ-MA-007).
	 *
	 * @param string $from Current status.
	 * @param string $to Target status.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-17
	 */
	public function shouldStartRetentionClock(string $from, string $to): bool {
		if ($this->isTransitionAllowed(from: $from, to: $to) === false) {
			return false;
		}

		$fromReadOnly = in_array(needle: $from, haystack: self::READ_ONLY_STATES, strict: true);
		$toReadOnly = in_array(needle: $to, haystack: self::READ_ONLY_STATES, strict: true);

		return $fromReadOnly === false && $toReadOnly === true;
	}//end shouldStartRetentionClock()

	/**
	 * Fetch a single Administration record by id, via the real ObjectService API.
	 *
	 * @param string $administrationId The administration id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findAdministration(string $administrationId): ?array {
		try {
			// NOT findAll(['filters' => ['id' => …]]) — that addresses JSON
			// properties, and the entity's `id` is not one, so it matched
			// nothing for every value and this method reported every
			// administration as absent. An administration is addressed by its
			// administrationCode ('ADM-001') throughout the app, which is why
			// findOne() needs the fallback.
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			return ObjectIdentifier::findOne(
				scoped: $objectService
					->setRegister($this->resolveRegister())
					->setSchema('Administration'),
				id: $administrationId,
				fallbackProperty: 'administrationCode'
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'AdministrationArchivalService: failed to load administration',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end findAdministration()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
