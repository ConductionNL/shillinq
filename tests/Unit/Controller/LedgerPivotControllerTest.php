<?php

/**
 * Tests for LedgerPivotController (reporting-custom-analysis REQ-RCA-002).
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\LedgerPivotController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Pivot\LedgerPivotService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The endpoint answers only for an administration the caller belongs to.
 */
class LedgerPivotControllerTest extends TestCase {

	/**
	 * A controller for a caller who belongs to adm-1 only.
	 *
	 * @param array<string, string> $params The request parameters.
	 * @param string|null           $userId The caller.
	 *
	 * @return LedgerPivotController The controller.
	 */
	private function controller(array $params, ?string $userId = 'director'): LedgerPivotController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($userId);
		$context->method('buildContext')->willReturn(['activeAdministrationId' => 'adm-1']);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$pivot = new LedgerPivotService(
			new InMemoryObjectServiceStub(
				[
					'GLTransaction' => [['id' => 'tx-1', 'administrationId' => 'adm-1', 'state' => 'posted', 'postingDate' => '2026-02-01']],
					'GLLine' => [['administrationId' => 'adm-1', 'transactionId' => 'tx-1', 'accountNumber' => '8000', 'signedAmount' => 100.0, 'accountClass' => 'pnl', 'countsInResult' => true]],
				]
			),
			$settings
		);

		return new LedgerPivotController($request, $context, $pivot, $this->createMock(LoggerInterface::class));
	}

	/**
	 * The active administration is pivoted when none is named.
	 *
	 * @return void
	 */
	public function testThePivotOfTheActiveAdministration(): void {
		$response = $this->controller(['rows' => 'account', 'columns' => 'quarter', 'from' => '2026-01-01', 'to' => '2026-12-31'])->pivot();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(100.0, $response->getData()['cells']['8000']['2026-Q1']);
	}

	/**
	 * Another administration is masked as absent.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationIsNotFound(): void {
		$response = $this->controller(['administrationId' => 'adm-2', 'rows' => 'account', 'columns' => 'quarter', 'from' => '2026-01-01', 'to' => '2026-12-31'])->pivot();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	/**
	 * A refused request says why.
	 *
	 * @return void
	 */
	public function testAnUnknownAxisIsABadRequest(): void {
		$response = $this->controller(['rows' => 'vatCode', 'columns' => 'quarter', 'from' => '2026-01-01', 'to' => '2026-12-31'])->pivot();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('vatCode', $response->getData()['error']);
	}

	/**
	 * Nobody signed in gets nothing.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller([], null)->pivot()->getStatus());
	}
}
