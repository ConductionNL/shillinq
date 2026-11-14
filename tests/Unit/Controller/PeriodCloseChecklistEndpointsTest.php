<?php

/**
 * Unit tests for the checklist endpoints of PeriodCloseController.
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
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-person-resolves-reopens-and-adds-checklist-items-req-lpe-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PeriodCloseController;
use OCA\Shillinq\Kernel\Exception\PeriodCloseException;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PeriodChecklistService;
use OCA\Shillinq\Service\PeriodCloseAssistantService;
use OCA\Shillinq\Service\PeriodCloseService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Controller\PeriodCloseController
 * @uses   \OCA\Shillinq\Controller\PeriodCloseActor
 * @uses   \OCA\Shillinq\Kernel\Exception\PeriodCloseException
 */
final class PeriodCloseChecklistEndpointsTest extends TestCase {

	/**
	 * The checklist service.
	 *
	 * @var PeriodChecklistService&MockObject
	 */
	private PeriodChecklistService&MockObject $checklist;

	/**
	 * Whether the caller may access the administration.
	 *
	 * @var bool
	 */
	private bool $canAccess = true;

	/**
	 * The signed-in user, or null.
	 *
	 * @var string|null
	 */
	private ?string $user = 'alice';

	/**
	 * Fresh stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->checklist = $this->createMock(PeriodChecklistService::class);
		$this->canAccess = true;
		$this->user      = 'alice';

	}//end setUp()

	/**
	 * The controller over a request carrying the parameters.
	 *
	 * @param array<string,string> $params    The request parameters.
	 * @param bool                 $withList  Whether the checklist service is wired.
	 *
	 * @return PeriodCloseController
	 */
	private function controller(array $params, bool $withList = true): PeriodCloseController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		$session = $this->createMock(IUserSession::class);
		if ($this->user !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->user);
			$session->method('getUser')->willReturn($user);
		}

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(fn (): bool => $this->canAccess);

		return new PeriodCloseController(
			request: $request,
			periodCloseService: $this->createMock(PeriodCloseService::class),
			assistantService: $this->createMock(PeriodCloseAssistantService::class),
			context: $context,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
			checklist: $withList === true ? $this->checklist : null,
		);

	}//end controller()

	public function testResolvePassesThePeriodTheAdministrationTheItemAndTheUser(): void {
		$this->checklist->expects($this->once())->method('resolveItem')
			->with('2026-09', 'ADM-001', 'bank', 'alice')
			->willReturn(['periodId' => '2026-09', 'taskChecklistItems' => [['id' => 'bank', 'resolved' => true]]]);

		$response = $this->controller(['administration_id' => 'ADM-001'])->resolveItem(' 2026-09 ', 'bank');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['data' => ['periodId' => '2026-09', 'taskChecklistItems' => [['id' => 'bank', 'resolved' => true]]]], $response->getData());

	}//end testResolvePassesThePeriodTheAdministrationTheItemAndTheUser()

	public function testReopenPassesTheItem(): void {
		$this->checklist->expects($this->once())->method('reopenItem')
			->with('2026-09', 'ADM-001', 'ap', 'alice')
			->willReturn(['periodId' => '2026-09']);

		$this->assertSame(Http::STATUS_OK, $this->controller(['administration_id' => 'ADM-001'])->reopenItem('2026-09', 'ap')->getStatus());

	}//end testReopenPassesTheItem()

	public function testAddPassesTheCategoryAndDescriptionFromTheBody(): void {
		$this->checklist->expects($this->once())->method('addItem')
			->with('2026-09', 'ADM-001', 'other', 'loonjournaal geboekt', 'alice')
			->willReturn(['periodId' => '2026-09']);

		$response = $this->controller(['administration_id' => 'ADM-001', 'category' => 'other', 'description' => 'loonjournaal geboekt'])->addItem('2026-09');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAddPassesTheCategoryAndDescriptionFromTheBody()

	public function testRefusalsAreMappedToTheirStatus(): void {
		$cases = [
			['forbidden', Http::STATUS_FORBIDDEN],
			['not-found', Http::STATUS_NOT_FOUND],
			['validation', Http::STATUS_UNPROCESSABLE_ENTITY],
			['invalid-state', Http::STATUS_CONFLICT],
		];
		foreach ($cases as [$status, $http]) {
			$this->checklist = $this->createMock(PeriodChecklistService::class);
			$this->checklist->method('addItem')->willThrowException(new PeriodCloseException(message: 'refused ' . $status, status: $status));

			$response = $this->controller(['administration_id' => 'ADM-001', 'category' => 'x', 'description' => 'y'])->addItem('2026-09');

			$this->assertSame($http, $response->getStatus(), $status);
			$this->assertSame(['error' => 'refused ' . $status], $response->getData());
		}

	}//end testRefusalsAreMappedToTheirStatus()

	public function testAnUnexpectedFailureIsAGeneric500(): void {
		$this->checklist->method('resolveItem')->willThrowException(new \RuntimeException('database detail'));

		$response = $this->controller(['administration_id' => 'ADM-001'])->resolveItem('2026-09', 'bank');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'An unexpected error occurred'], $response->getData());

	}//end testAnUnexpectedFailureIsAGeneric500()

	public function testAnAnonymousCallerIs401AndNothingChanges(): void {
		$this->user = null;
		$this->checklist->expects($this->never())->method($this->anything());

		$controller = $this->controller(['administration_id' => 'ADM-001']);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->resolveItem('2026-09', 'bank')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->reopenItem('2026-09', 'bank')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->addItem('2026-09')->getStatus());

	}//end testAnAnonymousCallerIs401AndNothingChanges()

	public function testAForeignAdministrationIsAMasked404AndNothingChanges(): void {
		$this->canAccess = false;
		$this->checklist->expects($this->never())->method($this->anything());

		$controller = $this->controller(['administration_id' => 'ADM-999']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->resolveItem('2026-09', 'bank')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->addItem('2026-09')->getStatus());

	}//end testAForeignAdministrationIsAMasked404AndNothingChanges()

	public function testMalformedIdsAndAMissingAdministrationAre400(): void {
		$this->checklist->expects($this->never())->method($this->anything());

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(['administration_id' => 'ADM-001'])->resolveItem('2026-09', 'bad id!')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(['administration_id' => 'ADM-001'])->reopenItem('../x', 'bank')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller([])->addItem('2026-09')->getStatus());

	}//end testMalformedIdsAndAMissingAdministrationAre400()

	public function testWithoutTheChecklistServiceTheAnswerIsA500(): void {
		$response = $this->controller(['administration_id' => 'ADM-001'], false)->resolveItem('2026-09', 'bank');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

	}//end testWithoutTheChecklistServiceTheAnswerIsA500()
}//end class
