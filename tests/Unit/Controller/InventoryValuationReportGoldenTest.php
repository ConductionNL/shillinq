<?php

/**
 * Golden tests for the input checks and access check of
 * InventoryValuationReportController::report().
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\InventoryValuationReportController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InventoryValuationReportService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every refusal and the allowed path of the valuation report.
 */
final class InventoryValuationReportGoldenTest extends TestCase {

	/**
	 * Report service mock.
	 *
	 * @var InventoryValuationReportService&MockObject
	 */
	private InventoryValuationReportService&MockObject $report;

	/**
	 * Context mock.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->report = $this->createMock(InventoryValuationReportService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Call report() with parameters.
	 *
	 * @param array<string,mixed> $params The parameters.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function report(array $params): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$controller = new InventoryValuationReportController($request, $this->report, $this->context, $this->logger);
		$response = $controller->report();
		return [$response->getStatus(), $response->getData()];

	}//end report()

	/**
	 * Inputs refused with a 400, before any access check.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function badInputs(): array {
		$required = 'administration_id and as_of are required';
		$invalid = 'Invalid administration_id, as_of, sku or warehouse';
		$ok = ['administration_id' => 'adm-1', 'as_of' => '2026-01-31'];
		return [
			'no administration' => [['as_of' => '2026-01-31'], $required],
			'blank administration' => [['administration_id' => '  ', 'as_of' => '2026-01-31'], $required],
			'no date' => [['administration_id' => 'adm-1'], $required],
			'bad administration' => [['administration_id' => 'adm/1', 'as_of' => '2026-01-31'], $invalid],
			'long administration' => [['administration_id' => str_repeat('a', 65), 'as_of' => '2026-01-31'], $invalid],
			'bad date' => [['administration_id' => 'adm-1', 'as_of' => '31-01-2026'], $invalid],
			'date with junk' => [['administration_id' => 'adm-1', 'as_of' => '2026-01-31x'], $invalid],
			'bad sku' => [$ok + ['sku' => 'a;b'], $invalid],
			'bad warehouse' => [$ok + ['warehouse' => 'w<1>'], $invalid],
		];

	}//end badInputs()

	/**
	 * A missing or malformed input is a 400 and nothing is read.
	 *
	 * @param array<string,mixed> $params The parameters.
	 * @param string $error The expected error.
	 *
	 * @return void
	 *
	 * @dataProvider badInputs
	 */
	public function testBadInputIsRefused(array $params, string $error): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->expects(self::never())->method('canAccess');
		$this->report->expects(self::never())->method(self::anything());

		self::assertSame([400, ['error' => $error]], $this->report($params));

	}//end testBadInputIsRefused()

	/**
	 * An anonymous caller is refused first, a foreign administration with a 404.
	 *
	 * @return void
	 */
	public function testCallerAndAdministrationAreChecked(): void {
		$this->context->method('currentUserId')->willReturnOnConsecutiveCalls(null, 'alice');
		$this->context->expects(self::once())->method('canAccess')->with('adm 1')->willReturn(false);
		$this->report->expects(self::never())->method(self::anything());

		self::assertSame([401, ['error' => 'Not authenticated']], $this->report(['administration_id' => 'bad/', 'as_of' => 'x']));
		self::assertSame([404, ['error' => 'Administration not found']], $this->report(['administration_id' => ' adm 1 ', 'as_of' => ' 2026-01-31 12:00 ']));

	}//end testCallerAndAdministrationAreChecked()

	/**
	 * Filters that are passed on, and when ageing is added.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<int,mixed>,2:bool}>
	 */
	public static function allowed(): array {
		$base = ['administration_id' => 'adm-1', 'as_of' => '2026-01-31T00:00'];
		return [
			'no filters' => [$base, ['adm-1', '2026-01-31T00:00', null, null], false],
			'sku only, ageing asked' => [$base + ['sku' => 'S 1', 'ageing' => '1'], ['adm-1', '2026-01-31T00:00', 'S 1', null], false],
			'both, ageing not asked' => [$base + ['sku' => 'S1', 'warehouse' => 'W1', 'ageing' => 'yes'], ['adm-1', '2026-01-31T00:00', 'S1', 'W1'], false],
			'both, ageing asked' => [$base + ['sku' => ' S1 ', 'warehouse' => 'W1', 'ageing' => '1'], ['adm-1', '2026-01-31T00:00', 'S1', 'W1'], true],
		];

	}//end allowed()

	/**
	 * An allowed caller gets the valuation, and the ageing when asked for one item.
	 *
	 * @param array<string,mixed> $params The parameters.
	 * @param array<int,mixed> $arguments The valuation arguments.
	 * @param bool $ageing Whether ageing is added.
	 *
	 * @return void
	 *
	 * @dataProvider allowed
	 */
	public function testAllowedReport(array $params, array $arguments, bool $ageing): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willReturn(true);
		$this->report->expects(self::once())->method('valuationAsOf')->with(...$arguments)->willReturn(['v' => 1]);
		$expected = ['valuation' => ['v' => 1]];
		if ($ageing === true) {
			$this->report->expects(self::once())->method('ageing')->with('adm-1', '2026-01-31T00:00', 'S1', 'W1')->willReturn(['a' => 2]);
			$expected['ageing'] = ['a' => 2];
		} else {
			$this->report->expects(self::never())->method('ageing');
		}

		self::assertSame([200, $expected], $this->report($params));

	}//end testAllowedReport()

	/**
	 * A failing access check is logged and a 500.
	 *
	 * @return void
	 */
	public function testFailingAccessCheckIsA500(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willThrowException(new \RuntimeException('ctx'));
		$this->logger->expects(self::once())->method('error')->with(
			'InventoryValuationReportController: failed to compute valuation report',
			['administrationId' => 'adm-1', 'exception' => 'ctx']
		);

		self::assertSame([500, ['error' => 'Failed to compute inventory valuation report']], $this->report(['administration_id' => 'adm-1', 'as_of' => '2026-01-31']));

	}//end testFailingAccessCheckIsA500()
}//end class
