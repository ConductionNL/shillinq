<?php

/**
 * Gap-filling unit tests for DBAComplianceGuard.
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
 * @spec openspec/changes/dba-compliance-marker/specs/dba-compliance-marker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\DBAComplianceGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the id-only lookups and empty-id refusals of the three transition
 * guards, their fail-closed paths, beeindiging, malformed dossier stukken and
 * the administration VBAR-grens override of DBAComplianceGuard.
 *
 * @covers \OCA\Shillinq\Lifecycle\DBAComplianceGuard
 * @uses   \OCA\Shillinq\Lifecycle\GuardObjectLookup
 * @uses   \OCA\Shillinq\Lifecycle\GuardSubject
 */
final class DBAComplianceGuardGapsTest extends TestCase {

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
	 * Every findAll() call as [schema, params].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $calls = [];

	/**
	 * Registers passed to setRegister().
	 *
	 * @var array<int,string>
	 */
	private array $registers = [];

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
	 * Build the guard over a configured register slug.
	 *
	 * @param string $register  The register slug the app config answers.
	 * @param string $vbarGrens The dba_vbar_grens override the app config answers.
	 *
	 * @return DBAComplianceGuard The guard.
	 */
	private function guard(string $register = 'books', string $vbarGrens = ''): DBAComplianceGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => $register,
				'dba_vbar_grens' => $vbarGrens,
				default => $default,
			}
		);

		return new DBAComplianceGuard(appConfig: $config, logger: $this->logger, objectService: $this->objects);
	}//end guard()

	/**
	 * Seed the object service with rows per schema and record every read.
	 *
	 * @param array<string,array<int,mixed>|\Throwable> $bySchema Rows (or a throwable) per schema.
	 *
	 * @return void
	 */
	private function seed(array $bySchema): void {
		$schema = '';
		$this->objects->method('setRegister')->willReturnCallback(
			function (string|int $register): ObjectServiceInterface {
				$this->registers[] = (string)$register;
				return $this->objects;
			}
		);
		$this->objects->method('setSchema')->willReturnCallback(
			function (string|int $slug) use (&$schema): ObjectServiceInterface {
				$schema = (string)$slug;
				return $this->objects;
			}
		);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = []) use (&$schema, $bySchema): array {
				$this->calls[] = [$schema, $config];
				$rows = ($bySchema[$schema] ?? []);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}
		);
	}//end seed()

	/**
	 * Activation without object or id is refused without a store read.
	 *
	 * @return void
	 */
	public function testActivateWithoutObjectOrIdIsRefused(): void {
		$this->objects->expects($this->never())->method('findAll');

		self::assertFalse($this->guard()->canActivateOpdracht(assignmentId: ''));
	}//end testActivateWithoutObjectOrIdIsRefused()

	/**
	 * An id-only activation reads the opdracht and permits a VOLTOOID intake.
	 *
	 * @return void
	 */
	public function testActivateByIdReadsOpdracht(): void {
		$this->seed(['DBAOpdracht' => [['id' => 'op-1', 'intakeStatus' => 'VOLTOOID']]]);

		self::assertTrue($this->guard()->canActivateOpdracht(assignmentId: 'op-1'));
		self::assertSame([['DBAOpdracht', ['filters' => ['id' => 'op-1']]]], $this->calls);
		self::assertSame(['books'], $this->registers);
	}//end testActivateByIdReadsOpdracht()

	/**
	 * A failing lookup during activation fails closed and logs the cause.
	 *
	 * @return void
	 */
	public function testActivateLookupFailureFailsClosedAndLogs(): void {
		$this->seed(['DBAOpdracht' => new RuntimeException('store down')]);

		$this->logger->expects($this->once())->method('error')->with(
			'DBAComplianceGuard: activate-opdracht check failed — denying transition (fail-closed)',
			['assignmentId' => 'op-2', 'exception' => 'store down']
		);

		self::assertFalse($this->guard()->canActivateOpdracht(assignmentId: 'op-2'));
	}//end testActivateLookupFailureFailsClosedAndLogs()

	/**
	 * Beeindiging needs a non-blank actualEndDate.
	 *
	 * @return void
	 */
	public function testBeeindigRequiresActualEndDate(): void {
		$guard = $this->guard();

		self::assertTrue($guard->canBeeindigOpdracht(assignmentId: 'op-3', object: ['actualEndDate' => '2026-06-30']));
		self::assertFalse($guard->canBeeindigOpdracht(assignmentId: 'op-3', object: ['actualEndDate' => '   ']));
		self::assertFalse($guard->canBeeindigOpdracht(assignmentId: 'op-3', object: ['state' => 'actief']));
	}//end testBeeindigRequiresActualEndDate()

	/**
	 * An id-only beeindiging reads the opdracht from the store (empty register
	 * slug falls back to `shillinq`).
	 *
	 * @return void
	 */
	public function testBeeindigByIdReadsOpdracht(): void {
		$this->seed(['DBAOpdracht' => [['id' => 'op-4', 'actualEndDate' => '2026-01-31']]]);

		self::assertTrue($this->guard(register: '')->canBeeindigOpdracht(assignmentId: 'op-4'));
		self::assertSame([['DBAOpdracht', ['filters' => ['id' => 'op-4']]]], $this->calls);
		self::assertSame(['shillinq'], $this->registers);
	}//end testBeeindigByIdReadsOpdracht()

	/**
	 * Beeindiging without object or id is refused; a lookup that finds no row
	 * is refused too.
	 *
	 * @return void
	 */
	public function testBeeindigWithoutResolvableOpdrachtIsRefused(): void {
		$this->seed(['DBAOpdracht' => []]);

		self::assertFalse($this->guard()->canBeeindigOpdracht(assignmentId: ''));
		self::assertFalse($this->guard()->canBeeindigOpdracht(assignmentId: 'op-missing'));
		self::assertSame([['DBAOpdracht', ['filters' => ['id' => 'op-missing']]]], $this->calls);
	}//end testBeeindigWithoutResolvableOpdrachtIsRefused()

	/**
	 * A failing lookup during beeindiging fails closed and logs the cause.
	 *
	 * @return void
	 */
	public function testBeeindigLookupFailureFailsClosedAndLogs(): void {
		$this->seed(['DBAOpdracht' => new RuntimeException('store down')]);

		$this->logger->expects($this->once())->method('error')->with(
			'DBAComplianceGuard: beeindig-opdracht check failed — denying transition (fail-closed)',
			['assignmentId' => 'op-5', 'exception' => 'store down']
		);

		self::assertFalse($this->guard()->canBeeindigOpdracht(assignmentId: 'op-5'));
	}//end testBeeindigLookupFailureFailsClosedAndLogs()

	/**
	 * Intake completion without object or id is refused without a store read.
	 *
	 * @return void
	 */
	public function testCompleteIntakeWithoutObjectOrIdIsRefused(): void {
		$this->objects->expects($this->never())->method('findAll');

		self::assertFalse($this->guard()->canCompleteIntake(intakeId: ''));
	}//end testCompleteIntakeWithoutObjectOrIdIsRefused()

	/**
	 * A failing lookup during intake completion fails closed and logs the cause.
	 *
	 * @return void
	 */
	public function testCompleteIntakeLookupFailureFailsClosedAndLogs(): void {
		$this->seed(['DBAIntake' => new RuntimeException('store down')]);

		$this->logger->expects($this->once())->method('error')->with(
			'DBAComplianceGuard: complete-intake check failed — denying transition (fail-closed)',
			['intakeId' => 'in-1', 'exception' => 'store down']
		);

		self::assertFalse($this->guard()->canCompleteIntake(intakeId: 'in-1'));
	}//end testCompleteIntakeLookupFailureFailsClosedAndLogs()

	/**
	 * Non-array stukken are ignored when computing dossier completeness.
	 *
	 * @return void
	 */
	public function testCompletenessIgnoresNonArrayStukken(): void {
		$result = $this->guard()->computeCompleteness(
			documents: ['SIGNED_AGREEMENT', null, ['type' => 'INVOICE_FIRST'], 3]
		);

		self::assertSame(1 / 3, $result['score']);
		self::assertSame(['SIGNED_AGREEMENT', 'TIMESHEET_QUARTER'], $result['missing']);
	}//end testCompletenessIgnoresNonArrayStukken()

	/**
	 * A positive administration override replaces the baked VBAR grens.
	 *
	 * @return void
	 */
	public function testPositiveVbarOverrideIsUsed(): void {
		$result = $this->guard(vbarGrens: '40')->effectiveHourlyRateBreach(amount: 350.0, hours: 10.0);

		self::assertSame(['breach' => true, 'rate' => 35.0, 'grens' => 40.0], $result);
	}//end testPositiveVbarOverrideIsUsed()

	/**
	 * A non-positive or non-numeric override falls back to VBAR_GRENS_EUR.
	 *
	 * @return void
	 */
	public function testInvalidVbarOverrideFallsBackToDefault(): void {
		$zero = $this->guard(vbarGrens: '0')->effectiveHourlyRateBreach(amount: 350.0, hours: 10.0);
		$text = $this->guard(vbarGrens: 'abc')->effectiveHourlyRateBreach(amount: 350.0, hours: 10.0);

		self::assertSame(['breach' => false, 'rate' => 35.0, 'grens' => 33.0], $zero);
		self::assertSame(['breach' => false, 'rate' => 35.0, 'grens' => 33.0], $text);
	}//end testInvalidVbarOverrideFallsBackToDefault()

	/**
	 * Without a reference date, expiry is judged against today.
	 *
	 * @return void
	 */
	public function testModelExpiryDefaultsToToday(): void {
		$guard = $this->guard();

		self::assertTrue($guard->isModelExpired(model: ['validTo' => '2000-01-01']));
		self::assertFalse($guard->isModelExpired(model: ['validTo' => '2999-12-31']));
	}//end testModelExpiryDefaultsToToday()
}//end class
