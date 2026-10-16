<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * reporting-data-delivery REQ-RDD-002: a due schedule produces its report as
 * its owner, delivers it and moves on; a failed run records why and tells the
 * owner; a schedule never runs for an owner who cannot reach the
 * administration. The schedule as written back is validated against the real
 * ReportSchedule fragment, and the owner check runs through the real
 * AdministrationContextService over AdministrationMembership rows.
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Schedule;

use DateTimeImmutable;
use OCA\Shillinq\Reporting\ReportGenerationService;
use OCA\Shillinq\Reporting\Schedule\ReportScheduleCalendar;
use OCA\Shillinq\Reporting\Schedule\ScheduledReportDelivery;
use OCA\Shillinq\Reporting\Schedule\ScheduledReportRunner;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ScheduledReportRunnerTest extends TestCase {

	private InMemoryObjectServiceStub $store;

	/** @var array<int, array<string, mixed>> */
	private array $saved = [];

	private ReportGenerationService&MockObject $reports;

	private ScheduledReportDelivery&MockObject $delivery;

	/**
	 * The monthly budget schedule of the design's seed, due on 2026-10-05.
	 *
	 * @param string $owner The schedule's owner.
	 *
	 * @return array<string, mixed> The schedule row.
	 */
	private static function schedule(string $owner = 'controller-anna'): array {
		return [
			'id' => 'sched-1',
			'owner' => $owner,
			'administrationId' => 'adm-voorbeeld',
			'name' => 'Maandrapportage budget versus realisatie',
			'reportType' => 'budget-vs-actual',
			'format' => 'pdf',
			'frequency' => 'monthly',
			'runDay' => 5,
			'periodRule' => 'previous-period',
			'recipients' => ['group:controllers'],
			'folderPath' => '/Rapportages/Maand',
			'nextRunAt' => '2026-10-05T00:00:00+00:00',
			'status' => 'active',
		];

	}//end schedule()

	/**
	 * Build the runner over a store holding the schedule and the owner's membership.
	 *
	 * @param array<string, mixed> $schedule The schedule row.
	 *
	 * @return ScheduledReportRunner The runner.
	 */
	private function runner(array $schedule): ScheduledReportRunner {
		$this->store = new InMemoryObjectServiceStub(
			[
				'ReportSchedule' => [$schedule],
				'AdministrationMembership' => [
					['id' => 'm-1', 'userId' => 'controller-anna', 'administrationId' => 'adm-voorbeeld', 'role' => 'controller'],
				],
			],
			$this->saved,
			true
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$administrations = new AdministrationContextService(
			$container,
			$this->createMock(IUserSession::class),
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);

		$this->reports  = $this->createMock(ReportGenerationService::class);
		$this->delivery = $this->createMock(ScheduledReportDelivery::class);

		return new ScheduledReportRunner(
			$this->store,
			$settings,
			$this->reports,
			$this->delivery,
			new ReportScheduleCalendar(),
			$administrations,
			$this->createMock(LoggerInterface::class)
		);

	}//end runner()

	/**
	 * The schedule as it is stored now.
	 *
	 * @return array<string, mixed> The stored schedule.
	 */
	private function stored(): array {
		$found = $this->store->find(id: 'sched-1', schema: 'ReportSchedule');
		self::assertNotNull($found);
		$row = $found->getObject();
		unset($row['id'], $row['owner']);

		return $row;

	}//end stored()

	/**
	 * Scenario "The 5th of October": the September report is produced, filed and announced.
	 *
	 * @return void
	 */
	public function testTheFifthOfOctober(): void {
		$runner = $this->runner(self::schedule());
		$this->reports->expects(self::once())->method('generate')
			->with('budget-vs-actual', '2026-09', 'adm-voorbeeld', 'pdf', 'controller-anna')
			->willReturn(['id' => 'gen-1', 'fileId' => 42, 'status' => 'ready']);
		$this->delivery->expects(self::once())->method('file')
			->with('controller-anna', 42, '/Rapportages/Maand', ['group:controllers'])
			->willReturn(['fileId' => 77, 'path' => '/Rapportages/Maand/budget-vs-actual-2026-09.pdf']);
		$this->delivery->expects(self::once())->method('notifyReady')
			->with(['group:controllers'], self::anything(), '2026-09', 77)
			->willReturn(3);
		$this->delivery->expects(self::never())->method('notifyFailed');

		$counts = $runner->runDue(new DateTimeImmutable('2026-10-05T01:00:00Z'));

		self::assertSame(['ran' => 1, 'failed' => 0], $counts);
		$stored = $this->stored();
		self::assertSame('2026-11-05T00:00:00+00:00', $stored['nextRunAt']);
		self::assertSame('2026-09', $stored['lastPeriod']);
		self::assertSame('/Rapportages/Maand/budget-vs-actual-2026-09.pdf', $stored['lastFilePath']);
		self::assertSame('', $stored['lastError']);
		self::assertSame([], RegisterSchema::errors('ReportSchedule', $stored));

	}//end testTheFifthOfOctober()

	/**
	 * A schedule whose next run has not come does not run.
	 *
	 * @return void
	 */
	public function testAScheduleThatIsNotDueWaits(): void {
		$runner = $this->runner(self::schedule());
		$this->reports->expects(self::never())->method('generate');

		self::assertSame(['ran' => 0, 'failed' => 0], $runner->runDue(new DateTimeImmutable('2026-10-04T23:00:00Z')));

	}//end testAScheduleThatIsNotDueWaits()

	/**
	 * Scenario "A run fails": the reason is kept, the owner is told, the next run moves on.
	 *
	 * @return void
	 */
	public function testARunFails(): void {
		$runner = $this->runner(self::schedule());
		$this->reports->method('generate')->willReturn(
			['error' => 'docudesk-unavailable', 'message' => 'Docudesk is not installed.']
		);
		$this->delivery->expects(self::never())->method('file');
		$this->delivery->expects(self::once())->method('notifyFailed')
			->with('controller-anna', self::anything(), 'Docudesk is not installed.');

		self::assertSame(['ran' => 0, 'failed' => 1], $runner->runDue(new DateTimeImmutable('2026-10-05T01:00:00Z')));
		$stored = $this->stored();
		self::assertSame('Docudesk is not installed.', $stored['lastError']);
		self::assertSame('2026-11-05T00:00:00+00:00', $stored['nextRunAt']);
		self::assertSame([], RegisterSchema::errors('ReportSchedule', $stored));

	}//end testARunFails()

	/**
	 * A schedule owned by someone without access to the administration produces nothing.
	 *
	 * @return void
	 */
	public function testAnOwnerWithoutAccessProducesNothing(): void {
		$runner = $this->runner(self::schedule('outsider'));
		$this->reports->expects(self::never())->method('generate');
		$this->delivery->expects(self::once())->method('notifyFailed')
			->with('outsider', self::anything(), 'The schedule owner has no access to this administration.');

		self::assertSame(['ran' => 0, 'failed' => 1], $runner->runDue(new DateTimeImmutable('2026-10-05T01:00:00Z')));

	}//end testAnOwnerWithoutAccessProducesNothing()
}//end class
