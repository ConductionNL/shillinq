<?php

/**
 * Gap-filling unit tests for AnnualReportGuard.
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
 * @spec openspec/changes/bookkeeping-titel-9-jaarrekening/specs/bookkeeping-titel-9-jaarrekening/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\AnnualReportGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the empty-id refusals, the id-only AnnualReport lookup, a balans
 * without totals or rubrieken, the empty register-slug fallback and the
 * vaststellen fail-closed path of AnnualReportGuard.
 *
 * @covers \OCA\Shillinq\Lifecycle\AnnualReportGuard
 */
final class AnnualReportGuardGapsTest extends TestCase {

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
	 * @param string $register The register slug the app config answers.
	 *
	 * @return AnnualReportGuard The guard.
	 */
	private function guard(string $register = 'books'): AnnualReportGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'register' ? $register : $default
		);

		return new AnnualReportGuard(appConfig: $config, logger: $this->logger, objectService: $this->objects);
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
	 * No object and no id: opmaken is refused without touching the store.
	 *
	 * @return void
	 */
	public function testOpmakenWithoutIdIsRefusedWithoutLookup(): void {
		$this->objects->expects($this->never())->method('findAll');

		self::assertFalse($this->guard()->canOpmaken(annualReportId: '', object: ['id' => '']));
	}//end testOpmakenWithoutIdIsRefusedWithoutLookup()

	/**
	 * A balans with neither totals nor a rubrieken list cannot be totalled:
	 * opmaken is refused. The object's own id wins over the transition id.
	 *
	 * @return void
	 */
	public function testOpmakenWithUntotallableBalansIsRefused(): void {
		$this->seed(['BalanceSheet' => [['reportId' => 'ar-1', 'totalAssets' => 100.0, 'rubrieken' => 'none']]]);

		self::assertFalse($this->guard()->canOpmaken(annualReportId: 'other', object: ['id' => 'ar-1']));
		self::assertSame([['BalanceSheet', ['filters' => ['reportId' => 'ar-1']]]], $this->calls);
	}//end testOpmakenWithUntotallableBalansIsRefused()

	/**
	 * Vaststellen without object or id is refused without a store read.
	 *
	 * @return void
	 */
	public function testVaststellenWithoutObjectOrIdIsRefused(): void {
		$this->objects->expects($this->never())->method('findAll');

		self::assertFalse($this->guard()->canVaststellen(annualReportId: ''));
	}//end testVaststellenWithoutObjectOrIdIsRefused()

	/**
	 * An object lacking auditorsStatementRequired triggers a lookup; the first
	 * array row (non-arrays skipped) says no verklaring is required: permitted.
	 * An empty register slug falls back to `shillinq`.
	 *
	 * @return void
	 */
	public function testVaststellenLooksUpReportWhenFlagMissing(): void {
		$this->seed(['AnnualReport' => ['junk', ['id' => 'ar-2', 'auditorsStatementRequired' => false]]]);

		self::assertTrue($this->guard(register: '')->canVaststellen(annualReportId: 'ar-2', object: ['id' => 'ar-2']));
		self::assertSame([['AnnualReport', ['filters' => ['id' => 'ar-2']]]], $this->calls);
		self::assertSame(['shillinq'], $this->registers);
	}//end testVaststellenLooksUpReportWhenFlagMissing()

	/**
	 * A looked-up report requiring a verklaring with an accepted status passes;
	 * a rejected status does not.
	 *
	 * @return void
	 */
	public function testVaststellenLookedUpRequiredStatusDecides(): void {
		$this->seed(
			[
				'AnnualReport' => [
					['id' => 'ar-3', 'auditorsStatementRequired' => true, 'auditorsStatementStatus' => 'met-beperking'],
				],
			]
		);

		self::assertTrue($this->guard()->canVaststellen(annualReportId: 'ar-3'));
		self::assertFalse(
			$this->guard()->canVaststellen(
				annualReportId: 'ar-3',
				object: ['auditorsStatementRequired' => true, 'auditorsStatementStatus' => 'afkeurend']
			)
		);
		self::assertCount(1, $this->calls);
	}//end testVaststellenLookedUpRequiredStatusDecides()

	/**
	 * A lookup answering only non-array rows resolves to nothing: refused.
	 *
	 * @return void
	 */
	public function testVaststellenLookupWithoutArrayRowsIsRefused(): void {
		$this->seed(['AnnualReport' => ['junk', 7]]);

		self::assertFalse($this->guard()->canVaststellen(annualReportId: 'ar-4'));
		self::assertSame([['AnnualReport', ['filters' => ['id' => 'ar-4']]]], $this->calls);
	}//end testVaststellenLookupWithoutArrayRowsIsRefused()

	/**
	 * A failing lookup during vaststellen fails closed and logs the cause.
	 *
	 * @return void
	 */
	public function testVaststellenLookupFailureFailsClosedAndLogs(): void {
		$this->seed(['AnnualReport' => new RuntimeException('store down')]);

		$this->logger->expects($this->once())->method('error')->with(
			'AnnualReportGuard: vaststelling verklaring check failed — denying vaststellen transition (fail-closed)',
			['annualReportId' => 'ar-5', 'exception' => 'store down']
		);

		self::assertFalse($this->guard()->canVaststellen(annualReportId: 'ar-5'));
	}//end testVaststellenLookupFailureFailsClosedAndLogs()
}//end class
