<?php

/**
 * Unit tests for AutomationRuleEvaluator.
 *
 * @spec openspec/changes/general/tasks.md#task-13.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AutomationRuleEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AutomationRuleEvaluator.
 *
 * @spec openspec/changes/general/tasks.md#task-13.1
 */
class AutomationRuleEvaluatorTest extends TestCase
{

    private AutomationRuleEvaluator $evaluator;

    private ContainerInterface $container;

    private LoggerInterface $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->evaluator = new AutomationRuleEvaluator($this->container, $this->logger);
    }//end setUp()

    /**
     * Test that GTE operator matches objects at the threshold.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testGteOperatorMatchesAtThreshold(): void
    {
        $rule   = [
            'triggerField'    => 'ageInDays',
            'triggerOperator' => 'gte',
            'triggerValue'    => '30',
        ];
        $object = ['ageInDays' => 30];

        $this->assertTrue($this->evaluator->matchesCondition($object, $rule));
    }//end testGteOperatorMatchesAtThreshold()

    /**
     * Test that GTE operator matches objects above the threshold.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testGteOperatorMatchesAboveThreshold(): void
    {
        $rule   = [
            'triggerField'    => 'ageInDays',
            'triggerOperator' => 'gte',
            'triggerValue'    => '30',
        ];
        $object = ['ageInDays' => 45];

        $this->assertTrue($this->evaluator->matchesCondition($object, $rule));
    }//end testGteOperatorMatchesAboveThreshold()

    /**
     * Test that GTE operator does NOT match objects below the threshold.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testGteOperatorDoesNotMatchBelowThreshold(): void
    {
        $rule   = [
            'triggerField'    => 'ageInDays',
            'triggerOperator' => 'gte',
            'triggerValue'    => '30',
        ];
        $object = ['ageInDays' => 29];

        $this->assertFalse($this->evaluator->matchesCondition($object, $rule));
    }//end testGteOperatorDoesNotMatchBelowThreshold()

    /**
     * Test the GT (greater than) operator.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testGtOperator(): void
    {
        $rule = [
            'triggerField'    => 'amount',
            'triggerOperator' => 'gt',
            'triggerValue'    => '100',
        ];

        $this->assertTrue($this->evaluator->matchesCondition(['amount' => 101], $rule));
        $this->assertFalse($this->evaluator->matchesCondition(['amount' => 100], $rule));
        $this->assertFalse($this->evaluator->matchesCondition(['amount' => 99], $rule));
    }//end testGtOperator()

    /**
     * Test the LT (less than) operator.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testLtOperator(): void
    {
        $rule = [
            'triggerField'    => 'amount',
            'triggerOperator' => 'lt',
            'triggerValue'    => '50',
        ];

        $this->assertTrue($this->evaluator->matchesCondition(['amount' => 49], $rule));
        $this->assertFalse($this->evaluator->matchesCondition(['amount' => 50], $rule));
    }//end testLtOperator()

    /**
     * Test the EQ (equals) operator.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testEqOperator(): void
    {
        $rule = [
            'triggerField'    => 'priority',
            'triggerOperator' => 'eq',
            'triggerValue'    => '5',
        ];

        $this->assertTrue($this->evaluator->matchesCondition(['priority' => 5], $rule));
        $this->assertFalse($this->evaluator->matchesCondition(['priority' => 4], $rule));
    }//end testEqOperator()

    /**
     * Test the LTE (less than or equal) operator.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testLteOperator(): void
    {
        $rule = [
            'triggerField'    => 'score',
            'triggerOperator' => 'lte',
            'triggerValue'    => '10',
        ];

        $this->assertTrue($this->evaluator->matchesCondition(['score' => 10], $rule));
        $this->assertTrue($this->evaluator->matchesCondition(['score' => 5], $rule));
        $this->assertFalse($this->evaluator->matchesCondition(['score' => 11], $rule));
    }//end testLteOperator()

    /**
     * Test that a missing trigger field returns false.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testMissingFieldReturnsFalse(): void
    {
        $rule   = [
            'triggerField'    => 'nonExistent',
            'triggerOperator' => 'gte',
            'triggerValue'    => '10',
        ];
        $object = ['amount' => 100];

        $this->assertFalse($this->evaluator->matchesCondition($object, $rule));
    }//end testMissingFieldReturnsFalse()

    /**
     * Test that an unknown operator returns false and logs a warning.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testUnknownOperatorReturnsFalse(): void
    {
        $this->logger->expects($this->once())->method('warning');

        $rule   = [
            'triggerField'    => 'amount',
            'triggerOperator' => 'invalid',
            'triggerValue'    => '10',
        ];
        $object = ['amount' => 100];

        $this->assertFalse($this->evaluator->matchesCondition($object, $rule));
    }//end testUnknownOperatorReturnsFalse()
}//end class
