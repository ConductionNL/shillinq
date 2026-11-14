<?php

/**
 * Unit tests for DestructionScheduleGuard.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\DestructionScheduleGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Records on the destruction schedule: what may still be changed or
 * deleted, which state arrows exist and who may take them, the seven-year
 * retention floor and the audit event a transition writes.
 *
 * @covers \OCA\Shillinq\Lifecycle\DestructionScheduleGuard
 */
final class DestructionScheduleGuardTest extends TestCase {

	private const OFFICER = ['actorRoles' => ['bookkeeper', 'compliance-officer'], 'now' => '2026-06-01'];

	/**
	 * The guard with a silent logger.
	 *
	 * @return DestructionScheduleGuard
	 */
	private function guard(): DestructionScheduleGuard {
		return new DestructionScheduleGuard(logger: $this->createMock(LoggerInterface::class));

	}//end guard()

	/**
	 * Only destroyed records are frozen; only active records may be deleted.
	 *
	 * @return void
	 */
	public function testModifyAndDeleteFollowTheState(): void {
		$guard = $this->guard();
		$this->assertTrue($guard->canModify([]));
		$this->assertTrue($guard->canModify(['status' => 'marked-for-destruction']));
		$this->assertFalse($guard->canModify(['status' => 'destruction-completed']));
		$this->assertFalse($guard->canModify(['destructionState' => 'destruction-completed', 'status' => 'active']));

		$this->assertTrue($guard->canDelete([]));
		$this->assertTrue($guard->canDelete(['status' => 'active']));
		$this->assertFalse($guard->canDelete(['status' => 'marked-for-destruction']));
		$this->assertFalse($guard->canDelete(['destructionState' => 'marked-for-destruction', 'status' => 'active']));

	}//end testModifyAndDeleteFollowTheState()

	/**
	 * Marking needs a compliance officer and a record past the retention floor.
	 *
	 * @return void
	 */
	public function testMarkingNeedsAnOfficerAndAnOldEnoughRecord(): void {
		$guard = $this->guard();
		$old   = ['id' => 'r-1', 'createdAt' => '2019-06-01'];

		$this->assertTrue($guard->canTransition('active', 'marked-for-destruction', $old, self::OFFICER));
		$this->assertTrue($guard->canTransition('active', 'marked-for-destruction', ['created' => '2019-01-01'], self::OFFICER));
		$this->assertTrue($guard->canTransition('active', 'marked-for-destruction', ['recordedAt' => '2010-01-01'], self::OFFICER));
		$this->assertFalse($guard->canTransition('active', 'marked-for-destruction', ['createdAt' => '2019-06-02'], self::OFFICER));
		$this->assertFalse($guard->canTransition('active', 'marked-for-destruction', $old, ['actorRoles' => ['bookkeeper'], 'now' => '2026-06-01']));
		$this->assertFalse($guard->canTransition('active', 'marked-for-destruction', $old, ['actorUid' => 'system', 'now' => '2026-06-01']));
		$this->assertFalse($guard->canTransition('active', 'marked-for-destruction', ['createdAt' => 'long ago'], self::OFFICER));
		$this->assertFalse($guard->canTransition('active', 'marked-for-destruction', $old, ['now' => 'someday'] + self::OFFICER));

	}//end testMarkingNeedsAnOfficerAndAnOldEnoughRecord()

	/**
	 * A record without any creation date cannot be aged, so is not marked.
	 *
	 * @return void
	 */
	public function testAnUndatedRecordIsNotMarked(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'DestructionScheduleGuard: cannot determine record age — refusing marked-for-destruction transition',
			['recordId' => 'r-9']
		);
		$guard = new DestructionScheduleGuard(logger: $logger);

		$this->assertFalse($guard->canTransition('active', 'marked-for-destruction', ['id' => 'r-9'], self::OFFICER));

	}//end testAnUndatedRecordIsNotMarked()

	/**
	 * Unmarking is free; completing needs an officer or the destruction runner.
	 *
	 * @return void
	 */
	public function testUnmarkingAndCompleting(): void {
		$guard = $this->guard();
		$this->assertTrue($guard->canTransition('marked-for-destruction', 'active', [], []));
		$this->assertTrue($guard->canTransition('marked-for-destruction', 'destruction-completed', [], self::OFFICER));
		$this->assertTrue($guard->canTransition('marked-for-destruction', 'destruction-completed', [], ['actorUid' => 'system']));
		$this->assertTrue($guard->canTransition('marked-for-destruction', 'destruction-completed', [], ['actorUid' => 'shillinq-destruction-runner']));
		$this->assertFalse($guard->canTransition('marked-for-destruction', 'destruction-completed', [], ['actorUid' => 'admin']));
		$this->assertFalse($guard->canTransition('marked-for-destruction', 'destruction-completed', [], []));

	}//end testUnmarkingAndCompleting()

	/**
	 * No other arrow exists, whoever asks.
	 *
	 * @return void
	 */
	public function testEveryOtherArrowIsRefused(): void {
		$guard = $this->guard();
		$old   = ['createdAt' => '2000-01-01'];
		$arrows = [
			['active', 'destruction-completed'],
			['active', 'active'],
			['destruction-completed', 'active'],
			['destruction-completed', 'marked-for-destruction'],
			['marked-for-destruction', 'marked-for-destruction'],
			['archived', 'active'],
		];
		foreach ($arrows as [$from, $to]) {
			$this->assertFalse($guard->canTransition($from, $to, $old, ['actorUid' => 'system'] + self::OFFICER), $from . '>' . $to);
		}

	}//end testEveryOtherArrowIsRefused()

	/**
	 * The audit event names the arrow, actor, object and legal basis.
	 *
	 * @return void
	 */
	public function testTheTransitionEvent(): void {
		$guard = $this->guard();

		$this->assertSame(
			[
				'action'            => 'lifecycle:active→marked-for-destruction',
				'actor'             => 'anna',
				'objectType'        => 'ARInvoice',
				'objectId'          => 'inv-1',
				'selectionListCode' => '1.1.1',
				'legalBasis'        => 'Selectielijst',
				'timestamp'         => '2026-06-01T10:00:00+00:00',
				'requirementId'     => 'REQ-RAP-008',
			],
			$guard->buildTransitionEvent(
				['_objectType' => 'ARInvoice', 'schema' => 'x', 'id' => 'inv-1', 'uuid' => 'u-1'],
				'active',
				'marked-for-destruction',
				['actorUid' => 'anna', 'selectionListCode' => '1.1.1', 'legalBasis' => 'Selectielijst', 'now' => '2026-06-01T10:00:00+00:00']
			)
		);

		$event = $guard->buildTransitionEvent(['schema' => 'APInvoice', 'uuid' => 'u-2'], 'marked-for-destruction', 'destruction-completed');
		$this->assertSame('system', $event['actor']);
		$this->assertSame('APInvoice', $event['objectType']);
		$this->assertSame('u-2', $event['objectId']);
		$this->assertSame('5.1.2', $event['selectionListCode']);
		$this->assertSame('Archiefwet Article 7', $event['legalBasis']);
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $event['timestamp']));

		$this->assertSame(['', ''], array_values(array_intersect_key($guard->buildTransitionEvent([], 'a', 'b'), ['objectType' => 1, 'objectId' => 1])));

	}//end testTheTransitionEvent()
}//end class
