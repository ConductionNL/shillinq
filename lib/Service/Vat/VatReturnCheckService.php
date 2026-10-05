<?php

/**
 * Shillinq VatReturnCheckService
 *
 * Runs the VAT return checks (REQ-TVRB-001) on one BtwAangifte. It gathers
 * the facts of the return's period (the booked transactions and their ledger
 * lines, the VAT accounts, the tariffs, the documents still in draft and the
 * administration's other returns), evaluates the BtwAangifte rules through
 * the RuleEngine, and answers per check whether it passed, whether it blocks
 * filing, and what makes it fail.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Vat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Vat;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Standards\Checks\VatReturnChecks;
use OCA\Shillinq\Standards\RuleEngine;
use OCP\IAppConfig;
use RuntimeException;

/**
 * The checks of one VAT return, with their verdicts.
 *
 * @spec openspec/changes/tax-vat-return-from-books/specs/bookkeeping-vat-btw-filing/spec.md#requirement-checks-run-on-the-vat-return-and-block-filing-when-they-fail-req-tvrb-001
 */
class VatReturnCheckService {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param IAppConfig             $appConfig     App config (register slug, VAT accounts).
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Run every check on a return.
	 *
	 * @param string $returnId The BtwAangifte id.
	 *
	 * @return list<array{id:string,source:string,statement:string,blocking:bool,passed:bool,offenders:list<string>}>
	 *
	 * @throws RuntimeException When the return does not exist.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public function run(string $returnId): array {
		$vatReturn = $this->row(schema: 'BtwAangifte', id: $returnId);
		if ($vatReturn === null) {
			throw new RuntimeException(sprintf('BtwAangifte %s not found', $returnId));
		}

		$facts = $this->facts(vatReturn: $vatReturn);
		$failed = [];
		foreach (RuleEngine::evaluate('BtwAangifte', $vatReturn, ['jurisdiction' => 'NL', 'vatReturnFacts' => $facts]) as $violation) {
			$failed[$violation->ruleId] = true;
		}

		$results = [];
		foreach (VatReturnChecks::RULE_IDS as $ruleId) {
			$rule = RuleEngine::violationFor($ruleId);
			$passed = (isset($failed[$ruleId]) === false);
			$offenders = [];
			if ($passed === false) {
				$offenders = VatReturnChecks::offenders(ruleId: $ruleId, facts: $facts);
			}

			$results[] = [
				'id' => $ruleId,
				'source' => $rule->source,
				'statement' => $rule->statement,
				'blocking' => ($rule->severity === 'mandatory'),
				'passed' => $passed,
				'offenders' => $offenders,
			];
		}

		return $results;
	}//end run()

	/**
	 * The failed checks that block filing.
	 *
	 * @param string $returnId The BtwAangifte id.
	 *
	 * @return list<array{id:string,source:string,statement:string,blocking:bool,passed:bool,offenders:list<string>}>
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public function blockingFailures(string $returnId): array {
		return array_values(
			array_filter(
				$this->run(returnId: $returnId),
				static fn (array $check): bool => $check['blocking'] === true && $check['passed'] === false
			)
		);
	}//end blockingFailures()

	/**
	 * Gather the facts of a return's period.
	 *
	 * @param array<string,mixed> $vatReturn The BtwAangifte.
	 *
	 * @return array<string,mixed>
	 */
	private function facts(array $vatReturn): array {
		$administrationId = (string)($vatReturn['administrationId'] ?? '');
		$start = substr((string)($vatReturn['startDate'] ?? ''), 0, 10);
		$end = substr((string)($vatReturn['endDate'] ?? ''), 0, 10);
		$filter = ['filters' => ['administrationId' => $administrationId]];

		$transactions = [];
		$drafts = [];
		foreach ($this->rows(schema: 'GLTransaction', config: $filter) as $transaction) {
			$date = substr((string)($transaction['postingDate'] ?? ''), 0, 10);
			if ($date < $start || $date > $end) {
				continue;
			}

			$state = (string)($transaction['state'] ?? '');
			$name = (string)($transaction['transactionNumber'] ?? $this->idOf(row: $transaction));
			if (in_array($state, ['posted', 'reversed'], true) === true) {
				$transactions[$this->idOf(row: $transaction)] = $transaction;
				continue;
			}

			$drafts[] = 'Journal entry ' . $name;
		}

		$lines = [];
		foreach ($this->rows(schema: 'GLLine', config: $filter) as $line) {
			if (isset($transactions[(string)($line['transactionId'] ?? '')]) === true) {
				$lines[] = $line;
			}
		}

		$tariffs = new VatLineStamper(objectService: $this->objectService, register: $this->register());
		$rates = [];
		$reverse = [];
		foreach ($this->rows(schema: 'VatTariff', config: []) as $tariff) {
			$code = (string)($tariff['code'] ?? '');
			if ($code === '') {
				continue;
			}

			$rates[$code] = $tariffs->ratePercentage(code: $code);
			if ($tariffs->isReverseCharge(code: $code) === true) {
				$reverse[] = $code;
			}
		}

		return [
			'period' => ['start' => $start, 'end' => $end],
			'previousPeriod' => $this->previousPeriod(vatReturn: $vatReturn),
			'transactions' => $transactions,
			'lines' => $lines,
			'vatAccounts' => $this->vatAccounts(),
			'rates' => $rates,
			'reverseChargeCodes' => $reverse,
			'totals' => [
				'collectedCents' => (int)round((float)($vatReturn['totalVATCollected'] ?? 0) * 100),
				'paidCents' => (int)round((float)($vatReturn['totalVATPaid'] ?? 0) * 100),
			],
			'drafts' => array_merge($drafts, $this->draftDocuments(administrationId: $administrationId, start: $start, end: $end)),
			'returns' => $this->rows(schema: 'BtwAangifte', config: $filter),
		];
	}//end facts()

