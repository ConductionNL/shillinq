<?php

/**
 * Rule Compliance Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ADR-031 exception-path lifecycle guard that enforces the machine-checkable
 * bookkeeping rules (the RuleCatalogue / RuleEngine) at the point a record is
 * issued or posted. Referenced from the schema x-openregister-lifecycle
 * `requires:` clauses:
 *   - ARInvoice.issue   → ::validateInvoice
 *   - GLTransaction.post → ::validateTransaction
 *
 * Each method loads the object (and, for a GL transaction, its lines + balance
 * via BalanceGuard), builds the jurisdiction context, runs RuleEngine, and
 * returns false (blocking the transition) when any `mandatory` rule is violated;
 * `conditional` / `recommended` violations are logged as warnings. Fail-closed:
 * any error denies the transition (REQ-RE-004 / CWE-863), and balance enforcement
 * is preserved by delegating the balance rule to the existing BalanceGuard.
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
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\Jurisdiction\AdministrationJurisdiction;
use OCA\Shillinq\Standards\RuleEngine;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Lifecycle guard enforcing machine-checkable rules on issue/post.
 */
class RuleComplianceGuard {
	/**
	 * Construct the rule compliance lifecycle guard.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for violations + fail-closed diagnostics.
	 * @param BalanceGuard $balanceGuard Existing double-entry balance guard (reused).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param PostingRestrictionGuard $restrictions The booking rules (ledger-booking-rules).
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly BalanceGuard $balanceGuard,
		private readonly ObjectServiceInterface $objectService,
		private readonly PostingRestrictionGuard $restrictions,
	) {

	}//end __construct()

	/**
	 * Allow ARInvoice.issue only when no mandatory invoice rule is violated.
	 *
	 * Takes either the invoice id or the invoice object itself. The object
	 * form is what RegisterRequiresGuardAdapter passes on the transition
	 * (#1103): it is the payload being issued, so it is evaluated as it
	 * stands rather than re-read from the store.
	 *
	 * @param string|array<string,mixed> $invoiceOrId The ARInvoice id, or the ARInvoice being issued.
	 *
	 * @return bool True to allow the transition.
	 *
	 * @spec openspec/specs/bookkeeping-general-ledger/spec.md
	 */
	public function validateInvoice(string|array $invoiceOrId): bool {
		$id = $this->idOf(objectOrId: $invoiceOrId);
		try {
			$invoice = $invoiceOrId;
			if (is_array($invoice) === false) {
				$invoice = $this->loadObject('ARInvoice', $id);
			}

			if ($invoice === null) {
				return false;
			}

			$violations = RuleEngine::evaluate('ARInvoice', $invoice, $this->context($invoice));
			$this->logViolations('ARInvoice', $id, $violations);
			return RuleEngine::hasMandatory($violations) === false;
		} catch (\Throwable $e) {
			$this->logger->error(
				'RuleComplianceGuard: invoice validation failed — denying issue (fail-closed)',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end validateInvoice()

	/**
	 * Allow GLTransaction.post only when balanced and no mandatory ledger rule is
	 * violated. Balance is delegated to BalanceGuard so existing behaviour is
	 * preserved exactly; the engine adds completeness + sequential-numbering.
	 *
	 * Takes either the transaction id or the transaction object itself, the
	 * form RegisterRequiresGuardAdapter passes on the transition (#1103). The
	 * lines and the balance are read from the stored GLLine rows either way.
	 *
	 * @param string|array<string,mixed> $transactionOrId The GLTransaction id, or the GLTransaction being posted.
	 *
	 * @return bool True to allow the transition.
	 *
	 * @throws PostingRefusedException When a line breaks a booking rule, naming it.
	 *
	 * @spec openspec/specs/bookkeeping-general-ledger/spec.md
	 */
	public function validateTransaction(string|array $transactionOrId): bool {
		$id = $this->idOf(objectOrId: $transactionOrId);
		try {
			$transaction = $this->transactionAsPosted(transactionOrId: $transactionOrId, id: $id);
			if ($transaction === null || $this->violatesMandatoryRule(id: $id, transaction: $transaction) === true) {
				return false;
			}

			// A person's posting is checked against the booking rules. A
			// transaction a sub-ledger prepared carries its journal code (the
			// GR/IR and inventory posters) or the journal entry it books, which
			// JournalEntryGuard already checked (ledger-booking-rules D2).
			if ($this->fromSubLedger(transaction: $transaction) === false) {
				$this->restrictions->assertAllowed(
					lines: $transaction['lines'],
					administrationId: (string)($transaction['administrationId'] ?? ''),
					postingDate: (string)($transaction['postingDate'] ?? '')
				);
			}

			return true;
		} catch (PostingRefusedException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error(
				'RuleComplianceGuard: transaction validation failed — denying post (fail-closed)',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end validateTransaction()

	/**
	 * The transaction as the post leaves it, with its stored lines; null when
	 * it cannot be found or carries no id.
	 *
	 * The page sends the draft with its state moved to posted. The lock,
	 * retention, integrity and audit-trail fields are what the post itself
	 * gives the entry (StampPostingAction persists them), so the entry is
	 * judged as the post leaves it (REQ-LPP-001, #516).
	 *
	 * @param string|array<string,mixed> $transactionOrId The GLTransaction id, or the GLTransaction.
	 * @param string $id The transaction's id.
	 *
	 * @return array<string,mixed>|null The stamped transaction with `lines`.
	 */
	private function transactionAsPosted(string|array $transactionOrId, string $id): array|null {
		$transaction = $transactionOrId;
		if (is_array($transaction) === false) {
			$transaction = $this->loadObject('GLTransaction', $id);
		}

		if ($transaction === null || $id === '') {
			return null;
		}

		$transaction = (new PostingStamps())->apply(transaction: $transaction, user: 'system', now: new DateTimeImmutable());
		$transaction['lines'] = $this->loadLines($transaction);
		return $transaction;

	}//end transactionAsPosted()

	/**
	 * Evaluate the ledger rules plus the double-entry balance, logging every violation.
	 *
	 * @param string $id The transaction's id.
	 * @param array<string,mixed> $transaction The stamped transaction with its lines.
	 *
	 * @return bool True when a mandatory rule is violated.
	 */
	private function violatesMandatoryRule(string $id, array $transaction): bool {
		$violations = RuleEngine::evaluate('GLTransaction', $transaction, $this->context($transaction));
		if ($this->balanceGuard->isBalanced($id) === false) {
			$violations[] = RuleEngine::violationFor('gl-double-entry-balanced');
		}

		$this->logViolations('GLTransaction', $id, $violations);
		return RuleEngine::hasMandatory($violations) === true;

	}//end violatesMandatoryRule()

	/**
	 * Whether a transaction was prepared by a sub-ledger rather than a person.
	 *
	 * @param array<string,mixed> $transaction The GL transaction.
	 *
	 * @return bool
	 */
	private function fromSubLedger(array $transaction): bool {
		return (string)($transaction['journalEntryId'] ?? '') !== ''
			|| (string)($transaction['journalCode'] ?? '') !== '';
	}//end fromSubLedger()

	/**
	 * The id of an object passed either as its id or as the object array.
	 *
	 * @param string|array<string,mixed> $objectOrId The id, or the object.
	 *
	 * @return string The id, '' when the object carries none.
	 */
	private function idOf(string|array $objectOrId): string {
		if (is_array($objectOrId) === false) {
			return $objectOrId;
		}

		return (string)($objectOrId['id'] ?? ($objectOrId['@self']['id'] ?? ''));
	}//end idOf()

	/**
	 * Build the evaluation context. Jurisdiction drives which rules apply: the
	 * administration's own jurisdiction, NL when it names none (EU and global
	 * rules then apply as well). A failed lookup keeps NL, the home jurisdiction.
	 *
	 * @param array<string, mixed> $object The object under evaluation.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	private function context(array $object): array {
		$administrationId = ($object['administrationId'] ?? null);
		$jurisdiction = AdministrationJurisdiction::DEFAULT;
		if (is_string($administrationId) === true && trim($administrationId) !== '') {
			try {
				$jurisdiction = (new AdministrationJurisdiction(objectService: $this->objectService, appConfig: $this->appConfig))
					->of(administrationId: $administrationId);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'RuleComplianceGuard: the administration\'s jurisdiction could not be read; checking under NL rules',
					['administrationId' => $administrationId, 'exception' => $e->getMessage()]
				);
			}
		}

		return [
			'jurisdiction' => $jurisdiction,
			'administrationId' => $administrationId,
		];

	}//end context()

	/**
	 * Load one object by id from the register as a plain array, or null.
	 *
	 * @param string $schema The schema name.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadObject(string $schema, string $id): ?array {
		$entity = ObjectIdentifier::findScoped(
			scoped: $this->objectService->setRegister($this->register())->setSchema($schema),
			id: $id
		);
		if ($entity === null) {
			return null;
		}

		$array = $entity->jsonSerialize();
		return is_array($array) === true ? $array : null;
	}//end loadObject()

	/**
	 * Load the GLLine rows for a transaction. Lines reference their parent via
	 * `transactionId` matching EITHER the OpenRegister id OR the human
	 * `transactionNumber`, so both are queried and merged (deduped by line id) —
	 * the same join the financial-series code uses, and the reason the balance
	 * check must also be matched on the right key.
	 *
	 * @param array<string, mixed> $transaction The GL transaction.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadLines(array $transaction): array {
		$keys = array_values(
			array_unique(
				array_filter(
					[
						(string)($transaction['id'] ?? $transaction['@self']['id'] ?? ''),
						(string)($transaction['transactionNumber'] ?? ''),
					]
				)
			)
		);

		$lines = [];
		foreach ($keys as $key) {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema('GLLine')
				->findAll(['filters' => ['transactionId' => $key]]);

			// ADR-084: findAll() is declared `: array` — never null.
			foreach ($rows as $row) {
				$line = $row;
				if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
					$line = (array)$row->jsonSerialize();
				}

				if (is_array($line) === true) {
					$lineId = (string)($line['id'] ?? $line['@self']['id'] ?? count($lines));
					$lines[$lineId] = $line;
				}
			}
		}

		return array_values($lines);
	}//end loadLines()

	/**
	 * Log violations (mandatory at warning level, others at info).
	 *
	 * @param string $objectType The object type.
	 * @param string $id The object id.
	 * @param array<int, \OCA\Shillinq\Standards\Violation> $violations Violations.
	 *
	 * @return void
	 */
	private function logViolations(string $objectType, string $id, array $violations): void {
		foreach ($violations as $violation) {
			$message = sprintf(
				'RuleComplianceGuard: %s %s violates %s (%s) — %s',
				$objectType,
				$id,
				$violation->ruleId,
				$violation->source,
				$violation->statement
			);
			if ($violation->severity === 'mandatory') {
				$this->logger->warning($message);
				continue;
			}

			$this->logger->info($message);
		}

	}//end logViolations()

	/**
	 * The configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		return $register === '' ? 'shillinq' : $register;
	}//end register()
}//end class
