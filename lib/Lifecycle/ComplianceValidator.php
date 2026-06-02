<?php

/**
 * Compliance Validator
 *
 * Multi-criteria compliance precondition for TreasuryAccount lifecycle transitions.
 * This is an ADR-031 exception: a single-method PHP lifecycle guard called *by* the
 * OR lifecycle engine from the TreasuryAccount schema's x-openregister-lifecycle
 * `requires:` clause. It does not replace the declarative lifecycle — it is a thin
 * seam for multi-criteria conditional rule evaluation that the OR engine cannot yet
 * express declaratively.
 *
 * Remove this class when OR's lifecycle engine supports multi-criteria conditional
 * precondition clauses (ADR-031 §Exceptions, point 1).
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
 * @spec openspec/changes/bookkeeping-schatkistbankieren/specs/bookkeeping-schatkistbankieren/spec.md#REQ-SCHATKIST-005
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Single-method compliance precondition for TreasuryAccount lifecycle transitions.
 *
 * Evaluates all active BankingRule criteria applicable to the treasury account's
 * administration. If ALL criteria pass, returns true (transition permitted).
 * If ANY criterion fails with severity=blocking, returns false.
 *
 * Called from x-openregister-lifecycle `requires: OCA\Shillinq\Lifecycle\ComplianceValidator::isCompliant`
 * on the `activate`, `monitor`, and `reactivate` transitions.
 *
 * ADR-031 exception: multi-criteria conditional logic not yet expressible declaratively.
 *
 * @spec openspec/changes/bookkeeping-schatkistbankieren/specs/bookkeeping-schatkistbankieren/spec.md#REQ-SCHATKIST-005
 */
class ComplianceValidator
{
    /**
     * Construct ComplianceValidator with lazy-loaded ObjectService.
     *
     * @param ContainerInterface $container DI container — OR's ObjectService fetched lazily.
     * @param IAppConfig         $appConfig App config for dynamic register slug resolution.
     * @param LoggerInterface    $logger    Nextcloud logger for compliance audit logging.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return the configured register slug, falling back to 'shillinq' if unset.
     *
     * @return string
     */
    private function getRegisterSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($slug === '') {
            return 'shillinq';
        }