	/**
	 * Sales and purchase invoices dated in the period that are not booked yet.
	 *
	 * @param string $administrationId The administration.
	 * @param string $start            Period start.
	 * @param string $end              Period end.
	 *
	 * @return list<string>
	 */
	private function draftDocuments(string $administrationId, string $start, string $end): array {
		$unbooked = [
			'ARInvoice' => ['field' => 'lifecycleState', 'states' => ['draft'], 'label' => 'Sales invoice'],
			'APInvoice' => ['field' => 'state', 'states' => ['draft', 'pending', 'approved'], 'label' => 'Purchase invoice'],
		];
		$found = [];
		foreach ($unbooked as $schema => $rule) {
			foreach ($this->rows(schema: $schema, config: ['filters' => ['administrationId' => $administrationId]]) as $invoice) {
				$date = substr((string)($invoice['invoiceDate'] ?? ''), 0, 10);
				if ($date < $start || $date > $end || in_array((string)($invoice[$rule['field']] ?? ''), $rule['states'], true) === false) {
					continue;
				}

				$found[] = $rule['label'] . ' ' . (string)($invoice['invoiceNumber'] ?? $this->idOf(row: $invoice));
			}
		}

		return $found;
	}//end draftDocuments()

	/**
	 * The period before the return's, of the same kind.
	 *
	 * @param array<string,mixed> $vatReturn The BtwAangifte.
	 *
	 * @return array{kind:string,year:int,number:int,label:string}
	 */
	private function previousPeriod(array $vatReturn): array {
		$kind = (string)($vatReturn['period'] ?? 'quarter');
		$year = (int)($vatReturn['periodYear'] ?? 0);
		$number = (int)($vatReturn['periodNumber'] ?? 0);
		if ($kind === 'year') {
			return ['kind' => 'year', 'year' => ($year - 1), 'number' => $number, 'label' => (string)($year - 1)];
		}

		$last = 4;
		$prefix = 'Q';
		if ($kind === 'month') {
			$last = 12;
			$prefix = 'M';
		}

		if ($number > 1) {
			return ['kind' => $kind, 'year' => $year, 'number' => ($number - 1), 'label' => sprintf('%d %s%d', $year, $prefix, ($number - 1))];
		}

		return ['kind' => $kind, 'year' => ($year - 1), 'number' => $last, 'label' => sprintf('%d %s%d', ($year - 1), $prefix, $last)];
	}//end previousPeriod()

	/**
	 * The output and input VAT account numbers, as the posting mapper uses them.
	 *
	 * @return array{output:string,input:string}
	 */
	private function vatAccounts(): array {
		$accounts = [
			'output' => [MaterialiseGlTransactionAction::CFG_OUTPUT_VAT_ACCOUNT, '2110'],
			'input' => [MaterialiseGlTransactionAction::CFG_INPUT_VAT_ACCOUNT, '1230'],
		];
		$numbers = [];
		foreach ($accounts as $kind => [$key, $default]) {
			$configured = trim($this->appConfig->getValueString(Application::APP_ID, $key, ''));
			if ($configured === '') {
				$configured = $default;
			}

			$numbers[$kind] = $configured;
		}

		return ['output' => $numbers['output'], 'input' => $numbers['input']];
	}//end vatAccounts()

	/**
	 * One object as a plain array, null when it does not exist.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function row(string $schema, string $id): ?array {
		$found = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->find($id);
		if ($found === null) {
			return null;
		}

		$row = $found->getObject();
		$row['id'] = (string)($row['id'] ?? $id);
		return $row;
	}//end row()

	/**
	 * Objects of a schema as plain arrays.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $config The findAll config.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rows(string $schema, array $config): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->findAll($config);

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			$out[] = $row->getObject();
		}

		return $out;
	}//end rows()

	/**
	 * An object's id, at the top or under `@self` as OpenRegister renders it.
	 *
	 * @param array<string,mixed> $row The object.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
	}//end idOf()

	/**
	 * The configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
