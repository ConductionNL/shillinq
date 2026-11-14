<?php

/**
 * Unit tests for SupplierQualificationGuard edge paths.
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
 * @spec openspec/specs/procurement-governance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\SupplierQualificationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The qualification check refuses every record it cannot prove valid, and fails closed.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(SupplierQualificationGuard::class)]
final class SupplierQualificationGuardEdgesTest extends TestCase {

	/**
	 * A guard over an ObjectService that returns the given rows.
	 *
	 * @param array<int,mixed>     $rows     The rows findAll returns.
	 * @param string               $register The configured register slug.
	 * @param LoggerInterface|null $logger   The logger, or a silent mock.
	 *
	 * @return SupplierQualificationGuard
	 */
	private function guard(array $rows, string $register = 'shillinq', ?LoggerInterface $logger = null): SupplierQualificationGuard {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->once())->method('setRegister')->with('shillinq')->willReturnSelf();
		$objectService->expects($this->once())->method('setSchema')->with('SupplierQualification')->willReturnSelf();
		$objectService->expects($this->once())->method('findAll')
			->with(['filters' => ['administrationId' => 'adm-1', 'supplierId' => 'sup-1']])
			->willReturn($rows);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($register);

		return new SupplierQualificationGuard(
			appConfig: $appConfig,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			objectService: $objectService,
		);
	}//end guard()

	/**
	 * Records that must not qualify a supplier, and records that must.
	 *
	 * @return array<string,array{0:array<int,mixed>,1:bool}>
	 */
	public static function records(): array {
		$doc = ['provided' => true, 'expiresAt' => '2999-12-31'];
		return [
			'not yet qualified' => [[['statusCode' => 'pending', 'requiredDocuments' => [$doc]]], false],
			'documents not a list' => [[['statusCode' => 'qualified', 'requiredDocuments' => 'none']], false],
			'a document that is not a record' => [[['statusCode' => 'qualified', 'requiredDocuments' => [$doc, 'scan.pdf']]], false],
			'a document not provided' => [[['statusCode' => 'qualified', 'requiredDocuments' => [['provided' => 'yes']]]], false],
			'an expired document' => [[['statusCode' => 'qualified', 'requiredDocuments' => [['provided' => true, 'expiresAt' => '2001-01-01']]]], false],
			'non-record rows are skipped' => [['junk', ['statusCode' => 'qualified', 'requiredDocuments' => [$doc, ['provided' => true]]]], true],
		];
	}//end records()

	/**
	 * @param array<int,mixed> $rows     The rows the lookup returns.
	 * @param bool             $expected Whether the supplier qualifies.
	 *
	 * @return void
	 */
	#[DataProvider('records')]
	public function testQualification(array $rows, bool $expected): void {
		self::assertSame($expected, $this->guard($rows)->isQualifiedForPo('adm-1', 'sup-1'));
	}//end testQualification()

	/**
	 * An empty register slug falls back to the app's own register.
	 *
	 * @return void
	 */
	public function testEmptyRegisterSlugFallsBackToShillinq(): void {
		$rows = [['statusCode' => 'qualified', 'requiredDocuments' => []]];
		self::assertTrue($this->guard($rows, '')->isQualifiedForPo('adm-1', 'sup-1'));
	}//end testEmptyRegisterSlugFallsBackToShillinq()

	/**
	 * A failing lookup denies and logs the supplier.
	 *
	 * @return void
	 */
	public function testAFailingLookupDeniesAndLogs(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willThrowException(new \RuntimeException('register down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'SupplierQualificationGuard: qualification check failed — denying (fail-closed)',
			['supplierId' => 'sup-1', 'exception' => 'register down']
		);

		$guard = new SupplierQualificationGuard(
			appConfig: $this->createMock(IAppConfig::class),
			logger: $logger,
			objectService: $objectService,
		);

		self::assertFalse($guard->isQualifiedForPo('adm-1', 'sup-1'));
	}//end testAFailingLookupDeniesAndLogs()
}//end class
