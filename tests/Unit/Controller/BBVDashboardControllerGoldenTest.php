<?php

/**
 * Golden tests for BBVDashboardController::index: administration resolution, the IDOR mask and the envelope.
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
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BBVDashboardController;
use OCA\Shillinq\Dashboard\BBVComplianceWidget;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ComplianceService;
use OCA\Shillinq\Service\FiscalYearContextService;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins what the dashboard reads, in which order, and every envelope it answers.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BBVDashboardControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Call index once.
	 *
	 * @param array<string,mixed> $setup user (bool), param, context, member (list), window (array|null).
	 *
	 * @return JSONResponse
	 */
	private function index(array $setup=[]): JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key) use ($setup): mixed {
				$this->calls[] = ['getParam', $key];
				return ($setup['param'] ?? null);
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => 'T:' . $text);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('buildContext')->willReturnCallback(
			function () use ($setup): array {
				$this->calls[] = ['buildContext'];
				return ($setup['context'] ?? []);
			}
		);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return in_array($administrationId, ($setup['member'] ?? []), true);
			}
		);
		$fiscal = $this->createMock(FiscalYearContextService::class);
		$fiscal->method('resolveActiveWindow')->willReturnCallback(
			function (string $administrationId) use ($setup): ?array {
				$this->calls[] = ['resolveActiveWindow', $administrationId];
				return ($setup['window'] ?? null);
			}
		);
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(false);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$logger    = $this->createMock(LoggerInterface::class);
		$widget    = new BBVComplianceWidget($container, $settings, new ComplianceService($container, $settings, $cacheFactory, $logger), $logger);

		return (new BBVDashboardController($request, $session, $l10n, $widget, $context, $fiscal))->index();
	}//end index()

	/**
	 * The envelope without its timestamp, which is checked for shape instead.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array<string,mixed>
	 */
	private static function envelope(JSONResponse $response): array {
		$data = $response->getData();
		self::assertSame(expected: 200, actual: $response->getStatus());
		self::assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|\+00:00)$/', string: $data['generatedAt']);
		unset($data['generatedAt']);
		return $data;
	}//end envelope()

	/**
	 * The empty envelope with the given scope.
	 *
	 * @param string|null $administrationId The echoed administration.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty(?string $administrationId): array {
		return [
			'widgets' => [],
			'programmes' => [],
			'mappings' => [],
			'counts' => ['unconfigured' => 0, 'on-track' => 0, 'at-risk' => 0, 'non-compliant' => 0],
			'summary' => ['programmeCount' => 0, 'mappingCount' => 0, 'totalBudget' => 0, 'totalYtdSpend' => 0, 'utilization' => 0.0],
			'scope' => ['administrationId' => $administrationId, 'fiscalYear' => null, 'startDate' => null, 'endDate' => null],
		];
	}//end empty()

	/**
	 * 401 for an anonymous caller.
	 *
	 * @return void
	 */
	public function testAnonymous(): void {
		$response = $this->index(setup: ['user' => false]);
		self::assertSame(expected: [401, ['error' => 'T:Not logged in']], actual: [$response->getStatus(), $response->getData()]);
		self::assertSame(expected: [], actual: $this->calls);
	}//end testAnonymous()

	/**
	 * No administration, or one the caller is not a member of, is the empty envelope without an echoed id.
	 *
	 * @return void
	 */
	public function testIdorMask(): void {
		$actual = self::envelope($this->index(setup: ['param' => ['x'], 'context' => ['activeAdministrationId' => 7]]));
		self::assertSame(expected: self::empty(administrationId: null), actual: $actual);
		self::assertSame(expected: [['getParam', 'administrationId'], ['buildContext']], actual: $this->calls);
		$this->calls = [];
		$actual = self::envelope($this->index(setup: ['param' => ' w2 ', 'member' => ['w1']]));
		self::assertSame(expected: self::empty(administrationId: null), actual: $actual);
		self::assertSame(expected: [['getParam', 'administrationId'], ['canAccess', 'w2']], actual: $this->calls);
	}//end testIdorMask()

	/**
	 * A member without an active fiscal year gets the empty envelope with the id echoed; the session default is used when no id is sent.
	 *
	 * @return void
	 */
	public function testNoWindowAndSessionDefault(): void {
		$setup = ['param' => '   ', 'context' => ['activeAdministrationId' => 'w1'], 'member' => ['w1']];
		self::assertSame(expected: self::empty(administrationId: 'w1'), actual: self::envelope($this->index(setup: $setup)));
		$actual = $this->calls;
		$expected = [['getParam', 'administrationId'], ['buildContext'], ['canAccess', 'w1'], ['resolveActiveWindow', 'w1']];
		self::assertSame(expected: $expected, actual: $actual);
	}//end testNoWindowAndSessionDefault()

	/**
	 * A member with an active fiscal year gets the widget envelope with the scope added.
	 *
	 * @return void
	 */
	public function testScopedEnvelope(): void {
		$window   = ['fiscalYear' => 2026, 'startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'extra' => 1];
		$envelope = self::envelope($this->index(setup: ['param' => 'w1', 'member' => ['w1'], 'window' => $window]));
		self::assertSame(expected: ['widgets', 'programmes', 'mappings', 'counts', 'summary', 'scope'], actual: array_keys($envelope));
		$actual = $envelope['scope'];
		$expected = ['administrationId' => 'w1', 'fiscalYear' => 2026, 'startDate' => '2026-01-01', 'endDate' => '2026-12-31'];
		self::assertSame(expected: $expected, actual: $actual);
		self::assertCount(expectedCount: 4, haystack: $envelope['widgets']);
	}//end testScopedEnvelope()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
