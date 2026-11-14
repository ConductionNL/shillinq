<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\BbvAllocationValidationListener;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Pins every OpenRegister read the BBV listener makes (register, schema,
 * filters, limit and the RBAC and multitenancy flags) and the refusal it sets,
 * over a grid of programmes and mappings.
 *
 * @covers \OCA\Shillinq\Listener\BbvAllocationValidationListener
 */
final class BbvAllocationValidationListenerGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/golden/bbv-allocation-listener.json';

	/** @var array<string, array<int, array<string, mixed>>> */
	private const ROWS = [
		'Account' => [
			['id' => 'acc-a', 'administrationId' => 'A', 'accountNumber' => '4000'],
			['id' => 'acc-b', 'administrationId' => 'B', 'accountNumber' => '5000'],
		],
		'BudgetBBVMapping' => [
			['id' => 'm1', 'administrationId' => 'A', 'glAccountNumber' => '4000', 'fiscalYear' => 2026, 'allocationPercentage' => 60],
			['id' => 'm2', 'administrationId' => 'A', 'glAccountNumber' => '4000', 'fiscalYear' => 2026, 'allocationPercentage' => 30],
			['id' => 'm3', 'administrationId' => 'B', 'glAccountNumber' => '5000', 'fiscalYear' => 2025, 'allocationPercentage' => '99.95'],
		],
		'BBVProgramme' => [
			['id' => 'p1', 'administrationId' => 'A', 'fiscalYear' => 2026, 'programmeCode' => 'P01'],
			['@self' => ['id' => 'p2'], 'administrationId' => 'B', 'fiscalYear' => 2025, 'programmeCode' => '1.2'],
		],
	];

	/** @var array<int, array<string, mixed>> */
	private array $calls = [];

	/**
	 * The listener, built with whichever OpenRegister collaborator its constructor takes.
	 *
	 * @param string $schema The slug the resolver matches.
	 * @param bool   $broken Whether the read throws.
	 */
	private function listener(string $schema, bool $broken): BbvAllocationValidationListener {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$current = '';
		$objectService->method('setRegister')->willReturnCallback(function ($register) use ($objectService, $broken) {
			$this->calls[] = ['setRegister' => $register];
			if ($broken === true) {
				throw new RuntimeException('down');
			}
			return $objectService;
		});
		$objectService->method('setSchema')->willReturnCallback(function ($slug) use ($objectService, &$current) {
			$this->calls[] = ['setSchema' => $slug];
			$current = $slug;
			return $objectService;
		});
		$objectService->method('findAll')->willReturnCallback(function (...$args) use (&$current) {
			$this->calls[] = ['findAll' => $args];
			$filters = $args[0]['filters'] ?? [];
			$rows = array_values(array_filter(self::ROWS[$current] ?? [], static function (array $row) use ($filters): bool {
				foreach ($filters as $key => $value) {
					if (($row[$key] ?? null) != $value) {
						return false;
					}
				}
				return true;
			}));
			if ($current === 'BBVProgramme' && ($filters['programmeCode'] ?? '') === 'P09') {
				$rows[] = 'not a row';
				$rows[] = new class(['id' => 'obj', 'programmeCode' => 'X']) implements \JsonSerializable {
					public function __construct(private array $data) {
					}
					public function jsonSerialize(): array {
						return $this->data;
					}
				};
			}
			return $rows;
		});

		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturnCallback(static fn ($e, string $expectedSlug): bool => $expectedSlug === $schema);
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq-register');
		$logger = $this->createMock(LoggerInterface::class);

		$params = (new ReflectionClass(BbvAllocationValidationListener::class))->getConstructor()->getParameters();
		$args = [];
		foreach ($params as $param) {
			$type = (string)$param->getType();
			$args[] = match ($type) {
				ListenerSchemaResolver::class, ListenerSchemaResolverInterface::class => $resolver,
				RegisterLocation::class, RegisterLocationInterface::class => $settings,
				LoggerInterface::class => $logger,
				ContainerInterface::class => $this->container($objectService),
				ObjectServiceInterface::class => $objectService,
			};
		}

		return new BbvAllocationValidationListener(...$args);
	}

	/**
	 * A container that hands out the object service by its OpenRegister name.
	 *
	 * @param ObjectServiceInterface $objectService The service.
	 */
	private function container(ObjectServiceInterface $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($objectService) {
			$this->calls[] = ['container' => $id];
			return $objectService;
		});
		return $container;
	}

	/**
	 * The cases: schema, event kind, uuid, data, broken read.
	 *
	 * @return array<int, array{0: string, 1: string, 2: ?string, 3: array<string, mixed>, 4: bool}>
	 */
	private static function cases(): array {
		$cases = [];
		$programmes = [
			['administrationId' => 'A', 'fiscalYear' => 2026, 'programmeCode' => 'P01'],
			['administrationId' => 'A', 'fiscalYear' => 2026, 'programmeCode' => '  P01  '],
			['administrationId' => 'A', 'fiscalYear' => 2027, 'programmeCode' => 'P01'],
			['administrationId' => 'B', 'fiscalYear' => 2025, 'programmeCode' => '1.2'],
			['administrationId' => '', 'fiscalYear' => null, 'programmeCode' => 'P01'],
			['administrationId' => 'A', 'fiscalYear' => 2026, 'programmeCode' => ''],
			['administrationId' => 'A', 'fiscalYear' => 2026],
			['id' => 'p1', 'administrationId' => 'A', 'fiscalYear' => 2026, 'programmeCode' => 'P01'],
			['administrationId' => 'A', 'fiscalYear' => 2026, 'programmeCode' => 'P09'],
		];
		$mappings = [
			['administrationId' => 'A', 'glAccountNumber' => '4000', 'fiscalYear' => 2026, 'allocationPercentage' => 10],
			['administrationId' => 'A', 'glAccountNumber' => '4000', 'fiscalYear' => 2026, 'allocationPercentage' => 10.1],
			['administrationId' => 'A', 'glAccountNumber' => '4000', 'fiscalYear' => 2026, 'allocationPercentage' => 10.2],
			['administrationId' => 'A', 'glAccountNumber' => ' 4000 ', 'fiscalYear' => 2026, 'allocationPercentage' => '5'],
			['administrationId' => 'A', 'glAccountNumber' => '4000', 'fiscalYear' => 2027, 'allocationPercentage' => 100.1],
			['administrationId' => 'A', 'glAccountNumber' => '4000', 'fiscalYear' => null, 'allocationPercentage' => 1],
			['administrationId' => 'B', 'glAccountNumber' => '4000', 'fiscalYear' => 2026, 'allocationPercentage' => 1],
			['administrationId' => 'B', 'glAccountNumber' => '5000', 'fiscalYear' => 2025, 'allocationPercentage' => 0.2],
			['administrationId' => 'A', 'glAccountNumber' => '', 'fiscalYear' => 2026, 'allocationPercentage' => 500],
			['administrationId' => 'A', 'glAccountNumber' => '4000', 'fiscalYear' => 2026],
		];
		foreach (['create', 'update'] as $kind) {
			foreach ($programmes as $data) {
				$cases[] = ['BBVProgramme', $kind, $kind === 'update' ? 'p1' : null, $data, false];
			}
			foreach ($mappings as $data) {
				$cases[] = ['BudgetBBVMapping', $kind, $kind === 'update' ? 'm1' : null, $data, false];
			}
		}
		$cases[] = ['BBVProgramme', 'create', null, $programmes[0], true];
		$cases[] = ['BudgetBBVMapping', 'create', null, $mappings[0], true];
		$cases[] = ['Other', 'create', null, $mappings[0], false];
		$cases[] = ['BBVProgramme', 'none', null, $programmes[0], false];
		return $cases;
	}

	/**
	 * Run one case and record the reads and the outcome.
	 *
	 * @param array{0: string, 1: string, 2: ?string, 3: array<string, mixed>, 4: bool} $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		[$schema, $kind, $uuid, $data, $broken] = $case;
		$this->calls = [];
		$entity = new ObjectEntity();
		if ($uuid !== null) {
			$entity->setUuid($uuid);
		}
		$entity->setObject($data);
		$event = match ($kind) {
			'create' => new ObjectCreatingEvent($entity),
			'update' => new ObjectUpdatingEvent($entity, clone $entity),
			'none' => new Event(),
		};
		$this->listener($schema, $broken)->handle($event);
		$reads = array_values(array_filter($this->calls, static fn (array $call): bool => array_key_exists('container', $call) === false));
		return [
			'reads' => $reads,
			'errors' => method_exists($event, 'getErrors') === true ? $event->getErrors() : null,
			'stopped' => $event->isPropagationStopped(),
		];
	}

	public function testListenerReadsAndRefusalsMatchTheRecording(): void {
		$actual = array_map(fn (array $case): array => $this->runCase($case), self::cases());
		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		self::assertCount(count($expected), $actual);
		foreach ($expected as $i => $record) {
			self::assertSame($record, json_decode(json_encode($actual[$i], JSON_PRESERVE_ZERO_FRACTION), true), 'case ' . $i);
		}
	}
}
