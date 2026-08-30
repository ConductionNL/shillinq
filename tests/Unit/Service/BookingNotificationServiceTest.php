<?php

/**
 * Unit tests for BookingNotificationService.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-16
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\BookingNotificationService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BookingNotificationService.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-16
 */
class BookingNotificationServiceTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service under test.
	 *
	 * @var BookingNotificationService
	 */
	private BookingNotificationService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->appConfig
			->method('getValueString')
			->willReturn('shillinq');

		$this->service = new BookingNotificationService(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end setUp()

	/**
	 * Rebuild the service over a given ObjectService.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so a test
	 * that needs a seeded — or a refusing — store has to rebuild the subject
	 * rather than re-point the container. The container stays: it still
	 * resolves `IClientService` for the Openconnector send path.
	 *
	 * @param ObjectServiceInterface $objectService The object service to inject.
	 *
	 * @return void
	 */
	private function rebuildServiceWith(ObjectServiceInterface $objectService): void {
		$this->service = new BookingNotificationService(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $objectService,
		);
	}//end rebuildServiceWith()

	/**
	 * An ObjectService that refuses every call the app makes on it.
	 *
	 * Models "OpenRegister is unavailable" — the condition the fail-open
	 * paths are asserted against, which used to be injected by making the
	 * container throw on get().
	 *
	 * @param string $message The refusal message.
	 *
	 * @return ObjectServiceInterface
	 */
	private function refusingObjectService(string $message): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		foreach (['setRegister', 'setSchema', 'findAll', 'find', 'saveObject'] as $method) {
			$objectService->method($method)->willThrowException(new \RuntimeException($message));
		}

		return $objectService;
	}//end refusingObjectService()

	/**
	 * Template renders booking variables correctly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
	 */
	public function testRenderTemplateSubstitutesBookingVariables(): void {
		$template = [
			'subject' => 'Boeking {{ booking.organizer }} - {{ booking.startTime | date(\'d M Y\') }}',
			'body' => 'Hallo {{ booking.guestName }}, bevestigd.',
		];

		$booking = [
			'organizer' => 'Tandarts Jansen',
			'startTime' => '2026-06-15T10:30:00Z',
			'guestName' => 'Alice',
		];

		$rendered = $this->service->renderTemplate(
			template: $template,
			booking: $booking,
			recipient: []
		);

		static::assertSame(expected: 'Boeking Tandarts Jansen - 15 Jun 2026', actual: $rendered['subject']);
		static::assertSame(expected: 'Hallo Alice, bevestigd.', actual: $rendered['body']);
	}//end testRenderTemplateSubstitutesBookingVariables()

	/**
	 * Missing template variables render as empty string, not error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
	 */
	public function testRenderTemplateMissingVariableRendersEmpty(): void {
		$template = [
			'subject' => 'Locatie: {{ booking.location }}',
			'body' => '{{ booking.missing }}',
		];

		$rendered = $this->service->renderTemplate(
			template: $template,
			booking: ['organizer' => 'Test'],
			recipient: []
		);

		static::assertSame(expected: 'Locatie: ', actual: $rendered['subject']);
		static::assertSame(expected: '', actual: $rendered['body']);
	}//end testRenderTemplateMissingVariableRendersEmpty()

	/**
	 * Recipient variables are substituted from the recipient array.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
	 */
	public function testRenderTemplateSubstitutesRecipientVariables(): void {
		$template = [
			'subject' => 'For {{ recipient.name }}',
			'body' => 'Email: {{ recipient.email }}',
		];

		$rendered = $this->service->renderTemplate(
			template: $template,
			booking: [],
			recipient: ['name' => 'Bob', 'email' => 'bob@example.com']
		);

		static::assertSame(expected: 'For Bob', actual: $rendered['subject']);
		static::assertSame(expected: 'Email: bob@example.com', actual: $rendered['body']);
	}//end testRenderTemplateSubstitutesRecipientVariables()

	/**
	 * System variable appName substitutes correctly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
	 */
	public function testRenderTemplateSystemAppName(): void {
		$template = ['subject' => '{{ system.appName }}', 'body' => ''];

		$rendered = $this->service->renderTemplate(
			template: $template,
			booking: [],
			recipient: []
		);

		static::assertSame(expected: 'Bookings', actual: $rendered['subject']);
	}//end testRenderTemplateSystemAppName()

	/**
	 * Condition "true" always evaluates to true.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateConditionTrueReturnsTrue(): void {
		$result = $this->service->evaluateCondition(condition: 'true', booking: []);
		static::assertTrue(condition: $result);
	}//end testEvaluateConditionTrueReturnsTrue()

	/**
	 * Empty condition defaults to true.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateConditionEmptyReturnsTrue(): void {
		$result = $this->service->evaluateCondition(condition: '', booking: []);
		static::assertTrue(condition: $result);
	}//end testEvaluateConditionEmptyReturnsTrue()

	/**
	 * Condition "false" evaluates to false.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateConditionFalseReturnsFalse(): void {
		$result = $this->service->evaluateCondition(condition: 'false', booking: []);
		static::assertFalse(condition: $result);
	}//end testEvaluateConditionFalseReturnsFalse()

	/**
	 * String equality condition evaluates correctly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateConditionStringEquality(): void {
		$booking = ['status' => 'confirmed'];

		$trueResult = $this->service->evaluateCondition(
			condition: "booking.status == 'confirmed'",
			booking: $booking
		);
		static::assertTrue(condition: $trueResult);

		$falseResult = $this->service->evaluateCondition(
			condition: "booking.status == 'pending'",
			booking: $booking
		);
		static::assertFalse(condition: $falseResult);
	}//end testEvaluateConditionStringEquality()

	/**
	 * Numeric greater-than condition evaluates correctly for price thresholds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateConditionNumericGreaterThan(): void {
		$trueResult = $this->service->evaluateCondition(
			condition: 'booking.price > 100',
			booking: ['price' => 150]
		);
		static::assertTrue(condition: $trueResult);

		$falseResult = $this->service->evaluateCondition(
			condition: 'booking.price > 100',
			booking: ['price' => 50]
		);
		static::assertFalse(condition: $falseResult);
	}//end testEvaluateConditionNumericGreaterThan()

	/**
	 * Conditional recipient rule is skipped when condition is false.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateRecipientRulesSkipsWhenConditionFalse(): void {
		$rules = [
			['role' => 'admin_group', 'channels' => ['email'], 'condition' => 'booking.price > 100'],
		];

		$matched = $this->service->evaluateRecipientRules(rules: $rules, booking: ['price' => 50]);

		static::assertCount(expectedCount: 0, haystack: $matched);
	}//end testEvaluateRecipientRulesSkipsWhenConditionFalse()

	/**
	 * Recipient rule is included when condition is true.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function testEvaluateRecipientRulesIncludesWhenConditionTrue(): void {
		$rules = [
			['role' => 'customer', 'channels' => ['email'], 'condition' => 'true'],
			['role' => 'organizer', 'channels' => ['email'], 'condition' => "booking.status == 'confirmed'"],
		];

		$matched = $this->service->evaluateRecipientRules(rules: $rules, booking: ['status' => 'confirmed']);

		static::assertCount(expectedCount: 2, haystack: $matched);
	}//end testEvaluateRecipientRulesIncludesWhenConditionTrue()

	/**
	 * IsRateLimited returns false when object service throws (fail-open).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
	 */
	public function testIsRateLimitedReturnsFalseOnServiceError(): void {
		$this->rebuildServiceWith($this->refusingObjectService('Service unavailable'));

		$this->logger
			->expects(static::once())
			->method('warning');

		$result = $this->service->isRateLimited(
			bookingId: 'booking-123',
			organizerId: 'user-1'
		);

		static::assertFalse(condition: $result);
	}//end testIsRateLimitedReturnsFalseOnServiceError()

	/**
	 * IsRateLimited returns false for empty IDs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
	 */
	public function testIsRateLimitedReturnsFalseForEmptyIds(): void {
		$result = $this->service->isRateLimited(bookingId: '', organizerId: '');
		static::assertFalse(condition: $result);
	}//end testIsRateLimitedReturnsFalseForEmptyIds()

	/**
	 * IsDuplicate returns false when object service throws (fail-open).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
	 */
	public function testIsDuplicateReturnsFalseOnServiceError(): void {
		$this->rebuildServiceWith($this->refusingObjectService('Service unavailable'));

		$this->logger
			->expects(static::once())
			->method('warning');

		$result = $this->service->isDuplicate(
			bookingId: 'booking-123',
			recipient: 'alice@example.com',
			triggerType: 'created'
		);

		static::assertFalse(condition: $result);
	}//end testIsDuplicateReturnsFalseOnServiceError()

	/**
	 * IsOptedOut returns false when object service throws (fail-open).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-13
	 */
	public function testIsOptedOutReturnsFalseOnServiceError(): void {
		$this->rebuildServiceWith($this->refusingObjectService('Service unavailable'));

		$this->logger
			->expects(static::once())
			->method('warning');

		$result = $this->service->isOptedOut(
			recipient: 'alice@example.com',
			triggerType: 'reminder'
		);

		static::assertFalse(condition: $result);
	}//end testIsOptedOutReturnsFalseOnServiceError()

	/**
	 * IsOptedOut returns false when no opt-out record exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-13
	 */
	public function testIsOptedOutReturnsFalseWhenNoRecord(): void {
		// phpcs:disable
		$objectService = new class {
			public function setRegister(string $register): static {
				return $this;
			}
			public function setSchema(string $schema): static {
				return $this;
			}
			public function findAll(array $config = []): array {
				return [];
			}
		};
		// phpcs:enable
		$this->rebuildServiceWith(new DuckObjectServiceAdapter($objectService));

		$result = $this->service->isOptedOut(
			recipient: 'alice@example.com',
			triggerType: 'created'
		);

		static::assertFalse(condition: $result);
	}//end testIsOptedOutReturnsFalseWhenNoRecord()

	/**
	 * SendViaOpenconnector returns false when HTTP client throws.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-5
	 */
	public function testSendViaOpenconnectorReturnsFalseOnException(): void {
		// phpcs:disable CustomSn.Functions.NamedParameters
		$clientService = $this->getMockBuilder(className: \stdClass::class)->addMethods(['newClient'])->getMock();
		$this->container->method('get')->willReturn($clientService);
		// phpcs:enable CustomSn.Functions.NamedParameters
		$clientService->method('newClient')->willThrowException(new \RuntimeException('timeout'));

		$this->logger->expects(static::once())->method('warning');

		$result = $this->service->sendViaOpenconnector(
			channel: 'email',
			recipient: 'alice@example.com',
			subject: 'Test',
			body: 'Body',
			templateId: 'tmpl-1'
		);

		static::assertFalse(condition: $result);
	}//end testSendViaOpenconnectorReturnsFalseOnException()

	/**
	 * RecordAuditTrail logs error and continues when object service throws.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-8
	 */
	public function testRecordAuditTrailLogsErrorOnServiceThrow(): void {
		$this->rebuildServiceWith($this->refusingObjectService('DB unavailable'));

		$this->logger
			->expects(static::once())
			->method('error');

		// Must not throw.
		$this->service->recordAuditTrail(
			notification: [
				'triggerName' => 'Test',
				'triggerType' => 'created',
				'bookingId' => 'b-1',
				'recipient' => 'alice@example.com',
				'channel' => 'email',
				'templateName' => 'Test Template',
			],
			status: 'sent',
			reason: ''
		);
	}//end testRecordAuditTrailLogsErrorOnServiceThrow()
}//end class
