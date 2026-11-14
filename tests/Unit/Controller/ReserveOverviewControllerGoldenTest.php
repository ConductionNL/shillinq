<?php

/**
 * Golden test for ReserveOverviewController::overview.
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
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ReserveOverviewController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PublicSector\ReserveBalances;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every answer of the overview, allowed and refused, and every read and call behind it in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ReserveOverviewControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Ask for the overview.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 * @param array<string,mixed> $setup  user (string|null), active (mixed), member (bool), fail (bool).
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function overview(array $params, array $setup=[]): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($params): mixed {
				$this->calls[] = ['getParam', $key, $default];
				return ($params[$key] ?? $default);
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturnCallback(
			function () use ($setup): ?string {
				$this->calls[] = ['currentUserId'];
				if (array_key_exists('user', $setup) === true) {
					return $setup['user'];
				}

				return 'u1';
			}
		);
		$context->method('buildContext')->willReturnCallback(
			function () use ($setup): array {
				$this->calls[] = ['buildContext'];
				return ['activeAdministrationId' => ($setup['active'] ?? null)];
			}
		);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$fail     = ($setup['fail'] ?? false);
		$balances = $this->createMock(ReserveBalances::class);
		$balances->method('overview')->willReturnCallback(
			function (string $administrationId, int $fromYear) use ($fail): array {
				$this->calls[] = ['overview', $administrationId, $fromYear];
				if ($fail === true) {
					throw new RuntimeException('ledger down');
				}

				return ['years' => [$fromYear]];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => 'T:' . $text);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		$response = (new ReserveOverviewController($request, $balances, $context, $l10n, $logger))->overview();
		return [$response->getStatus(), $response->getData()];
	}//end overview()

	/**
	 * No session is a 401 before any read; no or a foreign administration is a 404 before the year is read.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		self::assertSame(expected: [401, ['message' => 'T:Not logged in']], actual: $this->overview(params: [], setup: ['user' => null]));
		$notFound = [404, ['message' => 'T:Administration not found']];
		self::assertSame(expected: $notFound, actual: $this->overview(params: ['administrationId' => ' ']));
		self::assertSame(expected: $notFound, actual: $this->overview(params: ['administrationId' => ' a2 '], setup: ['member' => false]));
		self::assertSame(expected: $notFound, actual: $this->overview(params: [], setup: ['active' => 'a3', 'member' => false]));
		$expected = [
			['currentUserId'],
			['currentUserId'],
			['getParam', 'administrationId', ''],
			['buildContext'],
			['currentUserId'],
			['getParam', 'administrationId', ''],
			['canAccess', 'a2'],
			['currentUserId'],
			['getParam', 'administrationId', ''],
			['buildContext'],
			['canAccess', 'a3'],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testRefusals()

	/**
	 * The year is used when it lies in 1900 to 2999 and is the current year otherwise; a failure is a logged 500.
	 *
	 * @return void
	 */
	public function testYearsAndFailure(): void {
		$now   = (int)gmdate('Y');
		$cases = [['2025', 2025], [1900, 1900], [2999, 2999], [1899, $now], [3000, $now], ['x', $now], [null, $now]];
		foreach ($cases as [$given, $used]) {
			$this->calls = [];
			self::assertSame(expected: [200, ['years' => [$used]]], actual: $this->overview(params: ['administrationId' => 'a1', 'year' => $given]));
			$expected = [['currentUserId'], ['getParam', 'administrationId', ''], ['canAccess', 'a1'], ['getParam', 'year', 0], ['overview', 'a1', $used]];
			self::assertSame(expected: $expected, actual: $this->calls);
		}

		$this->calls = [];
		$actual      = $this->overview(params: [], setup: ['active' => ' a4', 'fail' => true]);
		self::assertSame(expected: [500, ['message' => 'T:The reserve overview could not be loaded.']], actual: $actual);
		$log = ['error', 'ReserveOverviewController: overview failed', ['exception' => 'ledger down']];
		$expected = [
			['currentUserId'],
			['getParam', 'administrationId', ''],
			['buildContext'],
			['canAccess', ' a4'],
			['getParam', 'year', 0],
			['overview', ' a4', $now],
			$log,
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testYearsAndFailure()
}//end class
