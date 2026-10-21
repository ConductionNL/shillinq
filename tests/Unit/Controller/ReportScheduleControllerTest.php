<?php

/**
 * Tests for ReportScheduleController and ReportScheduleService.
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
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ReportScheduleController;
use OCA\Shillinq\Reporting\Schedule\ReportScheduleCalendar;
use OCA\Shillinq\Reporting\Schedule\ReportScheduleService;
use OCA\Shillinq\Reporting\Schedule\ReportRecipientPolicy;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Http;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A schedule is written by its owner only, with server-set status and next
 * run, and recipients the owner may share the administration's reports with.
 */
class ReportScheduleControllerTest extends TestCase {

	/**
	 * The object store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * A controller for one caller over a store holding anna's schedule.
	 *
	 * Members of adm-1: anna and bram. Mallory belongs to no administration.
	 * Anna is in the group controllers.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param string|null          $userId The caller.
	 *
	 * @return ReportScheduleController The controller.
	 */
	private function controller(array $params, ?string $userId): ReportScheduleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$members = ['anna' => true, 'bram' => true];
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($userId);
		$context->method('canUserAccess')->willReturnCallback(
			static fn (string $user, string $administrationId): bool => $administrationId === 'adm-1' && isset($members[$user]) === true
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			function (string $gid): IGroup {
				$group = $this->createMock(IGroup::class);
				$group->method('getGID')->willReturn($gid);
				$member = $this->createMock(IUser::class);
				$member->method('getUID')->willReturn('bram');
				$group->method('getUsers')->willReturn([$member]);
				return $group;
			}
		);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $user, string $group): bool => $user === 'anna' && $group === 'controllers'
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$this->store = new InMemoryObjectServiceStub(
			[
				'ReportSchedule' => [
					[
						'id'               => 'sched-1',
						'owner'            => 'anna',
						'administrationId' => 'adm-1',
						'name'             => 'Trial balance',
						'reportType'       => 'trial-balance',
						'format'           => 'csv',
						'frequency'        => 'monthly',
						'runDay'           => 5,
						'periodRule'       => 'previous-period',
						'recipients'       => ['group:controllers'],
						'folderPath'       => '/Shillinq/Reports',
						'nextRunAt'        => '2026-11-05T00:00:00+00:00',
						'status'           => 'active',
					],
				],
			]
		);

		$service = new ReportScheduleService(
			$this->store,
			$settings,
			$context,
			new ReportRecipientPolicy($context, $groups),
			new ReportScheduleCalendar(),
			$l10n
		);

		return new ReportScheduleController($request, $context, $service, $this->createMock(LoggerInterface::class));

	}//end controller()

	/**
	 * The stored schedule.
	 *
	 * @param string $id The schedule.
	 *
	 * @return array<string, mixed> The schedule.
	 */
	private function stored(string $id): array {
		$found = $this->store->find(id: $id, schema: 'ReportSchedule');
		self::assertNotNull($found);

		return $found->getObject();

	}//end stored()

	/**
	 * A new schedule is active and its next run is computed by the server, whatever the client sends.
	 *
	 * @return void
	 */
	public function testANewScheduleGetsItsStatusAndNextRunFromTheServer(): void {
		$response = $this->controller(
			[
				'administrationId' => 'adm-1',
				'reportType'       => 'trial-balance',
				'format'           => 'csv',
				'frequency'        => 'monthly',
				'runDay'           => 5,
				'recipients'       => ['user:bram'],
				'status'           => 'paused',
				'nextRunAt'        => '2000-01-01T00:00:00+00:00',
			],
			'anna'
		)->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('active', $response->getData()['status']);
		$this->assertNotSame('2000-01-01T00:00:00+00:00', $response->getData()['nextRunAt']);
		$this->assertStringEndsWith('-05T00:00:00+00:00', $response->getData()['nextRunAt']);
		$this->assertSame(['user:bram'], $response->getData()['recipients']);

	}//end testANewScheduleGetsItsStatusAndNextRunFromTheServer()

	/**
	 * A schedule for an administration the caller does not belong to is refused.
	 *
	 * @return void
	 */
	public function testAScheduleForAnotherAdministrationIsNotFound(): void {
		$response = $this->controller(
			['administrationId' => 'adm-2', 'reportType' => 'trial-balance', 'format' => 'csv', 'frequency' => 'monthly', 'runDay' => 5],
			'anna'
		)->create();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAScheduleForAnotherAdministrationIsNotFound()

	/**
	 * Another user cannot add themselves as a recipient of anna's schedule.
	 *
	 * @return void
	 */
	public function testAnotherUserCannotAddThemselvesAsRecipient(): void {
		$response = $this->controller(['recipients' => ['group:controllers', 'user:bram']], 'bram')->update('sched-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['group:controllers'], $this->stored('sched-1')['recipients']);

	}//end testAnotherUserCannotAddThemselvesAsRecipient()

	/**
	 * The owner cannot send the reports to someone outside the administration.
	 *
	 * @return void
	 */
	public function testTheOwnerCannotAddARecipientOutsideTheAdministration(): void {
		$response = $this->controller(['recipients' => ['user:mallory']], 'anna')->update('sched-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('user:mallory', $response->getData()['error']);
		$this->assertSame(['group:controllers'], $this->stored('sched-1')['recipients']);

	}//end testTheOwnerCannotAddARecipientOutsideTheAdministration()

	/**
	 * The owner cannot send the reports to a group they are not in.
	 *
	 * @return void
	 */
	public function testTheOwnerCannotAddAGroupTheyAreNotIn(): void {
		$response = $this->controller(['recipients' => ['group:everyone']], 'anna')->update('sched-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testTheOwnerCannotAddAGroupTheyAreNotIn()

	/**
	 * The owner changes recipients and frequency; the next run follows the new frequency.
	 *
	 * @return void
	 */
	public function testTheOwnerChangesTheSchedule(): void {
		$response = $this->controller(['recipients' => ['user:bram'], 'frequency' => 'weekly', 'runDay' => 1], 'anna')->update('sched-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$stored = $this->stored('sched-1');
		$this->assertSame(['user:bram'], $stored['recipients']);
		$this->assertSame('weekly', $stored['frequency']);
		$this->assertNotSame('2026-11-05T00:00:00+00:00', $stored['nextRunAt']);
		$this->assertSame('adm-1', $stored['administrationId']);

	}//end testTheOwnerChangesTheSchedule()

	/**
	 * A format the report does not offer is refused.
	 *
	 * @return void
	 */
	public function testAFormatTheReportDoesNotOfferIsRefused(): void {
		$response = $this->controller(['format' => 'xbrl'], 'anna')->update('sched-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testAFormatTheReportDoesNotOfferIsRefused()

	/**
	 * Nobody signed in gets nothing.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller([], null)->create()->getStatus());

	}//end testAnAnonymousCallerIsRefused()
}//end class
