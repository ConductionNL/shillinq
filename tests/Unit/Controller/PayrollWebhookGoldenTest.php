<?php

/**
 * Golden tests for PayrollWebhookController::receive with a fed request body.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PayrollWebhookController;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A php:// stream wrapper that answers php://input with a fixed body.
 */
final class PayrollWebhookInputStream {
	/**
	 * The body php://input answers.
	 *
	 * @var string
	 */
	public static string $body = '';

	/**
	 * The stream context (set by PHP).
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * Read position.
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * Open the stream.
	 *
	 * @param string $path The path.
	 *
	 * @return bool
	 */
	public function stream_open(string $path): bool {
		$this->position = 0;
		return $path === 'php://input';
	}//end stream_open()

	/**
	 * Read from the body.
	 *
	 * @param int $count Bytes asked for.
	 *
	 * @return string
	 */
	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}//end stream_read()

	/**
	 * Whether the body is read.
	 *
	 * @return bool
	 */
	public function stream_eof(): bool {
		return $this->position >= strlen(self::$body);
	}//end stream_eof()

	/**
	 * Stat the stream.
	 *
	 * @return array<string, int>
	 */
	public function stream_stat(): array {
		return [];
	}//end stream_stat()
}//end class

/**
 * Pins every answer of the signed payroll webhook and every write and log
 * line of applying an event, before receive() and applyEvent() are split.
 */
final class PayrollWebhookGoldenTest extends TestCase {
	/**
	 * The webhook secret.
	 *
	 * @var string
	 */
	private const SECRET = 's3cret';

	/**
	 * Logged lines.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * The object store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Seed the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryObjectServiceStub(
			[
				'Payroll' => [
					['id' => 'pay-1', 'administrationId' => 'adm-1', 'period' => '2026-03', 'status' => 'draft', 'externalReference' => ''],
					['id' => 'pay-2', 'administrationId' => 'adm-1', 'period' => '2026-04', 'status' => 'calculated', 'externalReference' => 'evt-old'],
				],
			]
		);
	}//end setUp()

	/**
	 * Post a body to receive() with a signature.
	 *
	 * @param string      $body      The raw body.
	 * @param string|null $signature The signature header (null = signed correctly).
	 * @param string      $secret    The configured secret.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private function post(string $body, ?string $signature = null, string $secret = self::SECRET): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($signature ?? hash_hmac('sha256', $body, self::SECRET));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'payroll_webhook_secret' => $secret,
				'register' => '',
				default => $default,
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = $level . ':' . $message . json_encode($context);
				}
			);
		}

		$controller = new PayrollWebhookController(request: $request, appConfig: $appConfig, logger: $logger, objectService: $this->store);

		PayrollWebhookInputStream::$body = $body;
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', PayrollWebhookInputStream::class);
		try {
			$response = $controller->receive();
		} finally {
			stream_wrapper_restore('php');
		}

		return [$response->getStatus(), $response->getData()];
	}//end post()

	/**
	 * Refused bodies: no secret, a bad signature, malformed JSON, a missing id or payrollId, an unknown payroll.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$event = json_encode(['id' => 'evt-1', 'data' => ['payrollId' => 'pay-1']]);
		$bad = [400, ['message' => 'Invalid or missing signature.']];

		$this->assertSame($bad, $this->post(body: $event, secret: ''));
		$this->assertSame($bad, $this->post(body: $event, signature: ''));
		$this->assertSame($bad, $this->post(body: $event, signature: 'nope'));
		$this->assertSame(
			array_fill(0, 3, 'warning:Shillinq payroll webhook: signature verification failed — rejected.[]'),
			$this->logged
		);

		$this->assertSame([400, ['message' => 'Malformed request body.']], $this->post(body: '{"id":'));
		$this->assertSame([400, ['message' => 'Malformed request body.']], $this->post(body: '"text"'));
		$missing = [400, ['message' => 'Missing CloudEvent id or payrollId.']];
		$this->assertSame($missing, $this->post(body: json_encode(['data' => ['payrollId' => 'pay-1']])));
		$this->assertSame($missing, $this->post(body: json_encode(['id' => 'evt-1', 'data' => 'pay-1'])));
		$this->assertSame([422, ['message' => 'Unknown payroll.']], $this->post(body: json_encode(['id' => 'evt-1', 'data' => ['payrollId' => 'pay-9']])));
		$this->assertSame([], $this->store->saved);
	}//end testRefusals()

	/**
	 * A replayed event changes nothing.
	 *
	 * @return void
	 */
	public function testReplayIsANoOp(): void {
		$this->assertSame(
			[200, ['payrollId' => 'pay-2', 'status' => 'calculated', 'replayed' => true]],
			$this->post(body: json_encode(['id' => 'evt-old', 'data' => ['payrollId' => 'pay-2']]))
		);
		$this->assertSame([], $this->store->saved);
		$this->assertSame([], $this->logged);
	}//end testReplayIsANoOp()

