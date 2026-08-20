<?php

/**
 * Payment-Run Duplicate-Payment Guard
 *
 * Server-side duplicate-payment control on the `PaymentRun.export` lifecycle
 * transition (approved -> exported). Export is the transition that generates the
 * SEPA pain.001 / CSV bank file and disburses money; it is the last declarative
 * choke point before the administration pays. This guard rejects the export when
 * any `paymentLines[].apTransactionRef` in the batch points at an AP invoice
 * (`APTransaction`) that is EITHER:
 *
 *   - already `paid` (settled) — paying it again is a double payment, or
 *   - already present in ANOTHER open/executed PaymentRun (state draft,
 *     approved or exported) — the same invoice queued in two batches.
 *
 * Why `export` and not `approve`: the OpenRegister lifecycle engine resolves a
 * single `requires` guard tag per transition (LifecycleValidationListener reads
 * one `requires` string). The `approve` transition's slot is already occupied by
 * FourEyesPaymentRunGuard (segregation of duties, payment-run-four-eyes). Export
 * saves the run through OpenRegister's ObjectService (setting lifecycleState =
 * exported), so the engine runs this guard on that save — the definitive
 * pre-disbursement gate. A batch that reaches export with a duplicate/paid
 * invoice is blocked before any bank file is written.
 *
 * FAIL CLOSED (CWE-863 / OWASP A01:2021): the guard DENIES the export whenever it
 * cannot positively establish that every line is safe to pay — an unidentifiable
 * batch, a line without an `apTransactionRef`, or any thrown exception during the
 * cross-object lookups all block the disbursement. An indeterminate check must
 * never be treated as a pass; silently paying a duplicate is the failure this
 * control exists to prevent.
 *
 * The guard is wired via the schema's `x-openregister-lifecycle.transitions.
 * export.requires` DI tag (its own FQCN) and invoked by OpenRegister's
 * LifecycleValidationListener, which calls `check($object, $action, $userId)`.
 * It is read-only: it MUST NOT mutate the object.
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
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Blocks exporting a PaymentRun that pays an already-paid or already-batched AP invoice.
 *
 * @spec openspec/specs/payment-control-guards/spec.md
 */
final class PaymentRunDuplicateGuard implements LifecycleGuardInterface {
	/**
	 * User-facing denial when a line settles an already-paid AP invoice.
	 *
	 * @var string
	 */
	public const MESSAGE_ALREADY_PAID = 'This payment run cannot be exported: at least one invoice in the batch has already been paid. '
		. 'Remove the settled invoice before exporting; paying it again would be a duplicate payment.';

	/**
	 * User-facing denial when a line settles an invoice already in another batch.
	 *
	 * @var string
	 */
	public const MESSAGE_ALREADY_BATCHED = 'This payment run cannot be exported: at least one invoice in the batch is already queued in '
		. 'another open or executed payment batch. Remove the duplicate line before exporting to prevent a double payment.';

	/**
	 * User-facing denial when the batch itself cannot be identified (fail-closed).
	 *
	 * @var string
	 */
	public const MESSAGE_NO_OBJECT = 'This payment run cannot be exported: the batch could not be identified for the duplicate-payment check '
		. '(fail-closed).';

	/**
	 * User-facing denial when a line is malformed or a lookup fails (fail-closed).
	 *
	 * @var string
	 */
	public const MESSAGE_INDETERMINATE = 'This payment run cannot be exported: the duplicate-payment check could not be completed, '
		. 'so the disbursement is blocked (fail-closed).';

	/**
	 * FQCN of OpenRegister's ObjectService, resolved lazily from the container.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * PaymentRun states that still "occupy" an invoice (open or executed batches).
	 *
	 * A `reconciled` run is terminal and its invoices are already `paid`, so the
	 * already-paid check covers it; it is deliberately excluded here.
	 *
	 * @var array<string>
	 */
	private const OCCUPYING_STATES = [
		'draft',
		'approved',
		'exported',
	];

