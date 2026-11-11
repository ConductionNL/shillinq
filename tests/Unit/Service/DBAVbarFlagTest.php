<?php

/**
 * Characterisation tests for DBAVbarMonitorService::emitFlag().
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\DBAVbarMonitorService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the flag payload, the open-flag idempotency check (array and entity
 * rows), and every degraded path of emitFlag() with its log.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class DBAVbarFlagTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the service around an object service double (or a throwing container).
	 *
	 * @param object|null $objectService The double, or null for an unavailable OpenRegister.
	 * @param string      $register      The configured register slug.
	 *
	 * @return DBAVbarMonitorService
	 */
	private function service(?object $objectService, string $register): DBAVbarMonitorService {
		$container = $this->createMock(ContainerInterface::class);
		if ($objectService === null) {
			$container->method('get')->willThrowException(new RuntimeException('no OR'));
		} else {
			$container->method('get')->willReturn($objectService);
		}

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($register);
		$this->logger = $this->createMock(LoggerInterface::class);
		return new DBAVbarMonitorService(container: $container, appConfig: $appConfig, logger: $this->logger);

	}//end service()

	/**
	 * An object service double serving the given open flags.
	 *
	 * @param array<int,mixed> $openFlags Rows findAll returns.
	 * @param string           $failOn    '', 'find' or 'save'.
	 *
	 * @return object
	 */
	private function store(array $openFlags, string $failOn): object {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		return new class($openFlags, $failOn) {
			/** @var array<int,array<string,mixed>> */
			public array $calls = [];

			public function __construct(private array $openFlags, private string $failOn) {
			}

			public function setRegister(string $register): static {
				$this->calls[] = ['register', $register];
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			public function findAll(array $args): array {
				$this->calls[] = ['findAll', $args];
				if ($this->failOn === 'find') {
					throw new RuntimeException('find down');
				}

				return $this->openFlags;
			}

			public function saveObject(array $object): array {
				$this->calls[] = ['save', $object];
				if ($this->failOn === 'save') {
					throw new RuntimeException('save down');
				}

				return $object;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

	}//end store()

	/**
	 * A new flag: the open-flag query, then the full payload.
	 *
	 * @return void
	 */
	public function testWritesTheFlag(): void {
		$entity = new class {
			/**
			 * The entity's object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return ['details' => ['invoiceId' => 'inv-other']];
			}
		};
		$store = $this->store([['details' => []], $entity, 'x'], '');

		self::assertTrue($this->service($store, '')->emitFlag('opd-1', 'adm-1', 'inv-1', 4500, 3500));

		self::assertSame(['register', 'shillinq'], $store->calls[0]);
		self::assertSame(
			[
				'findAll',
				['filters' => ['assignmentId' => 'opd-1', 'type' => 'VBAR_GRENS_BELOW_THRESHOLD', 'status' => 'OPEN'], 'limit' => 10],
			],
			$store->calls[1]
		);
		$saved = $store->calls[3][1];
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $saved['detectionMoment']);
		unset($saved['detectionMoment']);
		self::assertSame(
			[
				'administrationId' => 'adm-1',
				'assignmentId' => 'opd-1',
				'type' => 'VBAR_GRENS_BELOW_THRESHOLD',
				'severity' => 'MEDIUM',
				'details' => ['invoiceId' => 'inv-1', 'uurtariefCents' => 4500, 'vbarGrensCents' => 3500, 'peiljaar' => 2024],
				'fiscalSource' => 'REQ-DBA-016; VBAR-wetsvoorstel uurtariefgrens (peil 2024)',
				'actionSuggestion' => 'Verhoog het uurtarief of leg een schriftelijke onderbouwing vast (motivatie EUR-grens uitzondering).',
				'status' => 'OPEN',
				'displayedInUser' => true,
			],
			$saved
		);

	}//end testWritesTheFlag()

	/**
	 * An open flag for the same invoice (array or entity row) blocks a second.
	 *
	 * @return void
	 */
	public function testOpenFlagForTheInvoiceIsNotRepeated(): void {
		$entity = new class {
			/**
			 * The entity's object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return ['details' => ['invoiceId' => 'inv-1']];
			}
		};
		$byEntity = $this->store([$entity], '');
		self::assertFalse($this->service($byEntity, 'reg')->emitFlag('opd-1', 'adm-1', 'inv-1', 1, 2));
		self::assertSame(['register', 'reg'], $byEntity->calls[0]);
		self::assertCount(2, $byEntity->calls);

		$byArray = $this->store([['details' => ['invoiceId' => 'inv-1']]], '');
		self::assertFalse($this->service($byArray, 'reg')->emitFlag('opd-1', 'adm-1', 'inv-1', 1, 2));

	}//end testOpenFlagForTheInvoiceIsNotRepeated()

	/**
	 * Unavailable OpenRegister, a failing idempotency check and a failing
	 * save each log and degrade.
	 *
	 * @return void
	 */
	public function testDegradedPaths(): void {
		$service = $this->service(null, '');
		$this->logger->expects(self::once())->method('warning')->with(
			'DBAVbarMonitorService: OpenRegister not available, skipping flag.',
			['exception' => 'no OR']
		);
		self::assertFalse($service->emitFlag('opd-1', 'adm-1', 'inv-1', 1, 2));

		$findFails = $this->store([], 'find');
		$service = $this->service($findFails, '');
		$this->logger->expects(self::once())->method('warning')->with(
			'DBAVbarMonitorService: idempotency check failed.',
			['exception' => 'find down']
		);
		self::assertTrue($service->emitFlag('opd-1', 'adm-1', 'inv-1', 1, 2));

		$service = $this->service($this->store([], 'save'), '');
		$this->logger->expects(self::once())->method('error')->with(
			'DBAVbarMonitorService: failed to write VBAR flag.',
			['exception' => 'save down']
		);
		self::assertFalse($service->emitFlag('opd-1', 'adm-1', 'inv-1', 1, 2));

	}//end testDegradedPaths()
}//end class
