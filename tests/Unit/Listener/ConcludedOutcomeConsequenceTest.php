<?php

/**
 * ConcludedOutcomeConsequenceTest — stamp and gate on a completing outcome only.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Shillinq\Listener\ConcludedOutcomeConsequence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers OCA\Shillinq\Listener\ConcludedOutcomeConsequence.
 */
#[CoversClass(ConcludedOutcomeConsequence::class)]
final class ConcludedOutcomeConsequenceTest extends TestCase {

	/**
	 * The consequence under test.
	 *
	 * @return ConcludedOutcomeConsequence The consequence.
	 */
	private function consequence(): ConcludedOutcomeConsequence {
		return new ConcludedOutcomeConsequence(completing: 'signed', stampField: 'signedAt', gateField: 'submissionGateOpen');

	}//end consequence()

	/**
	 * Only the completing outcome applies.
	 *
	 * @return void
	 */
	public function testOnlyTheCompletingOutcomeApplies(): void {
		self::assertTrue($this->consequence()->appliesTo(outcome: 'signed'));
		self::assertFalse($this->consequence()->appliesTo(outcome: 'declined'));
		self::assertFalse($this->consequence()->appliesTo(outcome: ''));

	}//end testOnlyTheCompletingOutcomeApplies()

	/**
	 * A completing outcome stamps a UTC time, opens the gate and keeps the other fields.
	 *
	 * @return void
	 */
	public function testACompletingOutcomeStampsAndOpensTheGate(): void {
		$object = $this->consequence()->apply(object: ['id' => 'r-1', 'signingStatus' => 'signed'], outcome: 'signed');

		self::assertSame('r-1', $object['id']);
		self::assertSame('signed', $object['signingStatus']);
		self::assertTrue($object['submissionGateOpen']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $object['signedAt']);

	}//end testACompletingOutcomeStampsAndOpensTheGate()

	/**
	 * Any other outcome leaves the object untouched.
	 *
	 * @return void
	 */
	public function testAnotherOutcomeLeavesTheObjectAlone(): void {
		$object = ['id' => 'r-1'];

		self::assertSame($object, $this->consequence()->apply(object: $object, outcome: 'expired'));

	}//end testAnotherOutcomeLeavesTheObjectAlone()

	/**
	 * The delta carries only the consequence fields that are present.
	 *
	 * @return void
	 */
	public function testTheDeltaCarriesThePresentConsequenceFields(): void {
		$object = ['id' => 'r-1', 'signedAt' => '2026-01-02T03:04:05Z', 'submissionGateOpen' => true];

		self::assertSame(
			['signedAt' => '2026-01-02T03:04:05Z', 'submissionGateOpen' => true],
			$this->consequence()->delta(object: $object, outcome: 'signed')
		);
		self::assertSame(['submissionGateOpen' => false], $this->consequence()->delta(object: ['submissionGateOpen' => false], outcome: 'signed'));

	}//end testTheDeltaCarriesThePresentConsequenceFields()

	/**
	 * Any other outcome has an empty delta, even when the fields are present.
	 *
	 * @return void
	 */
	public function testAnotherOutcomeHasAnEmptyDelta(): void {
		self::assertSame([], $this->consequence()->delta(object: ['signedAt' => 'x', 'submissionGateOpen' => true], outcome: 'declined'));

	}//end testAnotherOutcomeHasAnEmptyDelta()
}//end class
