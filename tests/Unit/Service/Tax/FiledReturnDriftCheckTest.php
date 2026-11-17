<?php

/**
 * Unit tests for FiledReturnDriftCheck.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Tax;

use OCA\Shillinq\Service\Tax\FiledReturnDriftCheck;
use OCA\Shillinq\Service\VatSuppletieDetectionService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which filed returns of a fiscal year are checked for drift, and what is counted.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(FiledReturnDriftCheck::class)]
#[UsesClass(ObjectIdentifier::class)]
final class FiledReturnDriftCheckTest extends TestCase {

	/**
	 * The detection service.
	 *
	 * @var VatSuppletieDetectionService&MockObject
	 */
	private VatSuppletieDetectionService&MockObject $suppletie;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->suppletie = $this->createMock(VatSuppletieDetectionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The check over a store holding these returns and corrections.
	 *
	 * @param array<int,array<string,mixed>> $returns     BtwAangifte rows.
	 * @param array<int,array<string,mixed>> $corrections VatCorrection rows.
	 *
	 * @return FiledReturnDriftCheck
	 */
	private function check(array $returns, array $corrections=[]): FiledReturnDriftCheck {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new FiledReturnDriftCheck(
			new InMemoryObjectServiceStub(['BtwAangifte' => $returns, 'VatCorrection' => $corrections]),
			$this->suppletie,
			$appConfig,
			$this->logger
		);
	}//end check()

	/**
	 * A return row.
	 *
	 * @param string $id     The id.
	 * @param string $status The status code.
	 * @param int    $year   The period year.
	 * @param string $admin  The administration.
	 *
	 * @return array<string,mixed>
	 */
	private function vatReturn(string $id, string $status, int $year=2026, string $admin='adm-1'): array {
		return ['id' => $id, 'statusCode' => $status, 'periodYear' => $year, 'administrationId' => $admin];
	}//end vatReturn()

	/**
	 * Only the administration's filed returns of that year are checked, and each correction written is counted.
	 *
	 * @return void
	 */
	public function testChecksTheFiledReturnsOfTheYearAndCountsTheCorrections(): void {
		$check = $this->check(
			[
				$this->vatReturn(id: 'q1', status: 'filed'),
				$this->vatReturn(id: 'q2', status: 'submitted'),
				$this->vatReturn(id: 'q3', status: 'draft'),
				$this->vatReturn(id: 'q4-2025', status: 'filed', year: 2025),
				$this->vatReturn(id: 'other-admin', status: 'filed', admin: 'adm-2'),
			]
		);

		$checked = [];
		$this->suppletie->method('detect')->willReturnCallback(
			static function (string $vatReturnId) use (&$checked): ?array {
				$checked[] = $vatReturnId;
				if ($vatReturnId === 'q2') {
					return ['id' => 'corr-q2'];
				}

				return null;
			}
		);

		$outcome = $check->checkFiscalYear(administrationId: 'adm-1', fiscalYear: 2026);

		$this->assertSame(['q1', 'q2'], $checked);
		$this->assertSame(['checked' => 2, 'corrections' => 1], $outcome);
	}//end testChecksTheFiledReturnsOfTheYearAndCountsTheCorrections()

	/**
	 * A return with a correction still in draft is not checked again; one whose correction was submitted is.
	 *
	 * @return void
	 */
	public function testAReturnWithACorrectionInDraftIsNotCheckedAgain(): void {
		$check = $this->check(
			[
				$this->vatReturn(id: 'q1', status: 'filed'),
				$this->vatReturn(id: 'q2', status: 'filed'),
			],
			[
				['id' => 'c1', 'administrationId' => 'adm-1', 'originalVatReturnId' => 'q1', 'state' => 'draft'],
				['id' => 'c2', 'administrationId' => 'adm-1', 'originalVatReturnId' => 'q2', 'state' => 'submitted'],
			]
		);

		$this->suppletie->expects($this->once())->method('detect')->with('q2')->willReturn(null);

		$this->assertSame(['checked' => 1, 'corrections' => 0], $check->checkFiscalYear(administrationId: 'adm-1', fiscalYear: 2026));
	}//end testAReturnWithACorrectionInDraftIsNotCheckedAgain()

	/**
	 * A return that cannot be checked is logged, and the next one is still checked.
	 *
	 * @return void
	 */
	public function testAFailedCheckIsLoggedAndTheOthersStillRun(): void {
		$check = $this->check(
			[
				$this->vatReturn(id: 'q1', status: 'filed'),
				$this->vatReturn(id: 'q2', status: 'filed'),
			]
		);

		$this->suppletie->method('detect')->willReturnCallback(
			static function (string $vatReturnId): ?array {
				if ($vatReturnId === 'q1') {
					throw new RuntimeException('ledger unreadable');
				}

				return ['id' => 'corr-q2'];
			}
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'FiledReturnDriftCheck: a filed return could not be checked for drift',
			['returnId' => 'q1', 'exception' => 'ledger unreadable']
		);

		$this->assertSame(['checked' => 2, 'corrections' => 1], $check->checkFiscalYear(administrationId: 'adm-1', fiscalYear: 2026));
	}//end testAFailedCheckIsLoggedAndTheOthersStillRun()

	/**
	 * Without an administration or a year nothing is read or checked.
	 *
	 * @return void
	 */
	public function testWithoutAnAdministrationOrYearNothingIsChecked(): void {
		$check = $this->check([$this->vatReturn(id: 'q1', status: 'filed', admin: '')]);

		$this->suppletie->expects($this->never())->method('detect');

		$this->assertSame(['checked' => 0, 'corrections' => 0], $check->checkFiscalYear(administrationId: '', fiscalYear: 2026));
		$this->assertSame(['checked' => 0, 'corrections' => 0], $check->checkFiscalYear(administrationId: 'adm-1', fiscalYear: 0));
	}//end testWithoutAnAdministrationOrYearNothingIsChecked()
}//end class
