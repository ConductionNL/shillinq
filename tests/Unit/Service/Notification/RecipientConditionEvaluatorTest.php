<?php

/**
 * Unit tests for RecipientConditionEvaluator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Notification;

use OCA\Shillinq\Service\Notification\RecipientConditionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the minimal-grammar comparison evaluator (operators, paths,
 * literals, fail-closed on malformed input).
 */
final class RecipientConditionEvaluatorTest extends TestCase
{

    /**
     * Subject under test.
     *
     * @var RecipientConditionEvaluator
     */
    private RecipientConditionEvaluator $evaluator;


    /**
     * Set up the subject.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->evaluator = new RecipientConditionEvaluator();

    }//end setUp()


    /**
     * Empty / null condition always evaluates to true (unconditional rule).
     *
     * @return void
     */
    public function testEmptyConditionIsTrue(): void
    {
        self::assertTrue($this->evaluator->evaluate(condition: '', variables: []));
        self::assertTrue($this->evaluator->evaluate(condition: null, variables: []));
        self::assertTrue($this->evaluator->evaluate(condition: 'true', variables: []));
        self::assertFalse($this->evaluator->evaluate(condition: 'false', variables: []));
    }//end testEmptyConditionIsTrue()


    /**
     * Numeric > / < comparisons against a path.
     *
     * @return void
     */
    public function testNumericGreaterThanComparison(): void
    {
        $vars = ['booking' => ['price' => 150]];

        self::assertTrue($this->evaluator->evaluate(condition: 'booking.price > 100', variables: $vars));
        self::assertFalse($this->evaluator->evaluate(condition: 'booking.price > 200', variables: $vars));
        self::assertTrue($this->evaluator->evaluate(condition: 'booking.price >= 150', variables: $vars));
        self::assertTrue($this->evaluator->evaluate(condition: 'booking.price < 200', variables: $vars));
        self::assertFalse($this->evaluator->evaluate(condition: 'booking.price <= 100', variables: $vars));
    }//end testNumericGreaterThanComparison()


    /**
     * String == / != comparisons honour quoted literals.
     *
     * @return void
     */
    public function testStringEqualityComparison(): void
    {
        $vars = ['booking' => ['status' => 'confirmed']];

        self::assertTrue($this->evaluator->evaluate(condition: "booking.status == 'confirmed'", variables: $vars));
        self::assertFalse($this->evaluator->evaluate(condition: "booking.status == 'cancelled'", variables: $vars));
        self::assertTrue($this->evaluator->evaluate(condition: "booking.status != 'pending'", variables: $vars));
    }//end testStringEqualityComparison()


    /**
     * Numeric strings compare numerically (JSON payloads often store
     * numbers as strings).
     *
     * @return void
     */
    public function testNumericStringsCompareNumerically(): void
    {
        $vars = ['booking' => ['price' => '150']];

        self::assertTrue($this->evaluator->evaluate(condition: 'booking.price > 100', variables: $vars));
    }//end testNumericStringsCompareNumerically()


    /**
     * Missing path resolves to null and fails the comparison cleanly.
     *
     * @return void
     */
    public function testMissingPathFailsClosed(): void
    {
        // null > 100 is false in PHP — and we want a clean false result,
        // never a fatal.
        self::assertFalse($this->evaluator->evaluate(condition: 'booking.price > 100', variables: []));
    }//end testMissingPathFailsClosed()


    /**
     * Malformed expressions fail-closed (return false) so a broken
     * trigger cannot silently spam everyone.
     *
     * @return void
     */
    public function testMalformedExpressionFailsClosed(): void
    {
        self::assertFalse($this->evaluator->evaluate(condition: 'booking.price ?? 100', variables: ['booking' => ['price' => 150]]));
        self::assertFalse($this->evaluator->evaluate(condition: '> 100', variables: ['booking' => ['price' => 150]]));
    }//end testMalformedExpressionFailsClosed()


}//end class
