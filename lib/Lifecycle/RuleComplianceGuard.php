<?php

/**
 * Rule Compliance Guard
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

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Standards\RuleEngine;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

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
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly BalanceGuard $balanceGuard,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Allow ARInvoice.issue only when no mandatory invoice rule is violated.
	 *
	 * @param string $id The ARInvoice id being issued.
	 *
	 * @return bool True to allow the transition.
	 */
	public function validateInvoice(string $id): bool {
		try {
			$invoice = $this->loadObject('ARInvoice', $id);
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
	 * @param string $id The GLTransaction id being posted.
	 *
	 * @return bool True to allow the transition.
	 */
	public function validateTransaction(string $id): bool {
		try {
			$transaction = $this->loadObject('GLTransaction', $id);
			if ($transaction === null) {
				return false;
			}

			$transaction['lines'] = $this->loadLines($transaction);

			$violations = RuleEngine::evaluate('GLTransaction', $transaction, $this->context($transaction));
			if ($this->balanceGuard->isBalanced($id) === false) {
				$violations[] = RuleEngine::violationFor('gl-double-entry-balanced');
			}

			$this->logViolations('GLTransaction', $id, $violations);
			return RuleEngine::hasMandatory($violations) === false;
		} catch (\Throwable $e) {
			$this->logger->error(
				'RuleComplianceGuard: transaction validation failed — denying post (fail-closed)',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end validateTransaction()

	/**
	 * Build the evaluation context. Jurisdiction drives which rules apply; it
	 * defaults to NL (the home jurisdiction — EU + global rules then apply) and
	 * is the seam for per-administration jurisdiction resolution later.
	 *
	 * @param array<string, mixed> $object The object under evaluation.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $object): array {
		return [
			'jurisdiction' => 'NL',
			'administrationId' => ($object['administrationId'] ?? null),
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
		$entity = $this->objectService->find(id: $id, register: $this->register(), schema: $schema);
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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		return $register === '' ? 'shillinq' : $register;
	}//end register()
}//end class
