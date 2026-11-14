<?php

/**
 * Unit tests for ObjectionPeriodGuard's record lookups and period boundary.
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

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\ObjectionPeriodGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The objection is checked against the assessment of the tax return (found
 * by the passed object's own id when it has one), the appeal against the
 * ruling date (read when the passed objection has none). The six-week period
 * includes its last day. Dates are relative to today, so no clock is pinned.
 *
 * @covers \OCA\Shillinq\Lifecycle\ObjectionPeriodGuard
 */
final class ObjectionPeriodGuardLookupTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The guard, over a configured register.
	 *
	 * @param string $register The configured register slug.
	 *
	 * @return ObjectionPeriodGuard The guard.
	 */
	private function guard(string $register = 'books'): ObjectionPeriodGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($register);

		return new ObjectionPeriodGuard($config, $this->logger, $this->objects);
	}//end guard()

	/**
	 * Expect one read of this schema in this register, answering these records.
	 *
	 * @param string               $register The register expected.
	 * @param string               $schema   The schema expected.
	 * @param array<string, mixed> $query    The query expected.
	 * @param array<int, mixed>    $records  The records to answer.
	 *
	 * @return void
	 */
	private function expectRead(string $register, string $schema, array $query, array $records): void {
		$this->objects->expects($this->once())->method('setRegister')->with($register)->willReturnSelf();
		$this->objects->expects($this->once())->method('setSchema')->with($schema)->willReturnSelf();
		$this->objects->expects($this->once())->method('findAll')->with($query)->willReturn($records);
	}//end expectRead()

	/**
	 * A day, relative to today.
	 *
	 * @param string $modifier The relative modifier.
	 *
	 * @return string The date as Y-m-d.
	 */
	private function day(string $modifier): string {
		return (new DateTimeImmutable('today'))->modify($modifier)->format('Y-m-d');
	}//end day()

	/**
	 * The objection reads the assessment by the passed object's id, in the configured register.
	 *
	 * @return void
	 */
	public function testTheObjectionReadsTheAssessmentOfThePassedReturn(): void {
		$this->expectRead('books', 'DefinitieveAanslag', ['filters' => ['taxReturn' => 'ret-2']], ['x', ['issueDate' => $this->day('-6 weeks') . 'T09:00:00Z']]);

		$this->assertTrue($this->guard()->canFileObjection('ret-1', ['id' => 'ret-2']), 'the last day of the period still counts');
	}//end testTheObjectionReadsTheAssessmentOfThePassedReturn()

	/**
	 * A passed object without id falls back to the transition id, in the default register.
	 *
	 * @return void
	 */
	public function testAPassedObjectWithoutIdFallsBackToTheTransitionId(): void {
		$this->expectRead('shillinq', 'DefinitieveAanslag', ['filters' => ['taxReturn' => 'ret-1']], [['issueDate' => $this->day('-6 weeks -1 day')]]);

		$this->assertFalse($this->guard('')->canFileObjection('ret-1', ['id' => '']), 'a day after the period is too late');
	}//end testAPassedObjectWithoutIdFallsBackToTheTransitionId()

	/**
	 * Without a return id, or an assessment, or an issue date, the objection is refused.
	 *
	 * @return void
	 */
	public function testAnObjectionWithoutAssessmentOrDateIsRefused(): void {
		$this->expectRead('books', 'DefinitieveAanslag', ['filters' => ['taxReturn' => 'ret-1']], [[]]);
		$guard = $this->guard();

		$this->assertFalse($guard->canFileObjection('ret-1'));
		$this->assertFalse($guard->canFileObjection(''));
	}//end testAnObjectionWithoutAssessmentOrDateIsRefused()

	/**
	 * An unparseable issue date refuses without logging.
	 *
	 * @return void
	 */
	public function testAnUnparseableIssueDateIsRefused(): void {
		$this->expectRead('books', 'DefinitieveAanslag', ['filters' => ['taxReturn' => 'ret-1']], [['issueDate' => 'gisteren']]);
		$this->logger->expects($this->never())->method('error');

		$this->assertFalse($this->guard()->canFileObjection('ret-1'));
	}//end testAnUnparseableIssueDateIsRefused()

	/**
	 * A failing read refuses the objection and logs it.
	 *
	 * @return void
	 */
	public function testAFailingReadRefusesTheObjectionAndIsLogged(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('error')->with(
			'ObjectionPeriodGuard: canFileObjection check failed — denying transition (fail-closed)',
			['taxReturnId' => 'ret-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canFileObjection('ret-1'));
	}//end testAFailingReadRefusesTheObjectionAndIsLogged()

	/**
	 * An appeal without a ruling date on the passed objection reads the objection.
	 *
	 * @return void
	 */
	public function testAnAppealReadsTheObjectionWhenThePassedOneHasNoRulingDate(): void {
		$this->expectRead('books', 'BezwaarBeroep', ['filters' => ['id' => 'obj-1']], [5, ['rulingDate' => $this->day('-1 week')]]);

		$this->assertTrue($this->guard()->canFileAppeal('obj-1', ['status' => 'decided']));
	}//end testAnAppealReadsTheObjectionWhenThePassedOneHasNoRulingDate()

	/**
	 * A passed blank ruling date, an unknown objection or a blank id refuses the appeal.
	 *
	 * @return void
	 */
	public function testAnAppealWithoutRulingDateIsRefused(): void {
		$this->expectRead('books', 'BezwaarBeroep', ['filters' => ['id' => 'obj-1']], ['broken']);
		$guard = $this->guard();

		$this->assertFalse($guard->canFileAppeal('obj-1'));
		$this->assertFalse($guard->canFileAppeal('', ['rulingDate' => '']));
		$this->assertFalse($guard->canFileAppeal(''));
	}//end testAnAppealWithoutRulingDateIsRefused()

	/**
	 * A failing read refuses the appeal and logs it.
	 *
	 * @return void
	 */
	public function testAFailingReadRefusesTheAppealAndIsLogged(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('error')->with(
			'ObjectionPeriodGuard: canFileAppeal check failed — denying transition (fail-closed)',
			['objectionId' => 'obj-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canFileAppeal('obj-1'));
	}//end testAFailingReadRefusesTheAppealAndIsLogged()
}//end class
