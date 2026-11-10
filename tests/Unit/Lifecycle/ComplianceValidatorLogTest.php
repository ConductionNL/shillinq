<?php

/**
 * Characterisation tests for ComplianceValidator rule evaluation and its audit log lines.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-schatkistbankieren/specs/bookkeeping-schatkistbankieren/spec.md#REQ-SCHATKIST-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\ComplianceValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the outcome AND every audit log line (level, message, context) of
 * isCompliant() across rule types, severities and the segregation check's
 * self-detection and indeterminate paths.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ComplianceValidatorLogTest extends TestCase {

	/**
	 * Logged lines.
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * Reads made.
	 *
	 * @var list<string>
	 */
	private array $reads = [];

	/**
	 * Build the validator over rules and TreasuryAccount answers.
	 *
	 * @param list<mixed>|\Throwable $rules    BankingRule rows, or a failure.
	 * @param mixed                  $siblings TreasuryAccount answer, or a failure.
	 *
	 * @return ComplianceValidator The validator.
	 */
	private function validator(mixed $rules, mixed $siblings = []): ComplianceValidator {
		$schema = '';
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (string $name) use (&$schema, $objectService) {
				$schema = $name;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$schema, $rules, $siblings) {
				$this->reads[] = $schema . ' ' . json_encode($config['filters']);
				$answer = ($schema === 'BankingRule') ? $rules : $siblings;
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('books');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new ComplianceValidator($appConfig, $logger, $objectService);

	}//end validator()

	/**
	 * Rules mixing types and severities: outcome and log sequence.
	 *
	 * @return void
	 */
	public function testMixedRulesLogSequence(): void {
		$rules = [
			['ruleNumber' => 'R1', 'ruleType' => 'iban-format', 'evaluationCriteria' => ['pattern' => '^NL'], 'severity' => 'warning'],
			['ruleNumber' => 'R2', 'ruleType' => 'approval-required', 'evaluationCriteria' => []],
			['ruleType' => 'transaction-limit'],
			['ruleNumber' => 'R4', 'ruleType' => 'iban-format', 'evaluationCriteria' => ['pattern' => '(']],
		];
		$account = ['id' => 'acc-1', 'administrationId' => 'adm-1', 'iban' => 'DE89', 'approvalStatus' => 'pending'];

		self::assertFalse($this->validator($rules)->isCompliant($account));
		self::assertSame(
			[
				['info', 'ComplianceValidator: rule failed', ['accountId' => 'acc-1', 'ruleNumber' => 'R1', 'ruleType' => 'iban-format', 'severity' => 'warning']],
				['info', 'ComplianceValidator: rule failed', ['accountId' => 'acc-1', 'ruleNumber' => 'R2', 'ruleType' => 'approval-required', 'severity' => 'blocking']],
				['info', 'ComplianceValidator: rule failed', ['accountId' => 'acc-1', 'ruleNumber' => 'R4', 'ruleType' => 'iban-format', 'severity' => 'blocking']],
				['warning', 'ComplianceValidator: blocking rules failed — denying transition', ['accountId' => 'acc-1', 'failedRules' => ['R2', 'R4']]],
			],
			$this->logs
		);
		self::assertSame(['BankingRule {"isActive":true,"administrationId":"adm-1"}'], $this->reads);

	}//end testMixedRulesLogSequence()

	/**
	 * Only non-blocking failures: permitted, with the pass summary.
	 *
	 * @return void
	 */
	public function testNonBlockingFailuresPermit(): void {
		$rules = [['ruleType' => 'approval-required', 'severity' => 'informational']];
		$account = ['accountNumber' => 'NL-9', 'administrationId' => 'adm-1', 'approvalStatus' => 'draft'];

		self::assertTrue($this->validator($rules)->isCompliant($account));
		self::assertSame(
			[
				['info', 'ComplianceValidator: rule failed', ['accountId' => 'NL-9', 'ruleNumber' => 'unknown', 'ruleType' => 'approval-required', 'severity' => 'informational']],
				['debug', 'ComplianceValidator: all active blocking rules passed', ['accountId' => 'NL-9', 'rulesEvaluated' => 1]],
			],
			$this->logs
		);

	}//end testNonBlockingFailuresPermit()

	/**
	 * Missing administration, no rules, failing rule lookup.
	 *
	 * @return void
	 */
	public function testEarlyOutcomes(): void {
		self::assertFalse($this->validator([])->isCompliant(['administrationId' => '']));
		self::assertTrue($this->validator([])->isCompliant(['id' => 'a', 'administrationId' => 'adm-1']));
		self::assertFalse($this->validator(new RuntimeException('rules down'))->isCompliant(['administrationId' => 'adm-1']));
		self::assertSame(
			[
				['error', 'ComplianceValidator: missing administrationId — denying transition (fail-closed)', ['accountId' => 'unknown']],
				['debug', 'ComplianceValidator: no active BankingRules found for administration — transition permitted', ['accountId' => 'a', 'administrationId' => 'adm-1']],
				['error', 'ComplianceValidator: rule evaluation failed — denying transition (fail-closed)', ['accountId' => 'unknown', 'exception' => 'rules down']],
			],
			$this->logs
		);

	}//end testEarlyOutcomes()

	/**
	 * Segregation: self by id, self by number when there is no id, a real
	 * duplicate, junk rows, and the indeterminate paths.
	 *
	 * @return void
	 */
	public function testSegregationBranches(): void {
		$rule = [['ruleNumber' => 'S1', 'ruleType' => 'segregation', 'evaluationCriteria' => []]];
		$byId = ['id' => 'acc-1', 'accountNumber' => 'N1', 'administrationId' => 'adm-1', 'iban' => 'NL01'];
		$byNumber = ['accountNumber' => 'N1', 'administrationId' => 'adm-1', 'iban' => 'NL01'];

		self::assertTrue($this->validator($rule, [['id' => 'acc-1', 'accountNumber' => 'other'], 'junk'])->isCompliant($byId));
		self::assertTrue($this->validator($rule, [['accountNumber' => 'N1']])->isCompliant($byNumber));
		$this->logs = [];
		self::assertFalse($this->validator($rule, [['id' => 'acc-2'], ['accountNumber' => 'N1'], []])->isCompliant($byId));
		self::assertSame(
			['warning', 'ComplianceValidator: segregation rule violation — duplicate IBAN within administration', ['accountId' => 'acc-1', 'administrationId' => 'adm-1', 'iban' => 'NL01', 'conflictingAccountIds' => ['acc-2', 'N1', 'unknown']]],
			$this->logs[0]
		);
		self::assertSame('TreasuryAccount {"administrationId":"adm-1","iban":"NL01"}', end($this->reads));

		$this->logs = [];
		self::assertFalse($this->validator($rule, new RuntimeException('ta down'))->isCompliant($byId));
		self::assertSame(['warning', 'ComplianceValidator: segregation check indeterminate — TreasuryAccount lookup failed; denying (fail-closed, NOT a pass)', ['accountId' => 'acc-1', 'administrationId' => 'adm-1', 'exception' => 'ta down']], $this->logs[0]);

		$this->logs = [];
		self::assertFalse($this->validator($rule)->isCompliant(['administrationId' => 'adm-1']));
		self::assertSame(['warning', 'ComplianceValidator: segregation check indeterminate — missing iban or administrationId; denying (fail-closed, NOT a pass)', ['accountId' => 'unknown', 'administrationId' => 'adm-1']], $this->logs[0]);

		self::assertTrue($this->validator([['ruleType' => 'segregation', 'evaluationCriteria' => ['checkDuplicates' => false]]])->isCompliant(['administrationId' => 'adm-1']));

	}//end testSegregationBranches()
}//end class