	/**
	 * Construct the guard.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution (ADR-022).
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise (or deny) the payment-run export transition.
	 *
	 * @param array<string, mixed> $object The loaded PaymentRun payload at its current (approved) state.
	 * @param string $action The transition action being applied (expected: `export`).
	 * @param string $userId The uid of the caller (unused; retained for the interface).
	 *
	 * @return GuardResult Allow when no line is a duplicate/paid invoice; deny (fail-closed) otherwise.
	 *
	 * @spec openspec/specs/payment-control-guards/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $userId is required by
	 *     LifecycleGuardInterface::check()'s signature; this guard does not
	 *     discriminate by caller.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$objectId = $this->resolveObjectId(object: $object);
		if ($objectId === '') {
			$this->logger->warning('PaymentRunDuplicateGuard: payment-run id is empty — denying (fail-closed).', ['action' => $action]);
			return GuardResult::deny(self::MESSAGE_NO_OBJECT);
		}

		$refs = $this->resolveLineRefs(object: $object);
		if ($refs === null) {
			$this->logger->error(
				'PaymentRunDuplicateGuard: a payment line has no apTransactionRef — denying (fail-closed).',
				['paymentRun' => $objectId, 'action' => $action]
			);
			return GuardResult::deny(self::MESSAGE_INDETERMINATE);
		}

		if ($refs === []) {
			// A batch with no settleable lines cannot double-pay anything.
			return GuardResult::allow();
		}

		$administrationId = trim((string)($object['administrationId'] ?? ''));

		try {
			if ($this->anyAlreadyPaid(refs: $refs, administrationId: $administrationId) === true) {
				$this->logger->warning(
					'PaymentRunDuplicateGuard: a line settles an already-paid invoice — denying export.',
					['paymentRun' => $objectId, 'action' => $action]
				);
				return GuardResult::deny(self::MESSAGE_ALREADY_PAID);
			}

			if ($this->anyAlreadyBatched(refs: $refs, administrationId: $administrationId, selfId: $objectId) === true) {
				$this->logger->warning(
					'PaymentRunDuplicateGuard: a line is already queued in another batch — denying export.',
					['paymentRun' => $objectId, 'action' => $action]
				);
				return GuardResult::deny(self::MESSAGE_ALREADY_BATCHED);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'PaymentRunDuplicateGuard: duplicate-payment lookup threw — denying export (fail-closed).',
				['paymentRun' => $objectId, 'action' => $action, 'error' => $e->getMessage()]
			);
			return GuardResult::deny(self::MESSAGE_INDETERMINATE);
		}//end try

		return GuardResult::allow();
	}//end check()

	/**
	 * Extract the object uuid from the transition payload.
	 *
	 * @param array<string, mixed> $object The loaded object payload.
	 *
	 * @return string The uuid, or '' when it cannot be resolved.
	 */
	private function resolveObjectId(array $object): string {
		$id = ($object['id'] ?? ($object['@self']['id'] ?? ''));
		if (is_string($id) === false) {
			return '';
		}

		return trim($id);
	}//end resolveObjectId()

	/**
	 * Collect the distinct, non-empty apTransactionRef values from the batch's lines.
	 *
	 * Returns null (indeterminate → fail-closed) when any line is present but
	 * carries no apTransactionRef, because that line cannot be checked for
	 * duplication and must not be silently paid.
	 *
	 * @param array<string, mixed> $object The PaymentRun payload.
	 *
	 * @return array<string, true>|null The ref set, [] when there are no lines, or null when indeterminate.
	 */
	private function resolveLineRefs(array $object): ?array {
		$lines = ($object['paymentLines'] ?? []);
		if (is_array($lines) === false) {
			return null;
		}

		$refs = [];
		foreach ($lines as $line) {
			if (is_array($line) === false) {
				return null;
			}

			$ref = trim((string)($line['apTransactionRef'] ?? ''));
			if ($ref === '') {
				return null;
			}

			$refs[$ref] = true;
		}

		return $refs;
	}//end resolveLineRefs()

