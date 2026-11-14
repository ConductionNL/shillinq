<?php

/**
 * Characterisation tests for BookingDetailController::show().
 *
 * Pins the access steps in order, the history paging passed to pipelinq,
 * and every log line and payload of the contact and history failures.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BookingDetailController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Kernel\Model\KlantbeeldResult;
use OCA\Shillinq\Kernel\Model\PipelinqContact;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Kernel\Exception\PipelinqTransportException;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins BookingDetailController::show().
 */
final class BookingDetailShowCharacterisationTest extends TestCase {

	/**
	 * Pipelinq adapter mock.
	 *
	 * @var PipelinqContactAdapter&MockObject
	 */
	private PipelinqContactAdapter&MockObject $pipelinq;

	/**
	 * Context mock.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Settings mock.
	 *
	 * @var RegisterLocation&MockObject
	 */
	private RegisterLocation&MockObject $settings;

	/**
	 * Request parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * Log lines.
	 *
	 * @var array<int,string>
	 */
	private array $logs = [];

	/**
	 * The appointment lookup answer, or a Throwable.
	 *
	 * @var mixed
	 */
	private mixed $appointments = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->pipelinq = $this->createMock(PipelinqContactAdapter::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->settings = $this->createMock(RegisterLocation::class);
		$this->settings->method('getRegisterSlug')->willReturn('books');
		$this->appointments = [['appointmentId' => 'apt-1', 'administrationId' => 'adm-1', 'pipelinqContactId' => ' c-1 ']];

	}//end setUp()

	/**
	 * Call show().
	 *
	 * @param string $id The route id.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function show(string $id): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default));
		$test = $this;
		$objects = new class($test) {
			/**
			 * Constructor.
			 *
			 * @param BookingDetailShowCharacterisationTest $test The test.
			 */
			public function __construct(private BookingDetailShowCharacterisationTest $test) {
			}

			/**
			 * Scope to a register.
			 *
			 * @param string $r Register.
			 *
			 * @return self
			 */
			public function setRegister(string $r): self {
				$this->test->record('register:' . $r);
				return $this;
			}

			/**
			 * Scope to a schema.
			 *
			 * @param string $s Schema.
			 *
			 * @return self
			 */
			public function setSchema(string $s): self {
				return $this;
			}

			/**
			 * List objects.
			 *
			 * @param array<string,mixed> $query Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $query): array {
				$this->test->record('findAll:' . json_encode($query));
				return $this->test->appointmentsAnswer();
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = $level . '|' . $message . '|' . json_encode($context);
				}
			);
		}

		$response = (new BookingDetailController($request, $container, $this->settings, $this->context, $this->pipelinq, $logger))->show($id);
		return [$response->getStatus(), $response->getData()];

	}//end show()

	/**
	 * Record a collaborator call.
	 *
	 * @param string $call The call.
	 *
	 * @return void
	 */
	public function record(string $call): void {
		$this->logs[] = $call;

	}//end record()

	/**
	 * The appointment lookup answer.
	 *
	 * @return array<int,mixed>
	 */
	public function appointmentsAnswer(): array {
		if ($this->appointments instanceof \Throwable) {
			throw $this->appointments;
		}

		return $this->appointments;

	}//end appointmentsAnswer()

	/**
	 * Allow every access step.
	 *
	 * @return void
	 */
	private function allow(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->context->method('canAccess')->willReturn(true);

	}//end allow()

	/**
	 * The access steps, in order, each refusing before the next is asked.
	 *
	 * @return void
	 */
	public function testAccessSteps(): void {
		$this->context->method('currentUserId')->willReturnOnConsecutiveCalls(null, 'alice', 'alice', 'alice', 'alice', 'alice');
		$this->settings->method('isOpenRegisterAvailable')->willReturnOnConsecutiveCalls(false, true, true, true);
		$this->context->expects(self::once())->method('canAccess')->with('adm-1')->willReturn(false);
		$this->pipelinq->expects(self::never())->method(self::anything());

		self::assertSame([401, ['error' => 'Not authenticated']], $this->show(''));
		self::assertSame([400, ['error' => 'id is required']], $this->show('  '));
		self::assertSame([503, ['error' => 'OpenRegister unavailable']], $this->show('apt-1'));
		$found = $this->appointments;
		$this->appointments = [];
		self::assertSame([404, ['error' => 'Booking not found']], $this->show('apt-1'));
		$this->appointments = new \RuntimeException('db');
		self::assertSame([404, ['error' => 'Booking not found']], $this->show('apt-1'));
		$this->appointments = $found;
		self::assertSame([404, ['error' => 'Booking not found']], $this->show(' apt-1 '));
		self::assertSame(
			[
				'register:books', 'findAll:{"filters":{"appointmentId":"apt-1"},"limit":1}',
				'register:books', 'findAll:{"filters":{"appointmentId":"apt-1"},"limit":1}',
				'error|BookingDetailController: appointment lookup failed|{"app":"shillinq","appointmentId":"apt-1","exception":"db"}',
				'register:books', 'findAll:{"filters":{"appointmentId":"apt-1"},"limit":1}',
			],
			$this->logs
		);

	}//end testAccessSteps()

