<?php

/**
 * The list of records due for destruction over HTTP (REQ-RET-015).
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
 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Shillinq\Controller\RetentionDueController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\RetentionDueService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Shillinq\Controller\RetentionDueController
 */
final class RetentionDueControllerTest extends TestCase {

	private AdministrationContextServiceInterface&MockObject $context;

	private RetentionDueService&MockObject $due;

	private LoggerInterface&MockObject $logger;

	/**
	 * The query parameters of the request.
	 *
	 * @var array<string, string>
	 */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();
		$this->context = $this->createMock(AdministrationContextServiceInterface::class);
		$this->due     = $this->createMock(RetentionDueService::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}

	private function controller(): RetentionDueController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null) => ($this->params[$key] ?? $default)
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-12T09:00:00Z'));

		return new RetentionDueController($request, $this->context, $this->due, $time, $this->logger);
	}

	private function signedIn(): void {
		$this->context->method('currentUserId')->willReturn('bookkeeper');
	}

	public function testTheActiveAdministrationsDueRecordsAreListed(): void {
		$this->signedIn();
		$this->context->method('buildContext')->willReturn(['userId' => 'bookkeeper', 'administrations' => [], 'activeAdministrationId' => 'adm-1']);
		$this->context->expects($this->once())->method('canAccess')->with('adm-1')->willReturn(true);
		$row = ['register' => 'GLTransaction', 'recordId' => 'tx-2018', 'ruleCode' => '5.1.2', 'endDate' => '2025-03-15', 'disposition' => 'destroy'];
		$this->due->expects($this->once())->method('dueFor')
			->with('adm-1', $this->callback(fn (DateTimeImmutable $today) => $today->format('Y-m-d') === '2026-11-12'))
			->willReturn([$row]);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['administrationId' => 'adm-1', 'results' => [$row], 'total' => 1], $response->getData());
	}

	public function testANamedAdministrationIsUsedOverTheActiveOne(): void {
		$this->signedIn();
		$this->params = ['administrationId' => 'adm-2'];
		$this->context->expects($this->never())->method('buildContext');
		$this->context->method('canAccess')->with('adm-2')->willReturn(true);
		$this->due->expects($this->once())->method('dueFor')->with('adm-2')->willReturn([]);

		$this->assertSame(['administrationId' => 'adm-2', 'results' => [], 'total' => 0], $this->controller()->index()->getData());
	}

	public function testAnAdministrationTheCallerMayNotReadIsNotFound(): void {
		$this->signedIn();
		$this->params = ['administrationId' => 'someone-elses'];
		$this->context->method('canAccess')->willReturn(false);
		$this->due->expects($this->never())->method('dueFor');

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Administration not found', $response->getData()['error']);
	}

	public function testAnAnonymousCallerIsRefused(): void {
		$this->context->method('currentUserId')->willReturn(null);
		$this->context->expects($this->never())->method('canAccess');
		$this->due->expects($this->never())->method('dueFor');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->index()->getStatus());
	}

	public function testAMalformedIdIsRefusedBeforeAnyLookup(): void {
		$this->signedIn();
		$this->params = ['administrationId' => "adm-1' OR 1=1"];
		$this->context->expects($this->never())->method('canAccess');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->index()->getStatus());
	}

	public function testACallerWithoutAnAdministrationGetsAnEmptyList(): void {
		$this->signedIn();
		$this->context->method('buildContext')->willReturn(['userId' => 'bookkeeper', 'administrations' => [], 'activeAdministrationId' => null]);
		$this->context->expects($this->never())->method('canAccess');
		$this->due->expects($this->never())->method('dueFor');

		$this->assertSame(['administrationId' => null, 'results' => [], 'total' => 0], $this->controller()->index()->getData());
	}

	public function testAFailedReadIsA500WithoutDetail(): void {
		$this->signedIn();
		$this->params = ['administrationId' => 'adm-1'];
		$this->context->method('canAccess')->willReturn(true);
		$this->due->method('dueFor')->willThrowException(new RuntimeException('register unreachable at db-7'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('register unreachable'));

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('db-7', json_encode($response->getData()));
	}
}
