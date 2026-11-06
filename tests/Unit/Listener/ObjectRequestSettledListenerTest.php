<?php

/**
 * Unit tests for ObjectRequestSettledListener and ObjectRequestReceiptMailer.
 *
 * The listener is driven with OpenRegister's real ObjectUpdatedEvent and
 * ObjectEntity, and the real mailer class sends through an IMailer double,
 * so the wiring between the two is what is tested.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\ObjectRequestSettledListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\ObjectRequestReceiptMailer;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One receipt mail per settled object request, on every settlement path.
 */
class ObjectRequestSettledListenerTest extends TestCase {
	/**
	 * The mailer double.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer $mailer;

	/**
	 * The message double.
	 *
	 * @var IMessage&MockObject
	 */
	private IMessage $message;

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * Every patch written, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $patches = [];

	/**
	 * The plain-text bodies sent, in order.
	 *
	 * @var array<int, string>
	 */
	private array $bodies = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->message = $this->createMock(IMessage::class);
		$this->message->method('setPlainBody')->willReturnCallback(
			function (string $text): IMessage {
				$this->bodies[] = $text;
				return $this->message;
			}
		);
		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createMessage')->willReturn($this->message);
		$this->mailer->method('validateMailAddress')->willReturnCallback(
			static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
		);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data, $register = null, $schema = null): ObjectEntity {
				$this->patches[] = ['id' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema];
				return new ObjectEntity();
			}
		);
	}//end setUp()

	/**
	 * The listener with the real mailer.
	 *
	 * @param LoggerInterface|null $logger A logger double, when the test reads it.
	 *
	 * @return ObjectRequestSettledListener The listener.
	 */
	private function listener(?LoggerInterface $logger = null): ObjectRequestSettledListener {
		$logger = ($logger ?? $this->createStub(LoggerInterface::class));
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('findGenericLanguage')->willReturn('nl');
		$factory->expects(self::any())->method('get')->with('shillinq', 'nl')->willReturn($l10n);

		$resolver = $this->createStub(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturnCallback(static fn (?object $entity): string => (string)$entity?->getSchema());
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new ObjectRequestSettledListener(
			receiptMailer: new ObjectRequestReceiptMailer(mailer: $this->mailer, l10nFactory: $factory, logger: $logger),
			schemaResolver: $resolver,
			objectService: $this->objectService,
			settings: $settings,
			logger: $logger,
		);
	}//end listener()

	/**
	 * A pending object request with a debtor email, as larpinq raises it.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function request(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'pr-1',
				'subjectKind' => 'object',
				'subject' => ['type' => 'registration', 'register' => 'larpinq', 'schema' => 'Registration', 'id' => 'reg-42'],
				'state' => 'pending',
				'requestType' => 'event-fee',
				'amount' => 85.0,
				'currency' => 'EUR',
				'paymentGateway' => 'mollie',
				'description' => 'Winter Camp 2026',
				'paymentReference' => 'WC26-0042',
				'debtor' => ['name' => 'Anna Jansen', 'email' => 'anna@example.nl'],
			],
			$overrides
		);
	}//end request()

	/**
	 * The same request after a capture by the provider.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The captured request.
	 */
	private function captured(array $overrides = []): array {
		return $this->request(
			array_merge(
				[
					'state' => 'captured',
					'capturedAt' => '2026-10-03T09:12:00Z',
					'settledAt' => '2026-10-03T09:12:00Z',
					'settledVia' => 'provider',
					'confirmationSummary' => 'Winter Camp 2026 paid on 2026-10-03, reference tr_123',
				],
				$overrides
			)
		);
	}//end captured()

	/**
	 * An entity as OpenRegister hands it to a listener.
	 *
	 * @param array<string, mixed> $data The payload with its id.
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data, string $schema = 'PaymentRequest'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)$data['id']);
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * Fire the update OpenRegister fires when a request is saved.
	 *
	 * @param array<string, mixed> $new The request after the save.
	 * @param array<string, mixed> $old The request before it.
	 *
	 * @return void
	 */
	private function update(array $new, array $old): void {
		$this->listener()->handle(new ObjectUpdatedEvent($this->entity($new), $this->entity($old)));
	}//end update()

	/**
	 * A capture by the provider mails the debtor once: description, amount,
	 * date, reference and confirmation summary, and records receiptSentAt in
	 * a value the real schema accepts. The update that stamp causes, and a
	 * replayed capture, mail nothing (REQ-ORS-005).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
	 */
	public function testProviderCaptureMailsTheDebtorOnce(): void {
		$this->message->expects(self::once())->method('setTo')->with(['anna@example.nl' => 'Anna Jansen']);
		$this->message->expects(self::once())->method('setSubject')->with('Payment received');
		$this->mailer->expects(self::once())->method('send')->with($this->message)->willReturn([]);

		$this->update($this->captured(), $this->request());

		self::assertCount(1, $this->patches);
		self::assertSame('pr-1', $this->patches[0]['id']);
		self::assertSame('shillinq', $this->patches[0]['register']);
		self::assertSame('PaymentRequest', $this->patches[0]['schema']);
		self::assertSame(['receiptSentAt'], array_keys($this->patches[0]['data']));
		$stamped = array_merge($this->captured(), $this->patches[0]['data']);
		self::assertSame([], RegisterSchema::errors('PaymentRequest', $stamped));

		$body = $this->bodies[0];
		self::assertStringContainsString('Winter Camp 2026', $body);
		self::assertStringContainsString('EUR 85,00', $body);
		self::assertStringContainsString('2026-10-03', $body);
		self::assertStringContainsString('WC26-0042', $body);
		self::assertStringContainsString('reference tr_123', $body);

		// The stamp's own update, then a replayed capture of the same request.
		$this->update($stamped, $this->captured());
		$this->update($stamped, $this->request());
		self::assertCount(1, $this->patches);
	}//end testProviderCaptureMailsTheDebtorOnce()

	/**
	 * Money recorded by hand settles the request without a provider capture,
	 * and the debtor gets the same receipt (REQ-ORS-005).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
	 */
	public function testManualSettlementMails(): void {
		$this->mailer->expects(self::once())->method('send')->willReturn([]);
		$settled = $this->request(
			[
				'settledAt' => '2026-10-04T10:00:00Z',
				'settledVia' => 'manual',
				'settlements' => [['method' => 'cash', 'amount' => 85.0, 'receivedAt' => '2026-10-04T10:00:00Z']],
			]
		);

		$this->update($settled, $this->request());

		self::assertCount(1, $this->patches);
		self::assertStringContainsString('2026-10-04', $this->bodies[0]);
		self::assertStringNotContainsString('tr_123', $this->bodies[0]);
	}//end testManualSettlementMails()

	/**
	 * No debtor email, an address the mailer refuses, a request on an invoice,
	 * or another schema: no mail and no stamp (REQ-ORS-005).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
	 */
	public function testNoEmailNoMail(): void {
		$this->mailer->expects(self::never())->method('send');

		$this->update($this->captured(['debtor' => ['name' => 'Anna Jansen']]), $this->request());
		$this->update($this->captured(['debtor' => ['email' => 'not an address']]), $this->request());
		$this->update($this->captured(['subjectKind' => 'invoice', 'invoiceReference' => 'inv-1']), $this->request(['subjectKind' => 'invoice']));
		$this->listener()->handle(
			new ObjectUpdatedEvent($this->entity($this->captured(), 'DepositPayment'), $this->entity($this->request(), 'DepositPayment'))
		);

		self::assertSame([], $this->patches);
	}//end testNoEmailNoMail()

	/**
	 * A mail server failure is logged and leaves receiptSentAt empty; the
	 * request stays settled (REQ-ORS-005).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
	 */
	public function testAMailFailureLeavesNoStamp(): void {
		$this->mailer->method('send')->willThrowException(new RuntimeException('smtp down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$this->listener(logger: $logger)->handle(new ObjectUpdatedEvent($this->entity($this->captured()), $this->entity($this->request())));

		self::assertSame([], $this->patches);
	}//end testAMailFailureLeavesNoStamp()

	/**
	 * The app wires the listener on ObjectUpdatedEvent (REQ-ORS-005).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-005)
	 */
	public function testTheAppWiresTheListener(): void {
		$root = __DIR__ . '/../../..';
		$application = (string)file_get_contents($root . '/lib/AppInfo/Application.php');
		$reporting = (string)file_get_contents($root . '/lib/AppInfo/ReportingRegistration.php');
		$registration = (string)file_get_contents($root . '/lib/AppInfo/ObjectRequestSettlementRegistration.php');

		self::assertStringContainsString('(new ReportingRegistration())->register(context: $context);', $application);
		self::assertStringContainsString('(new ObjectRequestSettlementRegistration())->register(context: $context);', $reporting);
		self::assertMatchesRegularExpression('/ObjectUpdatedEvent::class,\s*listener: ObjectRequestSettledListener::class/', $registration);
	}//end testTheAppWiresTheListener()
}//end class