	/**
	 * Whether any referenced APTransaction is already in a paid/settled state.
	 *
	 * @param array<string, true> $refs The apTransactionRef set to check.
	 * @param string $administrationId The administration scope ('' to skip scoping).
	 *
	 * @return bool True when at least one referenced invoice is already paid.
	 */
	private function anyAlreadyPaid(array $refs, string $administrationId): bool {
		$transactions = $this->findBySchema(schema: 'APTransaction', administrationId: $administrationId);
		foreach ($transactions as $transaction) {
			if (is_array($transaction) === false) {
				continue;
			}

			if ($this->recordMatchesRef(record: $transaction, refs: $refs) === false) {
				continue;
			}

			$state = (string)($transaction['state'] ?? ($transaction['status'] ?? ''));
			if ($state === 'paid') {
				return true;
			}
		}

		return false;
	}//end anyAlreadyPaid()

	/**
	 * Whether any referenced invoice is already in another open/executed batch.
	 *
	 * @param array<string, true> $refs The apTransactionRef set to check.
	 * @param string $administrationId The administration scope ('' to skip scoping).
	 * @param string $selfId The id of the batch being exported (excluded).
	 *
	 * @return bool True when at least one referenced invoice sits in another occupying batch.
	 */
	private function anyAlreadyBatched(array $refs, string $administrationId, string $selfId): bool {
		$runs = $this->findBySchema(schema: 'PaymentRun', administrationId: $administrationId);
		foreach ($runs as $run) {
			if (is_array($run) === false) {
				continue;
			}

			if ($this->recordIdentity(record: $run) === $selfId) {
				// The batch being exported never counts as a duplicate of itself.
				continue;
			}

			$state = (string)($run['lifecycleState'] ?? ($run['status'] ?? ''));
			if (in_array($state, self::OCCUPYING_STATES, true) === false) {
				continue;
			}

			$lines = ($run['paymentLines'] ?? []);
			if (is_array($lines) === false) {
				continue;
			}

			foreach ($lines as $line) {
				if (is_array($line) === false) {
					continue;
				}

				$ref = trim((string)($line['apTransactionRef'] ?? ''));
				if ($ref !== '' && isset($refs[$ref]) === true) {
					return true;
				}
			}
		}//end foreach

		return false;
	}//end anyAlreadyBatched()

	/**
	 * Fetch every record of a schema, scoped by administration when known.
	 *
	 * The nested `paymentLines[].apTransactionRef` and the cross-schema state
	 * check are not expressible as an OpenRegister filter, so the guard reads the
	 * (administration-scoped) set and matches in PHP.
	 *
	 * @param string $schema The schema slug.
	 * @param string $administrationId The administration scope ('' to skip).
	 *
	 * @return array<int, mixed> The record set.
	 */
	private function findBySchema(string $schema, string $administrationId): array {
		$filters = [];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$objectService = $this->container->get(self::OBJECT_SERVICE);
		$found = $objectService
			->setRegister($this->register())
			->setSchema($schema)
			->findAll(['filters' => $filters]);

		if (is_array($found) === false) {
			return [];
		}

		return $found;
	}//end findBySchema()

	/**
	 * Read the stable identity of a record, tolerating id / uuid / slug shapes.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string The first non-empty identity, or ''.
	 */
	private function recordIdentity(array $record): string {
		foreach (['id', 'uuid', 'slug'] as $key) {
			$value = trim((string)($record[$key] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}//end recordIdentity()

	/**
	 * Whether any of a record's identities (id / uuid / slug) is in the ref set.
	 *
	 * A `paymentLines[].apTransactionRef` may be persisted as a uuid or a slug,
	 * so every identity shape is compared, not just the primary id.
	 *
	 * @param array<string, mixed> $record The record to test.
	 * @param array<string, true> $refs The apTransactionRef set.
	 *
	 * @return bool True when the record is one of the referenced invoices.
	 */
	private function recordMatchesRef(array $record, array $refs): bool {
		foreach (['id', 'uuid', 'slug'] as $key) {
			$value = trim((string)($record[$key] ?? ''));
			if ($value !== '' && isset($refs[$value]) === true) {
				return true;
			}
		}

		return false;
	}//end recordMatchesRef()

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
