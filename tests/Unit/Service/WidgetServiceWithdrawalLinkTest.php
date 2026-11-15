<?php

/**
 * Unit tests for the withdrawal link in the widget's booking answer.
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\SlotService;
use OCA\Shillinq\Service\WidgetService;
use OCA\Shillinq\Service\WithdrawalService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The booking answer carries the signed guest link to withdraw (REQ-SCX-004),
 * built from the saved appointment, and a booking never fails for it.
 */
#[CoversClass(WidgetService::class)]
class WidgetServiceWithdrawalLinkTest extends TestCase {

	private const LINK = ['url' => 'https://portal.example.org/herroepen?token=abc', 'periodEndsOn' => '2026-06-05'];

	/**
	 * A widget service over an object service that saves `saved`.
	 *
	 * @param WithdrawalService|null $withdrawals The withdrawal service, or none.
	 * @param LoggerInterface|null   $logger      The logger.
	 * @param array<string,mixed>    $saved       What the save answers.
	 *
	 * @return WidgetService
	 */
	private function widget(?WithdrawalService $withdrawals, ?LoggerInterface $logger = null, array $saved = ['appointmentId' => 'apt-new', 'status' => 'pending_confirmation', '@self' => ['created' => '2026-05-22T07:00:00+00:00']]): WidgetService {
		$service = [
			'@self' => ['slug' => 'haircut'],
			'serviceId' => 'svc-001',
			'isPublic' => true,
			'status' => 'active',
			'duration' => 45,
			'resourceId' => 'res-001',
			'administrationId' => 'salon-demo',
		];
		$objects = new class($service, $saved) {
			private string $schema = '';

			/**
			 * @param array<string,mixed> $service The published service.
			 * @param array<string,mixed> $saved   The saved appointment.
			 */
			public function __construct(private array $service, private array $saved) {
			}//end __construct()

			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $config The query.
			 * @param bool                $_rbac  Unused.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true): array {
				if ($this->schema === 'Service') {
					return [$this->service];
				}

				return [];
			}//end findAll()

			/**
			 * @param array<string,mixed> $object   The appointment.
			 * @param string              $register Unused.
			 * @param string              $schema   Unused.
			 * @param bool                $_rbac    Unused.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = '', bool $_rbac = true): array {
				return $this->saved;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$slots = $this->createMock(SlotService::class);
		$slots->method('getAvailableSlots')->willReturn(['slots' => [['startTime' => '2026-05-22T08:00:00Z', 'endTime' => '2026-05-22T08:45:00Z']]]);

		return new WidgetService(
			container: $container,
			appConfig: $appConfig,
			slotService: $slots,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			withdrawals: $withdrawals,
		);
	}//end widget()

	/**
	 * The booking request.
	 *
	 * @return array<string,mixed>
	 */
	private function booking(): array {
		return [
			'serviceId' => 'svc-001',
			'resourceId' => 'res-001',
			'startTime' => '2026-05-22T08:00:00Z',
			'endTime' => '2026-05-22T08:45:00Z',
			'customerName' => 'Alice Smith',
			'customerEmail' => 'alice@example.com',
		];
	}//end booking()

	public function testTheAnswerCarriesTheLinkForTheSavedAppointment(): void {
		$withdrawals = $this->createMock(WithdrawalService::class);
		$withdrawals->expects(self::once())->method('guestLinkFor')->with(
			self::callback(static fn (array $appointment): bool => $appointment['appointmentId'] === 'apt-new'
				&& $appointment['@self']['created'] === '2026-05-22T07:00:00+00:00'
				&& $appointment['serviceId'] === 'svc-001'
				&& $appointment['startTime'] === '2026-05-22T08:00:00Z'),
			self::isInstanceOf(DateTimeImmutable::class)
		)->willReturn(self::LINK);

		$result = $this->widget(withdrawals: $withdrawals)->createAppointment('salon-demo', $this->booking());

		self::assertSame(201, $result['code']);
		self::assertSame('apt-new', $result['appointmentId']);
		self::assertSame(self::LINK, $result['withdrawal']);
	}//end testTheAnswerCarriesTheLinkForTheSavedAppointment()

	public function testABookingThatCannotBeWithdrawnCarriesNoLink(): void {
		$withdrawals = $this->createMock(WithdrawalService::class);
		$withdrawals->method('guestLinkFor')->willReturn(null);

		$result = $this->widget(withdrawals: $withdrawals)->createAppointment('salon-demo', $this->booking());

		self::assertSame(201, $result['code']);
		self::assertArrayNotHasKey('withdrawal', $result);
	}//end testABookingThatCannotBeWithdrawnCarriesNoLink()

	public function testWithoutAWithdrawalServiceTheAnswerIsUnchanged(): void {
		$result = $this->widget(withdrawals: null)->createAppointment('salon-demo', $this->booking());

		self::assertSame(
			['code', 'appointmentId', 'status', 'startTime', 'endTime', 'confirmationMessage'],
			array_keys($result)
		);
	}//end testWithoutAWithdrawalServiceTheAnswerIsUnchanged()

	public function testAFailingLinkIsLoggedAndTheBookingStillSucceeds(): void {
		$withdrawals = $this->createMock(WithdrawalService::class);
		$withdrawals->method('guestLinkFor')->willThrowException(new RuntimeException('signer down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'WidgetService: no withdrawal link for the confirmation step',
			['exception' => 'signer down']
		);

		$result = $this->widget(withdrawals: $withdrawals, logger: $logger)->createAppointment('salon-demo', $this->booking());

		self::assertSame(201, $result['code']);
		self::assertArrayNotHasKey('withdrawal', $result);
	}//end testAFailingLinkIsLoggedAndTheBookingStillSucceeds()
}//end class
