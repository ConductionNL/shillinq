<?php

/**
 * BillableWorkControllerTest
 *
 * @category  Tests
 * @package   OCA\Shillinq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/shillinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-sales-time-and-expense-billing/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BillableWorkController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BillableWorkServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The administration is checked before any work is read.
 */
#[CoversClass(BillableWorkController::class)]
final class BillableWorkControllerTest extends TestCase {

	/**
	 * The caller's administrations.
	 *
	 * @var AdministrationContextServiceInterface&MockObject
	 */
	private AdministrationContextServiceInterface&MockObject $context;

	/**
	 * The unbilled work.
	 *
	 * @var BillableWorkServiceInterface&MockObject
	 */
	private BillableWorkServiceInterface&MockObject $work;

	/**
	 * Build the doubles: a signed-in user whose active administration is adm-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->context = $this->createMock(AdministrationContextServiceInterface::class);
		$this->context->method('currentUserId')->willReturn('pm');
		$this->context->method('buildContext')->willReturn(['activeAdministrationId' => 'adm-1']);
		$this->work = $this->createMock(BillableWorkServiceInterface::class);
	}//end setUp()

	/**
	 * The controller over a request with the given parameters.
	 *
	 * @param array<string,string> $params The query parameters.
	 *
	 * @return BillableWorkController
	 */
	private function controller(array $params): BillableWorkController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name, mixed $default = null): mixed => $params[$name] ?? $default);
		return new BillableWorkController(request: $request, context: $this->context, work: $this->work, logger: new NullLogger());
	}//end controller()

	/**
	 * The active administration's work is answered, with the request's filters.
	 *
	 * @return void
	 */
	public function testTheActiveAdministrationsWorkIsListed(): void {
		$listed = ['hours' => ['available' => true, 'reason' => '', 'entries' => []], 'expenses' => [['id' => 'r-train']]];
		$this->work->expects($this->once())->method('billableWork')
			->with('adm-1', 'anker', 'p-kade', '2026-10-01', '2026-10-31', 'rc-2026')
			->willReturn($listed);

		$response = $this->controller(params: ['customerId' => ' anker ', 'projectId' => 'p-kade', 'from' => '2026-10-01', 'to' => '2026-10-31', 'rateCardId' => 'rc-2026'])->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($listed, $response->getData());
	}//end testTheActiveAdministrationsWorkIsListed()

	/**
	 * Another administration the caller may not access answers a masked 404 and reads nothing.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationIsRefused(): void {
		$this->context->method('canAccess')->with('adm-2')->willReturn(false);
		$this->work->expects($this->never())->method('billableWork');

		$response = $this->controller(params: ['administrationId' => 'adm-2', 'customerId' => 'anker'])->index();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Administration not found'], $response->getData());
	}//end testAnotherAdministrationIsRefused()

	/**
	 * A named administration the caller may access is read instead of the active one.
	 *
	 * @return void
	 */
	public function testANamedAccessibleAdministrationIsUsed(): void {
		$this->context->method('canAccess')->with('adm-3')->willReturn(true);
		$this->work->expects($this->once())->method('billableWork')->with('adm-3', 'anker')
			->willReturn(['hours' => ['available' => true, 'reason' => '', 'entries' => []], 'expenses' => []]);

		$this->assertSame(Http::STATUS_OK, $this->controller(params: ['administrationId' => 'adm-3', 'customerId' => 'anker'])->index()->getStatus());
	}//end testANamedAccessibleAdministrationIsUsed()

	/**
	 * Signed out answers 401; no administration answers 404; a failure answers 500.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailures(): void {
		$signedOut = $this->createMock(AdministrationContextServiceInterface::class);
		$signedOut->method('currentUserId')->willReturn(null);
		$request = $this->createMock(IRequest::class);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, (new BillableWorkController(request: $request, context: $signedOut, work: $this->work, logger: new NullLogger()))->index()->getStatus());

		$none = $this->createMock(AdministrationContextServiceInterface::class);
		$none->method('currentUserId')->willReturn('pm');
		$none->method('buildContext')->willReturn(['activeAdministrationId' => null]);
		$this->assertSame(Http::STATUS_NOT_FOUND, (new BillableWorkController(request: $request, context: $none, work: $this->work, logger: new NullLogger()))->index()->getStatus());

		$this->work->method('billableWork')->willThrowException(new RuntimeException('store down'));
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $this->controller(params: ['customerId' => 'anker'])->index()->getStatus());
	}//end testRefusalsAndFailures()
}//end class
