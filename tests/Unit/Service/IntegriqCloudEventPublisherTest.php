<?php

/**
 * Unit tests for IntegriqCloudEventPublisher: whether integriq is there, and
 * the CloudEvent object it saves in integriq's event register.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTime;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\IntegriqCloudEventPublisher;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Shillinq\Service\IntegriqCloudEventPublisher
 */
final class IntegriqCloudEventPublisherTest extends TestCase {

	private IAppManager&MockObject $apps;

	private ObjectServiceInterface&MockObject $objects;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->apps    = $this->createMock(IAppManager::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}

	private function publisher(): IntegriqCloudEventPublisher {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-01T09:30:00+00:00'));
		return new IntegriqCloudEventPublisher($this->apps, $this->objects, $time, $this->logger);
	}

	public function testIntegriqIsAvailableOnlyWhenInstalled(): void {
		$this->apps->expects(self::exactly(2))->method('isInstalled')->with('integriq')->willReturnOnConsecutiveCalls(true, false);

		self::assertTrue($this->publisher()->isAvailable());
		self::assertFalse($this->publisher()->isAvailable());
	}

	public function testAnAppManagerThatFailsReadsAsUnavailable(): void {
		$this->apps->method('isInstalled')->willThrowException(new RuntimeException('app store down'));
		$this->logger->expects(self::once())->method('warning')
			->with('IntegriqCloudEventPublisher: could not tell whether integriq is installed', ['exception' => 'app store down']);

		self::assertFalse($this->publisher()->isAvailable());
	}

	public function testTheEventIsSavedInIntegriqsEventRegister(): void {
		$this->objects->expects(self::once())->method('setRegister')->with('integriq')->willReturnSelf();
		$this->objects->expects(self::once())->method('setSchema')->with('event')->willReturnSelf();
		$saved = null;
		$this->objects->expects(self::once())->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntityInterface {
				$saved = $object;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$ok = $this->publisher()->publish('nl.conduction.peppol.outbound.requested', '/apps/shillinq/ar-invoices', 'inv-1', ['objectUri' => 'openregister://shillinq/ARInvoice/inv-1']);

		self::assertTrue($ok);
		self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $saved['id']);
		unset($saved['id']);
		self::assertSame(
			[
				'specversion' => '1.0',
				'type' => 'nl.conduction.peppol.outbound.requested',
				'source' => '/apps/shillinq/ar-invoices',
				'subject' => 'inv-1',
				'time' => '2026-10-01T09:30:00+00:00',
				'datacontenttype' => 'application/json',
				'data' => ['objectUri' => 'openregister://shillinq/ARInvoice/inv-1'],
			],
			$saved
		);
	}

	public function testAFailedSaveIsLoggedAndReported(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('no such register'));
		$this->logger->expects(self::once())->method('error')->with(
			'IntegriqCloudEventPublisher: failed to save the CloudEvent in integriq\'s register',
			['type' => 't', 'subject' => 's', 'exception' => 'no such register']
		);

		self::assertFalse($this->publisher()->publish('t', '/src', 's', []));
	}
}
