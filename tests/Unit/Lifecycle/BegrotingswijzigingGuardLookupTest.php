<?php

/**
 * BegrotingswijzigingGuard: resolving the budget amendment and the fail-closed path.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\BegrotingswijzigingGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * When the object does not carry its council resolution the guard reads the
 * amendment from the register; a failing read denies the transition.
 *
 * @covers \OCA\Shillinq\Lifecycle\BegrotingswijzigingGuard
 */
final class BegrotingswijzigingGuardLookupTest extends TestCase {

	/**
	 * A guard whose object service answers findAll with $rows.
	 *
	 * @param array<int, mixed>|\Throwable $rows     The answer or a failure.
	 * @param string                       $register The configured register.
	 * @param LoggerInterface|null         $logger   The logger.
	 *
	 * @return BegrotingswijzigingGuard
	 */
	private function guard(array|\Throwable $rows, string $register='shillinq', ?LoggerInterface $logger=null): BegrotingswijzigingGuard {
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->expects($this->once())->method('setRegister')->with($register === '' ? 'shillinq' : $register)->willReturnSelf();
		$service->expects($this->once())->method('setSchema')->with('Begrotingswijziging')->willReturnSelf();
		$find = $service->expects($this->once())->method('findAll')->with(['filters' => ['id' => 'bw-1']]);
		if ($rows instanceof \Throwable) {
			$find->willThrowException($rows);
		} else {
			$find->willReturn($rows);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($register);

		return new BegrotingswijzigingGuard(
			appConfig: $appConfig,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			objectService: $service,
		);

	}//end guard()

	/**
	 * The stored amendment decides when the object lacks the field.
	 *
	 * @return void
	 */
	public function testTheStoredAmendmentDecides(): void {
		$this->assertTrue($this->guard(['skip', ['councilResolution' => 'rb-031']], 'finance')->canVaststellen('bw-1', ['id' => 'bw-1']));
		$this->assertFalse($this->guard([['councilResolution' => '  ']])->canVaststellen('bw-1'));
		$this->assertFalse($this->guard([], '')->canVaststellen('bw-1', ['title' => 'Voorjaarsnota']));

	}//end testTheStoredAmendmentDecides()

	/**
	 * Without an id there is nothing to read and the transition is denied.
	 *
	 * @return void
	 */
	public function testNoIdDeniesWithoutALookup(): void {
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->expects($this->never())->method('findAll');
		$guard = new BegrotingswijzigingGuard(
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $service,
		);

		$this->assertFalse($guard->canVaststellen(''));

	}//end testNoIdDeniesWithoutALookup()

	/**
	 * A failing read denies and logs the amendment and the error.
	 *
	 * @return void
	 */
	public function testAFailingReadDenies(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'BegrotingswijzigingGuard: vaststellen check failed — denying transition (fail-closed)',
			['wijzigingId' => 'bw-1', 'exception' => 'database gone']
		);

		$this->assertFalse($this->guard(new \RuntimeException('database gone'), 'shillinq', $logger)->canVaststellen('bw-1'));

	}//end testAFailingReadDenies()
}//end class
