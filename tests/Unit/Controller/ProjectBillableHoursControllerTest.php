<?php

/**
 * Unit tests for ProjectBillableHoursController: the administration check on
 * the project runs before any hour is read, and the month's rated hours or
 * the hours-unavailable answer come back (REQ-BRPH-003).
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
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Controller;

use DateTime;
use OCA\Shillinq\Controller\ProjectBillableHoursController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\BillableRateService;
use OCA\Shillinq\Service\Billing\BillableHoursSource;
use OCA\Shillinq\Service\Billing\ProjectBillableHours;
use OCA\Shillinq\Service\Billing\UnavailableBillableHours;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The controller over a register with one project per administration.
 *
 * @covers \OCA\Shillinq\Controller\ProjectBillableHoursController
 * @uses   \OCA\Shillinq\Service\Billing\ProjectBillableHours
 * @uses   \OCA\Shillinq\Service\Billing\UnavailableBillableHours
 * @uses   \OCA\Shillinq\Service\BillableRateService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class ProjectBillableHoursControllerTest extends TestCase {
	/**
	 * The controller.
	 *
	 * @param BillableHoursSource  $hours   The hours source.
	 * @param string               $admin   The caller's active administration.
	 * @param array<string, mixed> $params  The query.
	 * @param bool                 $loggedIn Whether a user is signed in.
	 *
	 * @return ProjectBillableHoursController
	 */
	private function controller(
		BillableHoursSource $hours,
		string $admin = 'adm-1',
		array $params = [],
		bool $loggedIn = true
	): ProjectBillableHoursController {
		$store = new InMemoryObjectServiceStub(
			[
				'engagement' => [
					['id' => 'proj-1', 'administrationId' => 'adm-1', 'name' => 'Renovatie Kade 12'],
					['id' => 'proj-2', 'administrationId' => 'adm-2', 'name' => 'Elders'],
				],
			]
		);
		$register = $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-11-14T10:00:00Z'));
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($loggedIn === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('buildContext')->willReturn(['activeAdministrationId' => $admin]);

		return new ProjectBillableHoursController(
			$request,
			new ProjectBillableHours($hours, new BillableRateService($store, $register, $clock), $store, $register),
			$session,
			$context,
			$clock,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A project of another administration is refused before any hour is read.
	 *
	 * @return void
	 */
	public function testAProjectOfAnotherAdministrationIsRefused(): void {
		$hours = $this->createMock(BillableHoursSource::class);
		$hours->expects(self::never())->method('forProject');

		$response = $this->controller($hours)->index('proj-2');

		self::assertSame(404, $response->getStatus());
		self::assertSame('billable-hours-project-not-found', $response->getData()['error']);
	}//end testAProjectOfAnotherAdministrationIsRefused()

	/**
	 * Without an hours source the answer says the hours are unavailable, for the current month by default.
	 *
	 * @return void
	 */
	public function testWithoutAnHoursSourceTheAnswerSaysSo(): void {
		$response = $this->controller(new UnavailableBillableHours())->index('proj-1');

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertSame([false, 'no-hours-source', '2026-11', []], [$data['available'], $data['reason'], $data['month'], $data['rows']]);
	}//end testWithoutAnHoursSourceTheAnswerSaysSo()

	/**
	 * The month asked for is the month read.
	 *
	 * @return void
	 */
	public function testTheMonthAskedForIsRead(): void {
		$hours = $this->createMock(BillableHoursSource::class);
		$hours->expects(self::once())->method('forProject')->with('proj-1', '2026-10-01', '2026-10-31')
			->willReturn(['available' => true, 'reason' => '', 'hours' => []]);

		$data = $this->controller($hours, params: ['month' => '2026-10'])->index('proj-1')->getData();

		self::assertSame([true, '2026-10', 0.0], [$data['available'], $data['month'], $data['totals']['unbilledValue']]);
	}//end testTheMonthAskedForIsRead()

	/**
	 * A malformed month is a 422; an anonymous caller a 401; a caller without an administration a 403.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$hours = new UnavailableBillableHours();

		self::assertSame(422, $this->controller($hours, params: ['month' => '2026-1'])->index('proj-1')->getStatus());
		self::assertSame(401, $this->controller($hours, loggedIn: false)->index('proj-1')->getStatus());
		self::assertSame(403, $this->controller($hours, admin: '')->index('proj-1')->getStatus());
	}//end testRefusals()

	/**
	 * The endpoint is open to non-admin users; the administration check guards it.
	 *
	 * @return void
	 */
	public function testTheEndpointIsOpenToNonAdmins(): void {
		self::assertNotSame([], (new ReflectionMethod(ProjectBillableHoursController::class, 'index'))->getAttributes(NoAdminRequired::class));
	}//end testTheEndpointIsOpenToNonAdmins()
}//end class
