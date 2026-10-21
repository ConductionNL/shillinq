<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\BbvAllocationValidationListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The BBV rules the waterschappen variant declared run on the save path.
 */
final class BbvAllocationValidationListenerTest extends TestCase {

	/** @var array<string, array<int, array<string, mixed>>> */
	public array $rows = [
		'Account' => [['id' => 'acc', 'administrationId' => 'A', 'accountNumber' => '4000']],
		'BudgetBBVMapping' => [
			['id' => 'm1', 'administrationId' => 'A', 'glAccountNumber' => '4000', 'fiscalYear' => 2026, 'allocationPercentage' => 60],
		],
		'BBVProgramme' => [['id' => 'p1', 'administrationId' => 'A', 'fiscalYear' => 2026, 'programmeCode' => 'P01']],
	];

	private function listener(string $schema): BbvAllocationValidationListener {
		$test = $this;
		$objectService = new class($test) {
			private string $schema = '';
			public function __construct(private BbvAllocationValidationListenerTest $test) {
			}
			public function setRegister($r): self {
				return $this;
			}
			public function setSchema($s): self {
				$this->schema = $s;
				return $this;
			}
			public function findAll(array $config = [], bool $rbac = true, bool $multi = true): array {
				$filters = $config['filters'];
				return array_values(array_filter($this->test->rows[$this->schema] ?? [], static function (array $row) use ($filters): bool {
					foreach ($filters as $k => $v) {
						if (($row[$k] ?? null) != $v) {
							return false;
						}
					}
					return true;
				}));
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturnCallback(fn ($e, string $slug): bool => $slug === $schema);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		return new BbvAllocationValidationListener($resolver, $container, $settings, $this->createMock(LoggerInterface::class));
	}

	private function creating(array $data): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		return new ObjectCreatingEvent($entity);
	}

	public function testOverAllocationIsRefused(): void {
		$event = $this->creating(['administrationId' => 'A', 'glAccountNumber' => '4000', 'fiscalYear' => 2026, 'allocationPercentage' => 41]);
		$this->listener('BudgetBBVMapping')->handle($event);
		self::assertSame(BbvAllocationValidationListener::MESSAGE_OVER_ALLOCATED, $event->getErrors()['message'] ?? null);

		$event = $this->creating(['administrationId' => 'A', 'glAccountNumber' => '4000', 'fiscalYear' => 2026, 'allocationPercentage' => 40.1]);
		$this->listener('BudgetBBVMapping')->handle($event);
		self::assertSame([], $event->getErrors());
	}

	public function testUpdatingAMappingDoesNotCountItself(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('m1');
		$entity->setObject(['administrationId' => 'A', 'glAccountNumber' => '4000', 'fiscalYear' => 2026, 'allocationPercentage' => 100]);
		$event = new ObjectUpdatingEvent($entity, clone $entity);
		$this->listener('BudgetBBVMapping')->handle($event);
		self::assertSame([], $event->getErrors());
	}

	public function testUnknownAccountIsRefused(): void {
		$event = $this->creating(['administrationId' => 'B', 'glAccountNumber' => '4000', 'fiscalYear' => 2026, 'allocationPercentage' => 10]);
		$this->listener('BudgetBBVMapping')->handle($event);
		self::assertSame(BbvAllocationValidationListener::MESSAGE_UNKNOWN_ACCOUNT, $event->getErrors()['message'] ?? null);
	}

	public function testDuplicateProgrammeCodeIsRefused(): void {
		$event = $this->creating(['administrationId' => 'A', 'fiscalYear' => 2026, 'programmeCode' => 'P01']);
		$this->listener('BBVProgramme')->handle($event);
		self::assertSame(BbvAllocationValidationListener::MESSAGE_DUPLICATE_CODE, $event->getErrors()['message'] ?? null);

		$event = $this->creating(['administrationId' => 'A', 'fiscalYear' => 2027, 'programmeCode' => 'P01']);
		$this->listener('BBVProgramme')->handle($event);
		self::assertSame([], $event->getErrors());
	}
}
