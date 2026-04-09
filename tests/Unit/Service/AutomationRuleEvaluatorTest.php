<?php

/**
 * Unit tests for AutomationRuleEvaluator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/general/tasks.md#task-13.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AutomationRuleEvaluator;
use PHPUnit\Framework\MockObject\MockObject;
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

    /**
     * The evaluator under test.
     *
     * @var AutomationRuleEvaluator
     */
    private AutomationRuleEvaluator $evaluator;

    /**
     * Mock container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock object service.
     *
     * @var MockObject
     */
    private MockObject $objectService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container     = $this->createMock(ContainerInterface::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->objectService = $this->createMock(\stdClass::class);

        $this->container->method('get')
            ->willReturnCallback(function (string $id) {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $this->objectService;
                }

                return null;
            });

        $this->evaluator = new AutomationRuleEvaluator(
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that GTE operator matches objects with field value >= trigger value.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testGteOperatorMatchesObjectsAboveOrEqualToThreshold(): void
    {
        $rule = [
            'triggerSchema'   => 'Invoice',
            'triggerField'    => 'ageInDays',
            'triggerOperator' => 'gte',
            'triggerValue'    => '30',
        ];

        $objectAbove = ['id' => '1', 'ageInDays' => 45];
        $objectEqual = ['id' => '2', 'ageInDays' => 30];
        $objectBelow = ['id' => '3', 'ageInDays' => 15];

        self::assertTrue($this->evaluator->matchesCondition($objectAbove, $rule));
        self::assertTrue($this->evaluator->matchesCondition($objectEqual, $rule));
        self::assertFalse($this->evaluator->matchesCondition($objectBelow, $rule));
    }//end testGteOperatorMatchesObjectsAboveOrEqualToThreshold()

    /**
     * Test that GT operator only matches objects strictly above the threshold.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testGtOperatorMatchesObjectsStrictlyAboveThreshold(): void
    {
        $rule = [
            'triggerSchema'   => 'Invoice',
            'triggerField'    => 'ageInDays',
            'triggerOperator' => 'gt',
            'triggerValue'    => '30',
        ];

        $objectAbove = ['id' => '1', 'ageInDays' => 31];
        $objectEqual = ['id' => '2', 'ageInDays' => 30];

        self::assertTrue($this->evaluator->matchesCondition($objectAbove, $rule));
        self::assertFalse($this->evaluator->matchesCondition($objectEqual, $rule));
    }//end testGtOperatorMatchesObjectsStrictlyAboveThreshold()

    /**
     * Test that LT operator matches objects below the threshold.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testLtOperatorMatchesObjectsBelowThreshold(): void
    {
        $rule = [
            'triggerSchema'   => 'Invoice',
            'triggerField'    => 'amount',
            'triggerOperator' => 'lt',
            'triggerValue'    => '100',
        ];

        $objectBelow = ['id' => '1', 'amount' => 50];
        $objectAbove = ['id' => '2', 'amount' => 150];

        self::assertTrue($this->evaluator->matchesCondition($objectBelow, $rule));
        self::assertFalse($this->evaluator->matchesCondition($objectAbove, $rule));
    }//end testLtOperatorMatchesObjectsBelowThreshold()

    /**
     * Test that EQ operator matches objects equal to the trigger value.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testEqOperatorMatchesObjectsEqualToValue(): void
    {
        $rule = [
            'triggerSchema'   => 'Invoice',
            'triggerField'    => 'priority',
            'triggerOperator' => 'eq',
            'triggerValue'    => '5',
        ];

        $objectMatch = ['id' => '1', 'priority' => 5];
        $objectNoMatch = ['id' => '2', 'priority' => 3];

        self::assertTrue($this->evaluator->matchesCondition($objectMatch, $rule));
        self::assertFalse($this->evaluator->matchesCondition($objectNoMatch, $rule));
    }//end testEqOperatorMatchesObjectsEqualToValue()

    /**
     * Test that LTE operator matches objects at or below the threshold.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testLteOperatorMatchesObjectsAtOrBelowThreshold(): void
    {
        $rule = [
            'triggerSchema'   => 'Invoice',
            'triggerField'    => 'amount',
            'triggerOperator' => 'lte',
            'triggerValue'    => '100',
        ];

        $objectBelow = ['id' => '1', 'amount' => 50];
        $objectEqual = ['id' => '2', 'amount' => 100];
        $objectAbove = ['id' => '3', 'amount' => 150];

        self::assertTrue($this->evaluator->matchesCondition($objectBelow, $rule));
        self::assertTrue($this->evaluator->matchesCondition($objectEqual, $rule));
        self::assertFalse($this->evaluator->matchesCondition($objectAbove, $rule));
    }//end testLteOperatorMatchesObjectsAtOrBelowThreshold()

    /**
     * Test that objects with a null trigger field do not match.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.1
     */
    public function testNullFieldValueDoesNotMatch(): void
    {
        $rule = [
            'triggerSchema'   => 'Invoice',
            'triggerField'    => 'ageInDays',
            'triggerOperator' => 'gte',
            'triggerValue'    => '30',
        ];

        $object = ['id' => '1'];

        self::assertFalse($this->evaluator->matchesCondition($object, $rule));
    }//end testNullFieldValueDoesNotMatch()
}//end class
