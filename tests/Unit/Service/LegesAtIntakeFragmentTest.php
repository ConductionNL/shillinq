<?php

/**
 * Unit tests for the leges-at-intake register fragment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the FeeSchedule schema declares the tuple, the validity window and
 * the intake behaviour the resolver reads (REQ-SOPR-006).
 */
final class LegesAtIntakeFragmentTest extends TestCase {
	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/leges-at-intake.json';

	/**
	 * Decode the fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());

		return $data['components']['schemas']['FeeSchedule'];
	}//end schema()

	/**
	 * The fragment ships and declares FeeSchedule.
	 *
	 * @return void
	 */
	public function testTheFragmentDeclaresFeeSchedule(): void {
		self::assertFileExists($this->fragmentPath);
		self::assertSame('FeeSchedule', $this->schema()['slug']);
	}//end testTheFragmentDeclaresFeeSchedule()

	/**
	 * Every part the resolver reads is declared. OpenRegister DROPS an undeclared
	 * field in silence, so a schedule stored against a missing property would
	 * resolve to nothing with no error anywhere.
	 *
	 * @return void
	 */
	public function testEveryPartTheResolverReadsIsDeclared(): void {
		$properties = $this->schema()['properties'];

		foreach (
			[
				'targetApp',
				'register',
				'schema',
				'typeProperty',
				'typeValue',
				'intakeChannel',
				'productRef',
				'amount',
				'currency',
				'revenueAccount',
				'payAtIntake',
				'legalBasis',
				'validFrom',
				'validTo',
			] as $property
		) {
			self::assertArrayHasKey($property, $properties, $property . ' is missing from FeeSchedule');
		}
	}//end testEveryPartTheResolverReadsIsDeclared()

	/**
	 * `payAtIntake` names exactly the three behaviours the step implements. A
	 * fourth value in the schema would be a behaviour nothing acts on.
	 *
	 * @return void
	 */
	public function testPayAtIntakeNamesTheThreeBehaviours(): void {
		self::assertSame(['required', 'optional', 'later'], $this->schema()['properties']['payAtIntake']['enum']);
	}//end testPayAtIntakeNamesTheThreeBehaviours()

	/**
	 * The tuple and the start of the window are required: a schedule missing one
	 * of them can never be resolved for a day, so it would sit stored and inert.
	 *
	 * @return void
	 */
	public function testTheTupleAndTheWindowStartAreRequired(): void {
		$required = $this->schema()['required'];

		foreach (['targetApp', 'register', 'schema', 'typeProperty', 'typeValue', 'payAtIntake', 'validFrom'] as $field) {
			self::assertContains($field, $required, $field . ' must be required');
		}
	}//end testTheTupleAndTheWindowStartAreRequired()

	/**
	 * A fee is a council decision, so the schedule carries an audit trail.
	 *
	 * @return void
	 */
	public function testTheScheduleIsAudited(): void {
		self::assertTrue($this->schema()['x-openregister-audit-trail']['enabled']);
	}//end testTheScheduleIsAudited()
}//end class
