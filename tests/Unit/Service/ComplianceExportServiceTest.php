<?php

/**
 * Unit tests for ComplianceExportService's read of the OpenRegister audit trail.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use JsonSerializable;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\Shillinq\Service\ComplianceExportService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The export reads the register's hash-chained audit trail through the typed
 * OpenRegister mappers and keeps only the entries inside the requested window.
 */
final class ComplianceExportServiceTest extends TestCase {

	/**
	 * The export lists the audit-trail entries of the app's register inside
	 * [from, to], with before/after split from the stored changes and PII removed.
	 *
	 * @return void
	 */
	public function testExportReadsTheRegisterAuditTrailInsideTheWindow(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$register = new Register();
		$register->setId(7);
		$registerMapper->expects(self::once())->method('find')->with('shillinq')->willReturn($register);

		$auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$auditTrailMapper->expects(self::once())->method('findEvery')->with(
			self::callback(static fn (ListQuery $query): bool => $query->filters === ['register' => 7])
		)->willReturn(
			[
				$this->entry(created: '2025-12-31T23:00:00+00:00', objectUuid: 'before-window'),
				$this->entry(created: '2026-02-10T10:00:00+00:00', objectUuid: 'inv-1'),
				$this->entry(created: '2026-04-01T00:00:00+00:00', objectUuid: 'after-window'),
			]
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[RegisterMapper::class, $registerMapper],
				[AuditTrailMapper::class, $auditTrailMapper],
			]
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$service = new ComplianceExportService(
			container: $container,
			appConfig: $appConfig,
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$envelope = $service->generateExport(from: '2026-01-01', to: '2026-03-31', format: 'json');

		self::assertSame(1, $envelope['eventCount']);
		$row = $envelope['rows'][0];
		self::assertSame('inv-1', $row['objectId']);
		self::assertSame('update', $row['action']);
		self::assertSame('alice', $row['actor']);
		self::assertSame(['amount'], $row['fields_changed']);
		self::assertSame(['amount' => 100], $row['beforeValue']);
		self::assertSame(['amount' => 120], $row['afterValue']);

	}//end testExportReadsTheRegisterAuditTrailInsideTheWindow()

	/**
	 * One serialisable audit-trail entry as OpenRegister returns it.
	 *
	 * @param string $created    ISO creation instant.
	 * @param string $objectUuid The object the entry is about.
	 *
	 * @return JsonSerializable
	 */
	private function entry(string $created, string $objectUuid): JsonSerializable {
		return new class($created, $objectUuid) implements JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param string $created    ISO creation instant.
			 * @param string $objectUuid The object uuid.
			 */
			public function __construct(
				private readonly string $created,
				private readonly string $objectUuid,
			) {
			}//end __construct()

			/**
			 * The entry's serialised form.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return [
					'id' => 1,
					'objectUuid' => $this->objectUuid,
					'schema' => 3,
					'register' => 7,
					'action' => 'update',
					'user' => 'alice',
					'created' => $this->created,
					'changed' => [
						'amount' => ['old' => 100, 'new' => 120],
						'email' => ['old' => 'a@example.org', 'new' => 'b@example.org'],
					],
				];
			}//end jsonSerialize()
		};

	}//end entry()
}//end class
