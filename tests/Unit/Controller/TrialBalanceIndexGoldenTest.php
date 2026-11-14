<?php

/**
 * Golden tests for TrialBalanceController::index().
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

use OCA\Shillinq\Controller\TrialBalanceController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\TrialBalanceService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every refusal, the access check and the computation of a trial balance.
 */
final class TrialBalanceIndexGoldenTest extends TestCase {

	/**
	 * Service mock.
	 *
	 * @var TrialBalanceService&MockObject
	 */
	private TrialBalanceService&MockObject $service;

	/**
	 * Context mock.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Logged errors.
	 *
	 * @var array<int,string>
	 */
	private array $logs = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(TrialBalanceService::class);
		$this->context = $this->createMock(AdministrationContextService::class);

	}//end setUp()

	/**
	 * Call index() with parameters.
	 *
	 * @param array<string,mixed> $params The parameters.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function index(array $params): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logs[] = $message . '|' . json_encode($context);
			}
		);
		$response = (new TrialBalanceController($request, $this->service, $this->context, $logger))->index();
		return [$response->getStatus(), $response->getData()];

	}//end index()

	/**
	 * Inputs refused with a 400, in the order they are checked.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function badInputs(): array {
		return [
			'no period, bad administration' => [['administration_id' => 'a b'], 'period_id is required'],
			'no administration, bad period' => [['period_id' => 'p q'], 'administration_id is required'],
			'both bad' => [['period_id' => 'p q', 'administration_id' => 'a b'], 'period_id must be a valid period identifier'],
			'bad administration' => [['period_id' => '2026-01', 'administration_id' => str_repeat('a', 65)], 'administration_id must be a valid administration identifier'],
		];

	}//end badInputs()

	/**
	 * A missing or malformed input is a 400 without an access check.
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
		$this->service->expects(self::never())->method(self::anything());

		self::assertSame([400, ['error' => $error]], $this->index($params));

	}//end testBadInputIsRefused()

	/**
	 * The caller, then the administration, are checked.
	 *
	 * @return void
	 */
	public function testCallerAndAdministration(): void {
		$this->context->method('currentUserId')->willReturnOnConsecutiveCalls(null, 'alice', 'alice');
		$this->context->method('canAccess')->willReturnCallback(
			static function (string $id): bool {
				if ($id === 'boom') {
					throw new \RuntimeException('ctx');
				}

				return false;
			}
		);
		$this->service->expects(self::never())->method(self::anything());

		self::assertSame([401, ['error' => 'Not authenticated']], $this->index([]));
		self::assertSame([404, ['error' => 'Administration not found']], $this->index(['period_id' => ' p ', 'administration_id' => ' adm-1 ']));
		self::assertSame([500, ['error' => 'Failed to authorise trial balance']], $this->index(['period_id' => 'p', 'administration_id' => 'boom']));
		self::assertSame(['TrialBalanceController: failed to check administration access|{"administrationId":"boom","exception":"ctx"}'], $this->logs);

	}//end testCallerAndAdministration()

	/**
	 * Prior periods and the filters they become.
	 *
	 * @return array<string,array{0:string,1:array<string,string>}>
	 */
	public static function priorPeriods(): array {
		return [
			'none' => ['', []],
			'valid' => [' 2025-12 ', ['priorPeriodId' => '2025-12']],
			'malformed is ignored' => ['2025/12', []],
		];

	}//end priorPeriods()

	/**
	 * An allowed caller gets the computed trial balance.
	 *
	 * @param string $prior The prior_period_id parameter.
	 * @param array<string,string> $filters The filters passed on.
	 *
	 * @return void
	 *
	 * @dataProvider priorPeriods
	 */
	public function testCompute(string $prior, array $filters): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willReturn(true);
		$this->service->expects(self::once())->method('compute')->with('adm-1', '2026-01', $filters)->willReturn(['rows' => []]);

		self::assertSame([200, ['rows' => []]], $this->index(['period_id' => '2026-01', 'administration_id' => 'adm-1', 'prior_period_id' => $prior]));

	}//end testCompute()

	/**
	 * A failing computation is logged and a 500.
	 *
	 * @return void
	 */
	public function testComputeFailure(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willReturn(true);
		$this->service->method('compute')->willThrowException(new \RuntimeException('db'));

		self::assertSame([500, ['error' => 'Failed to compute trial balance']], $this->index(['period_id' => '2026-01', 'administration_id' => 'adm-1']));
		self::assertSame(['TrialBalanceController: failed to compute trial balance|{"administrationId":"adm-1","periodId":"2026-01","exception":"db"}'], $this->logs);

	}//end testComputeFailure()
}//end class
