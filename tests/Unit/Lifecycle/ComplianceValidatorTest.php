<?php

/**
 * Unit tests for ComplianceValidator.
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

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\ComplianceValidator;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ComplianceValidator.
 *
 * Covers:
 * - Happy path: all active blocking rules pass → transition allowed
 * - Blocking rule failure: IBAN format mismatch → transition denied
 * - Approval-required rule: missing approval → transition denied
 * - No active rules: empty rule set → transition permitted
 * - Missing administrationId → fail-closed (false)
 * - ObjectService exception → fail-closed (false)
 * - Segregation rule: duplicate IBAN in administration → violation (false)
 * - Segregation rule: no duplicate → passes (true)
 * - Segregation rule: missing data / lookup failure → indeterminate, fail-closed (false)
 */
class ComplianceValidatorTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The validator under test.
	 *
	 * @var ComplianceValidator
	 */
	private ComplianceValidator $validator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->appConfig
			->method('getValueString')
			->with(Application::APP_ID, 'register', 'shillinq')
			->willReturn('shillinq');

		$this->validator = $this->buildValidator(
			store: $this->buildObjectServiceMock(bankingRuleRules: [])
		);

	}//end setUp()

	/**
	 * Build the validator over a seeded in-memory store.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so a test's
	 * store has to be present when the validator is built — parking it on the
	 * container after the fact leaves the validator reading an empty world.
	 *
	 * @param object $store The duck-typed in-memory ObjectService double.
	 *
	 * @return ComplianceValidator
	 */
	private function buildValidator(object $store): ComplianceValidator {
		return new ComplianceValidator(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildValidator()

	/**
	 * Build a minimal valid TreasuryAccount fixture.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function buildAccount(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'acct-001',
				'accountNumber' => 'TR-NL-001',
				'iban' => 'NL91ABNA0417164300',
				'administrationId' => 'adm-1',
				'approvalStatus' => 'approved',
				'lifecycleState' => 'configured',
			],
			$overrides
		);

	}//end buildAccount()

	/**
	 * Build a mock ObjectService that returns per-schema fixtures from findAll(),
	 * routed by the most recent setSchema() call — mirrors OR's real fluent API
	 * where BankingRule and TreasuryAccount lookups go through the same service.
	 *
	 * @param array<array<string, mixed>> $bankingRuleRules Rules returned when schema=BankingRule.
	 * @param array<array<string, mixed>> $treasuryAccountRows Rows returned when schema=TreasuryAccount.
	 *
	 * @return object
	 */
	private function buildObjectServiceMock(array $bankingRuleRules, array $treasuryAccountRows = []): object {
		$objectService = new class($bankingRuleRules, $treasuryAccountRows) {

			/**
			 * Currently selected schema, set by the most recent setSchema() call.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Construct with pre-configured per-schema fixtures.
			 *
			 * @param array<array<string, mixed>> $bankingRuleRules BankingRule fixtures.
			 * @param array<array<string, mixed>> $treasuryAccountRows TreasuryAccount fixtures.
			 */
			public function __construct(
				private readonly array $bankingRuleRules,
				private readonly array $treasuryAccountRows,
			) {
			}//end __construct()

			/**
			 * Fluent register setter stub — returns self for chaining.
			 *
			 * @param string $register Register slug (ignored).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter stub — records the schema so findAll() can route.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return the pre-configured fixture for the most recently selected schema.
			 *
			 * @param array<string, mixed> $params Find parameters (ignored).
			 *
			 * @return array<array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return match ($this->currentSchema) {
					'TreasuryAccount' => $this->treasuryAccountRows,
					default => $this->bankingRuleRules,
				};
			}//end findAll()
		};

		return $objectService;
	}//end buildObjectServiceMock()

	/**
	 * Build a mock ObjectService whose TreasuryAccount lookup (used by the
	 * segregation check) throws, to exercise the indeterminate/fail-closed path.
	 *
	 * @param array<array<string, mixed>> $bankingRuleRules Rules returned when schema=BankingRule.
	 *
	 * @return object
	 */
	private function buildObjectServiceMockWithFailingTreasuryLookup(array $bankingRuleRules): object {
		$objectService = new class($bankingRuleRules) {

			/**
			 * Currently selected schema, set by the most recent setSchema() call.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Construct with pre-configured BankingRule fixtures.
			 *
			 * @param array<array<string, mixed>> $bankingRuleRules BankingRule fixtures.
			 */
			public function __construct(
				private readonly array $bankingRuleRules,
			) {
			}//end __construct()

			/**
			 * Fluent register setter stub — returns self for chaining.
			 *
			 * @param string $register Register slug (ignored).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter stub — records the schema so findAll() can route.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return BankingRule fixtures, or throw for TreasuryAccount lookups.
			 *
			 * @param array<string, mixed> $params Find parameters (ignored).
			 *
			 * @return array<array<string, mixed>>
			 *
			 * @throws \RuntimeException When schema=TreasuryAccount (simulates a lookup failure).
			 */
			public function findAll(array $params = []): array {
				if ($this->currentSchema === 'TreasuryAccount') {
					throw new \RuntimeException('TreasuryAccount lookup unavailable');
				}

				return $this->bankingRuleRules;
			}//end findAll()
		};

		return $objectService;
	}//end buildObjectServiceMockWithFailingTreasuryLookup()

	/**
	 * Build a store that models an unavailable OpenRegister.
	 *
	 * Before ADR-084 this scenario was expressed as
	 * `$container->method('get')->willThrowException(...)`. The container is no
	 * longer consulted, so the refusal has to come from the store itself; every
	 * read throws exactly as a downed ObjectService would, which is what the
	 * validator's fail-closed arm is there to catch.
	 *
	 * @return object
	 */
	private function buildUnavailableObjectServiceMock(): object {
		return new class {
			/**
			 * Fluent register setter — returns self.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — returns self.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse every list query.
			 *
			 * @param array<string,mixed> $params Query parameters (unused).
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('ObjectService unavailable');
			}//end findAll()

			/**
			 * Refuse every single-object lookup.
			 *
			 * @param string|int $id Object ID.
			 *
			 * @return object|null
			 *
			 * @throws \RuntimeException Always.
			 */
			public function find(string|int $id): ?object {
				throw new \RuntimeException('ObjectService unavailable');
			}//end find()
		};
	}//end buildUnavailableObjectServiceMock()

	/**
	 * All active rules pass → isCompliant returns true (happy path).
	 *
	 * @return void
	 */
	public function testAllRulesPassReturnsTrue(): void {
		$rules = [
			[
				'ruleNumber' => 'rule-iban-format',
				'ruleType' => 'iban-format',
				'severity' => 'blocking',
				'isActive' => true,
				'evaluationCriteria' => ['pattern' => '^NL[0-9]{2}[A-Z]{4}[0-9]{10}$'],
			],
			[
				'ruleNumber' => 'rule-approval-required',
				'ruleType' => 'approval-required',
				'severity' => 'blocking',
				'isActive' => true,
				'evaluationCriteria' => ['requiresTreasurerApproval' => true],
			],
		];

		$this->validator = $this->buildValidator(
			store: $this->buildObjectServiceMock(bankingRuleRules: $rules));

		$account = $this->buildAccount();
		$result = $this->validator->isCompliant(account: $account);

		$this->assertTrue(condition: $result, message: 'Expected isCompliant() to return true when all blocking rules pass.');

	}//end testAllRulesPassReturnsTrue()

	/**
	 * IBAN format rule fails with blocking severity → isCompliant returns false.
	 *
	 * @return void
	 */
	public function testIbanFormatFailureBlocksActivation(): void {
		$rules = [
			[
				'ruleNumber' => 'rule-iban-format',
				'ruleType' => 'iban-format',
				'severity' => 'blocking',
				'isActive' => true,
				'evaluationCriteria' => ['pattern' => '^NL[0-9]{2}[A-Z]{4}[0-9]{10}$'],
			],
		];

		$this->validator = $this->buildValidator(
			store: $this->buildObjectServiceMock(bankingRuleRules: $rules));

		// Invalid IBAN — too short.
		$account = $this->buildAccount(overrides: ['iban' => 'NL91ABNA04171643']);
		$result = $this->validator->isCompliant(account: $account);

		$this->assertFalse(condition: $result, message: 'Expected isCompliant() to return false when IBAN format rule fails.');

	}//end testIbanFormatFailureBlocksActivation()

	/**
	 * Approval-required rule fails when approvalStatus is pending → returns false.
	 *
	 * @return void
	 */
	public function testApprovalRequiredFailureBlocksActivation(): void {
		$rules = [
			[
				'ruleNumber' => 'rule-approval-required',
				'ruleType' => 'approval-required',
				'severity' => 'blocking',
				'isActive' => true,
				'evaluationCriteria' => ['requiresTreasurerApproval' => true],
			],
		];

		$this->validator = $this->buildValidator(
			store: $this->buildObjectServiceMock(bankingRuleRules: $rules));

		$account = $this->buildAccount(overrides: ['approvalStatus' => 'pending']);
		$result = $this->validator->isCompliant(account: $account);

		$this->assertFalse(condition: $result, message: 'Expected isCompliant() to return false when approval is pending.');

	}//end testApprovalRequiredFailureBlocksActivation()

	/**
	 * No active rules found → isCompliant returns true (no criteria = trivially compliant).
	 *
	 * @return void
	 */
	public function testNoActiveRulesPermitsTransition(): void {
		$this->validator = $this->buildValidator(
			store: $this->buildObjectServiceMock(bankingRuleRules: []));

		$account = $this->buildAccount();
		$result = $this->validator->isCompliant(account: $account);

		$this->assertTrue(condition: $result, message: 'Expected isCompliant() to return true when no active rules exist.');

	}//end testNoActiveRulesPermitsTransition()

	/**
	 * Missing administrationId → fail-closed (returns false without calling ObjectService).
	 *
	 * @return void
	 */
	public function testMissingAdministrationIdFailsClosed(): void {
		$this->container->expects($this->never())->method('get');

		$account = $this->buildAccount(overrides: ['administrationId' => '']);
		$result = $this->validator->isCompliant(account: $account);

		$this->assertFalse(condition: $result, message: 'Expected isCompliant() to return false when administrationId is missing.');

	}//end testMissingAdministrationIdFailsClosed()

	/**
	 * ObjectService throws → fail-closed (returns false).
	 *
	 * @return void
	 */
	public function testObjectServiceExceptionFailsClosed(): void {
		$this->validator = $this->buildValidator(store: $this->buildUnavailableObjectServiceMock());

		$account = $this->buildAccount();
		$result = $this->validator->isCompliant(account: $account);

		$this->assertFalse(condition: $result, message: 'Expected isCompliant() to return false when ObjectService throws.');

	}//end testObjectServiceExceptionFailsClosed()

	/**
	 * Warning-severity rule failure does not block activation.
	 *
	 * @return void
	 */
	public function testWarningSeverityFailureDoesNotBlock(): void {
		$rules = [
			[
				'ruleNumber' => 'rule-iban-format',
				'ruleType' => 'iban-format',
				'severity' => 'warning',
				'isActive' => true,
				'evaluationCriteria' => ['pattern' => '^NL[0-9]{2}[A-Z]{4}[0-9]{10}$'],
			],
		];

		$this->validator = $this->buildValidator(
			store: $this->buildObjectServiceMock(bankingRuleRules: $rules));

		// Invalid IBAN but the rule is only a warning, not blocking.
		$account = $this->buildAccount(overrides: ['iban' => 'NL91ABNA04171643']);
		$result = $this->validator->isCompliant(account: $account);

		$this->assertTrue(condition: $result, message: 'Expected isCompliant() to return true when only warning-severity rules fail.');

	}//end testWarningSeverityFailureDoesNotBlock()

	/**
	 * Multi-criteria check: one rule passes, another (blocking) fails → returns false.
	 *
	 * Corresponds to REQ-SCHATKIST-005 scenario: multi-criteria check fails on segregation only.
	 *
	 * @return void
	 */
	public function testMultiCriteriaBlockingFailureReturnsFalse(): void {
		$rules = [
			[
				'ruleNumber' => 'rule-iban-format',
				'ruleType' => 'iban-format',
				'severity' => 'blocking',
				'isActive' => true,
				'evaluationCriteria' => ['pattern' => '^NL[0-9]{2}[A-Z]{4}[0-9]{10}$'],
			],
			[
				'ruleNumber' => 'rule-approval-required',
				'ruleType' => 'approval-required',
				'severity' => 'blocking',
				'isActive' => true,
				'evaluationCriteria' => ['requiresTreasurerApproval' => true],
			],
		];

		$this->validator = $this->buildValidator(
			store: $this->buildObjectServiceMock(bankingRuleRules: $rules));

		// IBAN passes, but approval is still pending.
		$account = $this->buildAccount(overrides: ['approvalStatus' => 'pending']);
		$result = $this->validator->isCompliant(account: $account);

		$this->assertFalse(
			condition: $result,
			message: 'Expected isCompliant() to return false when one blocking rule fails even if others pass.'
		);

	}//end testMultiCriteriaBlockingFailureReturnsFalse()

	/**
	 * BAD PATH: another TreasuryAccount in the same administration already has the
	 * same IBAN → the segregation rule MUST report a violation (isCompliant() false).
	 *
	 * Proves the fix: prior to this change, ruleType=segregation was hardcoded
	 * `=> true` in evaluateRule() and this exact scenario would have wrongly passed.
	 *
	 * Corresponds to REQ-SCHATKIST-003 scenario: "Segregation rule prevents
	 * duplicate IBANs within administration".
	 *
	 * @return void
	 */
	public function testSegregationRuleDetectsDuplicateIbanInAdministration(): void {
		$rules = [
			[
				'ruleNumber' => 'rule-segregation',
				'ruleType' => 'segregation',
				'severity' => 'blocking',
				'isActive' => true,
				'evaluationCriteria' => ['checkDuplicates' => true],
			],
		];

		// A different, already-existing TreasuryAccount in the same administration
		// shares this account's IBAN.
		$treasuryAccounts = [
			[
				'id' => 'acct-002',
				'accountNumber' => 'TR-NL-002',
				'iban' => 'NL91ABNA0417164300',
				'administrationId' => 'adm-1',
			],
		];

		$this->validator = $this->buildValidator(
			store: $this->buildObjectServiceMock(bankingRuleRules: $rules, treasuryAccountRows: $treasuryAccounts)
		);

		$account = $this->buildAccount(overrides: ['id' => 'acct-001']);
		$result = $this->validator->isCompliant(account: $account);

		$this->assertFalse(
			condition: $result,
			message: 'Expected isCompliant() to return false (violation) when another TreasuryAccount in the same administration shares this IBAN.'
		);

	}//end testSegregationRuleDetectsDuplicateIbanInAdministration()

	/**
	 * GOOD PATH: no other TreasuryAccount in the administration shares this IBAN →
	 * the segregation rule passes.
	 *
	 * @return void
	 */
	public function testSegregationRulePassesWhenNoDuplicateIban(): void {
		$rules = [
			[
				'ruleNumber' => 'rule-segregation',
				'ruleType' => 'segregation',
				'severity' => 'blocking',
				'isActive' => true,
				'evaluationCriteria' => ['checkDuplicates' => true],
			],
		];

		// The TreasuryAccount findAll() stub only ever returns rows that "matched"
		// the filter; the account under evaluation matching itself is the only row.
		$treasuryAccounts = [
			[
				'id' => 'acct-001',
				'accountNumber' => 'TR-NL-001',
				'iban' => 'NL91ABNA0417164300',
				'administrationId' => 'adm-1',
			],
		];

		$this->validator = $this->buildValidator(
			store: $this->buildObjectServiceMock(bankingRuleRules: $rules, treasuryAccountRows: $treasuryAccounts)
		);

		$account = $this->buildAccount(overrides: ['id' => 'acct-001']);
		$result = $this->validator->isCompliant(account: $account);

		$this->assertTrue(
			condition: $result,
			message: 'Expected isCompliant() to return true when the only IBAN match is the account itself (no real duplicate).'
		);

	}//end testSegregationRulePassesWhenNoDuplicateIban()

	/**
	 * NO-DATA PATH: the TreasuryAccount lookup required to evaluate the segregation
	 * rule fails → the control MUST report indeterminate (fail-closed, false), NOT
	 * a fabricated pass.
	 *
	 * @return void
	 */
	public function testSegregationRuleIndeterminateOnLookupFailureDoesNotPass(): void {
		$rules = [
			[
				'ruleNumber' => 'rule-segregation',
				'ruleType' => 'segregation',
				'severity' => 'blocking',
				'isActive' => true,
				'evaluationCriteria' => ['checkDuplicates' => true],
			],
		];

		$this->validator = $this->buildValidator(
			store: $this->buildObjectServiceMockWithFailingTreasuryLookup(bankingRuleRules: $rules));

		$account = $this->buildAccount(overrides: ['id' => 'acct-001']);
		$result = $this->validator->isCompliant(account: $account);

		$this->assertFalse(
			condition: $result,
			message: 'Expected isCompliant() to return false (indeterminate, fail-closed) when the '
				. 'segregation duplicate-IBAN lookup fails — never a fabricated pass.'
		);

	}//end testSegregationRuleIndeterminateOnLookupFailureDoesNotPass()

	/**
	 * NO-DATA PATH: missing IBAN on the account under evaluation → segregation
	 * check cannot run → indeterminate, fail-closed (false), not a pass.
	 *
	 * @return void
	 */
	public function testSegregationRuleIndeterminateOnMissingIbanDoesNotPass(): void {
		$rules = [
			[
				'ruleNumber' => 'rule-segregation',
				'ruleType' => 'segregation',
				'severity' => 'blocking',
				'isActive' => true,
				'evaluationCriteria' => ['checkDuplicates' => true],
			],
		];

		$this->validator = $this->buildValidator(
			store: $this->buildObjectServiceMock(bankingRuleRules: $rules, treasuryAccountRows: []));

		$account = $this->buildAccount(overrides: ['iban' => '']);
		$result = $this->validator->isCompliant(account: $account);

		$this->assertFalse(
			condition: $result,
			message: 'Expected isCompliant() to return false (indeterminate, fail-closed) when the account has no IBAN to check.'
		);

	}//end testSegregationRuleIndeterminateOnMissingIbanDoesNotPass()

	/**
	 * The checkDuplicates=false criterion explicitly disables the duplicate-IBAN
	 * check → rule passes without querying TreasuryAccount at all.
	 *
	 * @return void
	 */
	public function testSegregationRuleSkipsCheckWhenCheckDuplicatesIsFalse(): void {
		$rules = [
			[
				'ruleNumber' => 'rule-segregation',
				'ruleType' => 'segregation',
				'severity' => 'blocking',
				'isActive' => true,
				'evaluationCriteria' => ['checkDuplicates' => false],
			],
		];

		// A failing TreasuryAccount lookup proves the rule genuinely short-circuits
		// rather than happening to pass because the stub returns no rows.
		$this->validator = $this->buildValidator(
			store: $this->buildObjectServiceMockWithFailingTreasuryLookup(bankingRuleRules: $rules));

		$account = $this->buildAccount();
		$result = $this->validator->isCompliant(account: $account);

		$this->assertTrue(
			condition: $result,
			message: 'Expected isCompliant() to return true when checkDuplicates=false disables the segregation check.'
		);

	}//end testSegregationRuleSkipsCheckWhenCheckDuplicatesIsFalse()
}//end class