	/**
	 * Applying an event writes each deduction, then the calculated payroll, and logs the count.
	 *
	 * @return void
	 */
	public function testApplyWritesDeductionsThenThePayroll(): void {
		$body = json_encode(
			[
				'id' => 'evt-1',
				'data' => [
					'payrollId' => 'pay-1',
					'deductions' => [
						['deductionType' => 'loonheffing', 'amount' => '120.5', 'rate' => '0.37', 'rateSource' => 'table'],
						'skip-me',
						['deductionName' => 'Pension'],
					],
				],
			]
		);

		$this->assertSame(
			[200, ['payrollId' => 'pay-1', 'status' => 'calculated', 'deductionsCreated' => 2, 'replayed' => false]],
			$this->post(body: $body)
		);

		$this->assertSame(['Deduction', 'Deduction', 'Payroll'], array_column($this->store->saved, 'schema'));
		$deduction = $this->store->saved[0]['object'];
		unset($deduction['id']);
		$this->assertSame(
			[
				'payrollId' => 'pay-1',
				'deductionType' => 'loonheffing',
				'deductionName' => 'loonheffing',
				'amount' => 120.5,
				'rate' => 0.37,
				'rateSource' => 'table',
				'taxYear' => 2026,
				'administrationId' => 'adm-1',
			],
			$deduction
		);
		$second = $this->store->saved[1]['object'];
		$this->assertSame(['other', 'Pension', 0.0, null, ''], [$second['deductionType'], $second['deductionName'], $second['amount'], $second['rate'], $second['rateSource']]);
		$payroll = $this->store->saved[2]['object'];
		$this->assertSame(['calculated', 'evt-1', 'pay-1'], [$payroll['status'], $payroll['externalReference'], $payroll['id'] ?? ($payroll['@self']['id'] ?? null)]);
		$this->assertSame(
			['info:Shillinq payroll webhook: applied external deductions.{"eventId":"evt-1","payrollId":"pay-1","deductionsCreated":2}'],
			$this->logged
		);
	}//end testApplyWritesDeductionsThenThePayroll()
	/**
	 * A failing write is logged and answered 500.
	 *
	 * @return void
	 */
	public function testFailureIsLogged500(): void {
		$this->store = new class(['Payroll' => [['id' => 'pay-1', 'period' => '2026-03']]]) extends InMemoryObjectServiceStub {
			/**
			 * Refuse every write.
			 *
			 * @param array<string,mixed> $object The object (the other arguments mirror the stub).
			 *
			 * @return \OCA\OpenRegister\Contract\ObjectEntityInterface Never returns.
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
				?array $uploadedFiles = null,
				?\OCP\IUser $currentUser = null,
				bool $failIfExists = false
			): \OCA\OpenRegister\Contract\ObjectEntityInterface {
				throw new \RuntimeException('disk full');
			}//end saveObject()
		};

		$this->assertSame(
			[500, ['message' => 'Webhook processing failed.']],
			$this->post(body: json_encode(['id' => 'evt-1', 'data' => ['payrollId' => 'pay-1']]))
		);
		$this->assertSame(['error:Shillinq payroll webhook: processing failed.{"eventId":"evt-1","exception":"disk full"}'], $this->logged);
	}//end testFailureIsLogged500()
}//end class