        return $slug;

    }//end getRegisterSlug()

    /**
     * Evaluate all active BankingRule criteria for the account's administration.
     *
     * Per REQ-SCHATKIST-005: ALL active BankingRule records applicable to the
     * administrationId are evaluated. If any blocking rule fails, returns false.
     * Non-blocking failures (warning/informational) are logged but do not block.
     *
     * Fail-closed: any unexpected error denies the transition and logs the reason.
     *
     * @param array<string, mixed> $account TreasuryAccount object array (loaded by OR lifecycle engine).
     *
     * @return bool True when all active blocking rules pass; false otherwise.
     *
     * @spec openspec/changes/bookkeeping-schatkistbankieren/specs/bookkeeping-schatkistbankieren/spec.md#REQ-SCHATKIST-005
     */
    public function isCompliant(array $account): bool
    {
        $administrationId = ($account['administrationId'] ?? null);
        $accountId        = ($account['id'] ?? $account['accountNumber'] ?? 'unknown');

        if ($administrationId === null || $administrationId === '') {
            $this->logger->error(
                'ComplianceValidator: missing administrationId — denying transition (fail-closed)',
                ['accountId' => $accountId]
            );
            return false;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $rules = $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema('BankingRule')
                ->findAll(
                    [
                        'filters' => [
                            'isActive'         => true,
                            'administrationId' => $administrationId,
                        ],
                    ]
                );

            if (count($rules) === 0) {
                $this->logger->debug(
                    'ComplianceValidator: no active BankingRules found for administration — transition permitted',
                    ['accountId' => $accountId, 'administrationId' => $administrationId]
                );
                return true;
            }

            $blockingFailures = [];
            foreach ($rules as $rule) {
                $passed = $this->evaluateRule(rule: $rule, account: $account);
                if ($passed === false) {
                    $severity = ($rule['severity'] ?? 'blocking');
                    if ($severity === 'blocking') {
                        $blockingFailures[] = ($rule['ruleNumber'] ?? 'unknown');
                    }

                    $this->logger->info(
                        'ComplianceValidator: rule failed',
                        [
                            'accountId'  => $accountId,
                            'ruleNumber' => ($rule['ruleNumber'] ?? 'unknown'),
                            'ruleType'   => ($rule['ruleType'] ?? 'unknown'),
                            'severity'   => $severity,
                        ]
                    );
                }
            }

            if (count($blockingFailures) > 0) {
                $this->logger->warning(
                    'ComplianceValidator: blocking rules failed — denying transition',
                    ['accountId' => $accountId, 'failedRules' => $blockingFailures]
                );
                return false;
            }

            $this->logger->debug(
                'ComplianceValidator: all active blocking rules passed',
                ['accountId' => $accountId, 'rulesEvaluated' => count($rules)]
            );
            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'ComplianceValidator: rule evaluation failed — denying transition (fail-closed)',
                ['accountId' => $accountId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try

    }//end isCompliant()

    /**
     * Evaluate a single BankingRule against the treasury account.
     *
     * Per REQ-SCHATKIST-003 ruleType semantics:
     * - iban-format: validates account.iban against evaluationCriteria.pattern
     * - segregation: always passes here (IBAN uniqueness is enforced at save time by OR)
     * - approval-required: checks that approvalStatus is 'approved' or 'not-required'
     * - Other types: returns true (not yet implemented; non-blocking default)
     *
     * @param array<string, mixed> $rule    BankingRule object.
     * @param array<string, mixed> $account TreasuryAccount object.
     *
     * @return bool True when the rule passes.
     */
    private function evaluateRule(array $rule, array $account): bool
    {
        $ruleType = ($rule['ruleType'] ?? '');
        $criteria = ($rule['evaluationCriteria'] ?? []);

        return match ($ruleType) {
            'iban-format'       => $this->evaluateIbanFormat(criteria: $criteria, account: $account),
            'approval-required' => $this->evaluateApprovalRequired(criteria: $criteria, account: $account),
            'segregation'       => true,
            default             => true,
        };

    }//end evaluateRule()

    /**
     * Validate IBAN against pattern in evaluationCriteria.
     *
     * @param array<string, mixed> $criteria Rule's evaluationCriteria.
     * @param array<string, mixed> $account  TreasuryAccount object.
     *
     * @return bool
     */
    private function evaluateIbanFormat(array $criteria, array $account): bool
    {
        $pattern = ($criteria['pattern'] ?? null);
        $iban    = ($account['iban'] ?? '');

        if ($pattern === null || $iban === '') {
            return false;
        }

        return (bool) preg_match(pattern: '/'.$pattern.'/', subject: $iban);

    }//end evaluateIbanFormat()

    /**
     * Check that the account's approvalStatus satisfies the approval-required rule.
     *
     * Per REQ-SCHATKIST-003 / ADR-022: approval is consumed from OR's approval-workflow.
     * This check verifies the approvalStatus field that OR sets after workflow completion.
     *
     * @param array<string, mixed> $criteria Rule's evaluationCriteria.
     * @param array<string, mixed> $account  TreasuryAccount object.
     *
     * @return bool
     */
    private function evaluateApprovalRequired(array $criteria, array $account): bool
    {
        $requiresApproval = ($criteria['requiresTreasurerApproval'] ?? true);
        if ($requiresApproval === false) {
            return true;
        }

        $approvalStatus = ($account['approvalStatus'] ?? 'pending');
        return in_array(needle: $approvalStatus, haystack: ['approved', 'not-required'], strict: true);

    }//end evaluateApprovalRequired()
}//end class