	/**
	 * Paging parameters and the history they ask for.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:int,2:int}>
	 */
	public static function paging(): array {
		return [
			'defaults' => [[], 5, 0],
			'given' => [['limit' => '12', 'offset' => '3'], 12, 3],
			'not numeric' => [['limit' => 'all', 'offset' => 'x'], 5, 0],
			'negative offset' => [['limit' => '-2', 'offset' => '-4'], -2, 0],
		];

	}//end paging()

	/**
	 * The history is fetched for the trimmed contact with the paging.
	 *
	 * @param array<string,mixed> $params The parameters.
	 * @param int $limit The limit asked.
	 * @param int $offset The offset asked.
	 *
	 * @return void
	 *
	 * @dataProvider paging
	 */
	public function testHistoryPaging(array $params, int $limit, int $offset): void {
		$this->allow();
		$this->params = $params;
		$this->pipelinq->expects(self::once())->method('getContact')->with('c-1')
			->willReturn(new PipelinqContact(externalId: 'c-1', legalName: 'Acme', found: true));
		$this->pipelinq->expects(self::once())->method('fetchKlantbeeld')->with('c-1', $limit, $offset)
			->willReturn(KlantbeeldResult::unavailable(limit: $limit, offset: $offset));

		[$status, $data] = $this->show('apt-1');

		self::assertSame(200, $status);
		self::assertSame(['booking', 'contact', 'klantbeeld', 'contactError', 'notLinkedToPipelinq'], array_keys($data));
		self::assertNull($data['contactError']);

	}//end testHistoryPaging()

	/**
	 * Contact failures: a transport failure is logged as a warning with its
	 * status, anything else as an error; both skip the history.
	 *
	 * @return void
	 */
	public function testContactFailures(): void {
		$this->allow();
		$this->pipelinq->method('getContact')->willReturnOnConsecutiveCalls(
			self::throwValue(new PipelinqTransportException(message: 'down', statusCode: 503)),
			self::throwValue(new \LogicException('bug'))
		);
		$this->pipelinq->expects(self::never())->method('fetchKlantbeeld');

		[, $transport] = $this->show('apt-1');
		[, $other] = $this->show('apt-1');

		self::assertIsString($transport['contactError']);
		self::assertSame('Customer profile temporarily unavailable.', $other['contactError']);
		self::assertNull($other['contact']);
		self::assertSame(
			[
				'register:books', 'findAll:{"filters":{"appointmentId":"apt-1"},"limit":1}',
				'warning|BookingDetailController: pipelinq Contact unavailable|{"app":"shillinq","bookingId":"apt-1","externalId":"c-1","status":503}',
				'register:books', 'findAll:{"filters":{"appointmentId":"apt-1"},"limit":1}',
				'error|BookingDetailController: pipelinq Contact failed|{"app":"shillinq","bookingId":"apt-1","exception":"bug"}',
			],
			$this->logs
		);

	}//end testContactFailures()

	/**
	 * A history fetch that throws is logged and shown as unavailable.
	 *
	 * @return void
	 */
	public function testHistoryFailure(): void {
		$this->allow();
		$this->params = ['limit' => '7', 'offset' => '2'];
		$this->pipelinq->method('getContact')->willReturn(new PipelinqContact(externalId: 'c-1', legalName: 'Acme', found: true));
		$this->pipelinq->method('fetchKlantbeeld')->willThrowException(new \RuntimeException('boom'));

		[, $data] = $this->show('apt-1');

		$expected = $this->show('apt-1')[1]['klantbeeld'];
		self::assertSame($expected, $data['klantbeeld']);
		self::assertTrue($data['klantbeeld']['unavailable']);
		self::assertSame('warning|BookingDetailController: klantbeeld fetch threw unexpectedly|{"app":"shillinq","bookingId":"apt-1","exception":"boom"}', $this->logs[2]);

	}//end testHistoryFailure()

	/**
	 * An unlinked booking is answered without pipelinq and without cache bust.
	 *
	 * @return void
	 */
	public function testUnlinkedAndCacheBust(): void {
		$this->allow();
		$this->appointments = [['appointmentId' => 'apt-1', 'administrationId' => 'adm-1', 'pipelinqContactId' => '  ']];
		$this->params = ['nocache' => 'yes'];
		$this->pipelinq->expects(self::never())->method(self::anything());

		[$status, $data] = $this->show('apt-1');

		self::assertSame(200, $status);
		self::assertTrue($data['notLinkedToPipelinq']);
		self::assertNull($data['klantbeeld']);

	}//end testUnlinkedAndCacheBust()

	/**
	 * A callback that throws the given failure.
	 *
	 * @param \Throwable $failure The failure.
	 *
	 * @return \PHPUnit\Framework\MockObject\Stub\Exception
	 */
	private static function throwValue(\Throwable $failure): \PHPUnit\Framework\MockObject\Stub\Exception {
		return new \PHPUnit\Framework\MockObject\Stub\Exception($failure);

	}//end throwValue()
}//end class
