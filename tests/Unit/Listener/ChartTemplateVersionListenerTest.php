<?php

/**
 * Unit tests for ChartTemplateVersionListener and ChartTemplateSyncJob.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\BackgroundJob\ChartTemplateSyncJob;
use OCA\Shillinq\Listener\ChartTemplateVersionListener;
use OCA\Shillinq\Service\ChartTemplate\ChartTemplateService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * A raised template version queues exactly one sync, and the job runs it.
 *
 * @covers \OCA\Shillinq\Listener\ChartTemplateVersionListener
 * @covers \OCA\Shillinq\BackgroundJob\ChartTemplateSyncJob
 */
class ChartTemplateVersionListenerTest extends TestCase {

	/**
	 * An entity of the schema with the given data and uuid.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param string               $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $uuid = 'tpl-1'): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getUuid')->willReturn($uuid);

		return $entity;
	}//end entity()

	/**
	 * Run the listener over one update and return the jobs it queued.
	 *
	 * @param array<string, mixed> $old      The data before.
	 * @param array<string, mixed> $new      The data after.
	 * @param bool                 $isTemplate Whether the schema matches.
	 * @param string               $uuid     The uuid.
	 *
	 * @return list<array{0: string, 1: mixed}>
	 */
	private function queued(array $old, array $new, bool $isTemplate = true, string $uuid = 'tpl-1'): array {
		$jobs = [];
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function ($job, $argument = null) use (&$jobs): void {
				$jobs[] = [$job, $argument];
			}
		);
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturnCallback(static fn (?object $entity, string $slug): bool => $isTemplate && $slug === 'AdministrationTemplate');

		$event = $this->createMock(ObjectUpdatedEvent::class);
		$event->method('getObject')->willReturn($this->entity(data: $new, uuid: $uuid));
		$event->method('getOldObject')->willReturn($this->entity(data: $old, uuid: $uuid));

		(new ChartTemplateVersionListener(jobList: $jobList, schemaResolver: $resolver))->handle($event);

		return $jobs;
	}//end queued()

	/**
	 * A raised version queues one sync of that template.
	 *
	 * @return void
	 */
	public function testARaisedVersionQueuesTheSync(): void {
		self::assertSame([[ChartTemplateSyncJob::class, ['templateId' => 'tpl-1']]], $this->queued(old: ['version' => 1], new: ['version' => 2]));
		self::assertSame([[ChartTemplateSyncJob::class, ['templateId' => 'tpl-1']]], $this->queued(old: [], new: ['version' => 2]));
	}//end testARaisedVersionQueuesTheSync()

	/**
	 * The same or a lower version, another schema, no uuid, or another event queue nothing.
	 *
	 * @return void
	 */
	public function testAnythingElseQueuesNothing(): void {
		self::assertSame([], $this->queued(old: ['version' => 2], new: ['version' => 2, 'name' => 'Nieuw']));
		self::assertSame([], $this->queued(old: ['version' => 3], new: ['version' => 2]));
		self::assertSame([], $this->queued(old: [], new: []));
		self::assertSame([], $this->queued(old: ['version' => 1], new: ['version' => 2], isTemplate: false));
		self::assertSame([], $this->queued(old: ['version' => 1], new: ['version' => 2], uuid: ''));

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('add');
		(new ChartTemplateVersionListener(jobList: $jobList, schemaResolver: $this->createMock(ListenerSchemaResolver::class)))->handle(new Event());
	}//end testAnythingElseQueuesNothing()

	/**
	 * The job syncs the template it names, ignores an empty argument, and
	 * logs a failure instead of throwing.
	 *
	 * @return void
	 */
	public function testTheJobSyncsTheNamedTemplate(): void {
		$templates = $this->createMock(ChartTemplateService::class);
		$templates->expects(self::exactly(2))->method('syncFollowers')->willReturnCallback(
			static function (string $templateId): array {
				if ($templateId === 'tpl-bad') {
					throw new \RuntimeException('register down');
				}

				return [];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('ChartTemplateSyncJob: template sync failed', ['templateId' => 'tpl-bad', 'exception' => 'register down']);

		$job = new ChartTemplateSyncJob(time: $this->createMock(ITimeFactory::class), templates: $templates, logger: $logger);
		$run = new ReflectionMethod($job, 'run');
		$run->invoke($job, ['templateId' => ' tpl-1 ']);
		$run->invoke($job, ['templateId' => 'tpl-bad']);
		$run->invoke($job, ['templateId' => '']);
		$run->invoke($job, 'tpl-1');
	}//end testTheJobSyncsTheNamedTemplate()
}//end class
