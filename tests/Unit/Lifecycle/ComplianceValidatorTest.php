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
 */
class ComplianceValidatorTest extends TestCase
{

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
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $this->logger    = $this->createMock(originalClassName: LoggerInterface::class);

        $this->appConfig
            ->method('getValueString')
            ->with(Application::APP_ID, 'register', 'shillinq')
            ->willReturn('shillinq');

        $this->validator = new ComplianceValidator(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Build a minimal valid TreasuryAccount fixture.
     *
     * @param array<string, mixed> $overrides Field overrides.
     *
     * @return array<string, mixed>
     */
    private function buildAccount(array $overrides=[]): array
    {
        return array_merge(
            [
                'id'               => 'acct-001',
                'accountNumber'    => 'TR-NL-001',
                'iban'             => 'NL91ABNA0417164300',
                'administrationId' => 'adm-1',
                'approvalStatus'   => 'approved',
                'lifecycleState'   => 'configured',
            ],
            $overrides
        );

    }//end buildAccount()

    /**
     * Build a mock ObjectService that returns the given rules from findAll().
     *
     * @param array<array<string, mixed>> $rules Rules to return.
     *
     * @return object
     */
    private function buildObjectServiceMock(array $rules): object
    {
        $objectService = new class($rules) {
            /**
             * Construct with pre-configured rule fixtures.
             *
             * @param array<array<string, mixed>> $rules Rules returned from findAll().
             */
            public function __construct(private readonly array $rules)
            {
            }//end __construct()

            /**
             * Fluent register setter stub — returns self for chaining.
             *
             * @param string $register Register slug (ignored).
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            /**
             * Fluent schema setter stub — returns self for chaining.
             *
             * @param string $schema Schema name (ignored).
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                return $this;
            }//end setSchema()

            /**
             * Return the pre-configured rule fixtures.
             *
             * @param array<string, mixed> $params Find parameters (ignored).
             *
             * @return array<array<string, mixed>>
             */
            public function findAll(array $params=[]): array
            {
                return $this->rules;
            }//end findAll()
        };

        return $objectService;

    }//end buildObjectServiceMock()

    /**
     * All active rules pass → isCompliant returns true (happy path).
     *
     * @return void
     */
    public function testAllRulesPassReturnsTrue(): void
    {
        $rules = [
            [
                'ruleNumber'         => 'rule-iban-format',
                'ruleType'           => 'iban-format',
                'severity'           => 'blocking',
                'isActive'           => true,
                'evaluationCriteria' => ['pattern' => '^NL[0-9]{2}[A-Z]{4}[0-9]{10}$'],
            ],
            [
                'ruleNumber'         => 'rule-approval-required',
                'ruleType'           => 'approval-required',
                'severity'           => 'blocking',
                'isActive'           => true,
                'evaluationCriteria' => ['requiresTreasurerApproval' => true],
            ],
        ];

        $this->container
            ->method('get')
            ->willReturn($this->buildObjectServiceMock(rules: $rules));

        $account = $this->buildAccount();
        $result  = $this->validator->isCompliant(account: $account);

        $this->assertTrue(condition: $result, message: 'Expected isCompliant() to return true when all blocking rules pass.');

    }//end testAllRulesPassReturnsTrue()

    /**
     * IBAN format rule fails with blocking severity → isCompliant returns false.
     *
     * @return void
     */
    public function testIbanFormatFailureBlocksActivation(): void
    {
        $rules = [
            [
                'ruleNumber'         => 'rule-iban-format',
                'ruleType'           => 'iban-format',
                'severity'           => 'blocking',
                'isActive'           => true,
                'evaluationCriteria' => ['pattern' => '^NL[0-9]{2}[A-Z]{4}[0-9]{10}$'],
            ],
        ];

        $this->container
            ->method('get')
            ->willReturn($this->buildObjectServiceMock(rules: $rules));

        // Invalid IBAN — too short.
        $account = $this->buildAccount(overrides: ['iban' => 'NL91ABNA04171643']);
        $result  = $this->validator->isCompliant(account: $account);

        $this->assertFalse(condition: $result, message: 'Expected isCompliant() to return false when IBAN format rule fails.');

    }//end testIbanFormatFailureBlocksActivation()

    /**
     * Approval-required rule fails when approvalStatus is pending → returns false.
     *
     * @return void
     */
    public function testApprovalRequiredFailureBlocksActivation(): void
    {
        $rules = [
            [
                'ruleNumber'         => 'rule-approval-required',
                'ruleType'           => 'approval-required',
                'severity'           => 'blocking',
                'isActive'           => true,
                'evaluationCriteria' => ['requiresTreasurerApproval' => true],
            ],
        ];

        $this->container
            ->method('get')
            ->willReturn($this->buildObjectServiceMock(rules: $rules));

        $account = $this->buildAccount(overrides: ['approvalStatus' => 'pending']);
        $result  = $this->validator->isCompliant(account: $account);

        $this->assertFalse(condition: $result, message: 'Expected isCompliant() to return false when approval is pending.');

    }//end testApprovalRequiredFailureBlocksActivation()

    /**
     * No active rules found → isCompliant returns true (no criteria = trivially compliant).
     *
     * @return void
     */
    public function testNoActiveRulesPermitsTransition(): void
    {
        $this->container
            ->method('get')
            ->willReturn($this->buildObjectServiceMock(rules: []));

        $account = $this->buildAccount();
        $result  = $this->validator->isCompliant(account: $account);

        $this->assertTrue(condition: $result, message: 'Expected isCompliant() to return true when no active rules exist.');

    }//end testNoActiveRulesPermitsTransition()

    /**
     * Missing administrationId → fail-closed (returns false without calling ObjectService).
     *
     * @return void
     */
    public function testMissingAdministrationIdFailsClosed(): void
    {
        $this->container->expects($this->never())->method('get');

        $account = $this->buildAccount(overrides: ['administrationId' => '']);
        $result  = $this->validator->isCompliant(account: $account);

        $this->assertFalse(condition: $result, message: 'Expected isCompliant() to return false when administrationId is missing.');

    }//end testMissingAdministrationIdFailsClosed()

    /**
     * ObjectService throws → fail-closed (returns false).
     *
     * @return void
     */
    public function testObjectServiceExceptionFailsClosed(): void
    {
        $this->container
            ->method('get')
            ->willThrowException(new \RuntimeException('ObjectService unavailable'));

        $account = $this->buildAccount();
        $result  = $this->validator->isCompliant(account: $account);

        $this->assertFalse(condition: $result, message: 'Expected isCompliant() to return false when ObjectService throws.');

    }//end testObjectServiceExceptionFailsClosed()

    /**
     * Warning-severity rule failure does not block activation.
     *
     * @return void
     */
    public function testWarningSeverityFailureDoesNotBlock(): void
    {
        $rules = [
            [
                'ruleNumber'         => 'rule-iban-format',
                'ruleType'           => 'iban-format',
                'severity'           => 'warning',
                'isActive'           => true,
                'evaluationCriteria' => ['pattern' => '^NL[0-9]{2}[A-Z]{4}[0-9]{10}$'],
            ],
        ];

        $this->container
            ->method('get')
            ->willReturn($this->buildObjectServiceMock(rules: $rules));

        // Invalid IBAN but the rule is only a warning, not blocking.
        $account = $this->buildAccount(overrides: ['iban' => 'NL91ABNA04171643']);
        $result  = $this->validator->isCompliant(account: $account);

        $this->assertTrue(condition: $result, message: 'Expected isCompliant() to return true when only warning-severity rules fail.');

    }//end testWarningSeverityFailureDoesNotBlock()

    /**
     * Multi-criteria check: one rule passes, another (blocking) fails → returns false.
     *
     * Corresponds to REQ-SCHATKIST-005 scenario: multi-criteria check fails on segregation only.
     *
     * @return void
     */
    public function testMultiCriteriaBlockingFailureReturnsFalse(): void
    {
        $rules = [
            [
                'ruleNumber'         => 'rule-iban-format',
                'ruleType'           => 'iban-format',
                'severity'           => 'blocking',
                'isActive'           => true,
                'evaluationCriteria' => ['pattern' => '^NL[0-9]{2}[A-Z]{4}[0-9]{10}$'],
            ],
            [
                'ruleNumber'         => 'rule-approval-required',
                'ruleType'           => 'approval-required',
                'severity'           => 'blocking',
                'isActive'           => true,
                'evaluationCriteria' => ['requiresTreasurerApproval' => true],
            ],
        ];

        $this->container
            ->method('get')
            ->willReturn($this->buildObjectServiceMock(rules: $rules));

        // IBAN passes, but approval is still pending.
        $account = $this->buildAccount(overrides: ['approvalStatus' => 'pending']);
        $result  = $this->validator->isCompliant(account: $account);

        $this->assertFalse(
            condition: $result,
            message: 'Expected isCompliant() to return false when one blocking rule fails even if others pass.'
        );

    }//end testMultiCriteriaBlockingFailureReturnsFalse()
}//end class
