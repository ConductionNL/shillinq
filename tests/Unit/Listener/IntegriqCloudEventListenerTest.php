<?php

/**
 * Unit tests for IntegriqCloudEventListener.
 *
 * Every test feeds the listener an `ObjectCreatedEvent` whose entity carries
 * numeric register and schema ids, the shape OpenRegister really emits, and
 * lets the real ListenerSchemaResolver turn them back into slugs. The payment
 * outcome runs through the real PaymentReconciliationService over the
 * OpenRegister-faithful in-memory store, so each test asserts what happened to
 * the request and its invoice, not merely that a method was called.
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
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Listener\IntegriqCloudEventListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\ListenerSlugContract;
use OCA\Shillinq\Service\PaymentReconciliationService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\OpenRegisterFaithfulObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Service/Support/OpenRegisterFaithfulObjectService.php';

/**
 * Payment status CloudEvents from integriq reach reconcile().
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IntegriqCloudEventListenerTest extends TestCase {
	/**
	 * Register id => slug, as `oc_openregister_registers` would answer.
	 *
	 * @var array<string,string>
	 */
	private const REGISTERS = ['7' => 'integriq', '3' => 'shillinq'];

	/**
	 * Schema id => slug, as `oc_openregister_schemas` would answer.
	 *
	 * @var array<string,string>
	 */
	private const SCHEMAS = ['4101' => 'event', '4102' => 'source', '1090' => 'PaymentRequest'];

	/**
	 * The in-memory store behind the real reconciliation service.
	 *
	 * @var OpenRegisterFaithfulObjectService
	 */
	private OpenRegisterFaithfulObjectService $store;

	/**
	 * Seed a pending request linked to an issued invoice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new OpenRegisterFaithfulObjectService();
		$this->store->seed(schema: 'PaymentRequest', rows: [
			['id' => 'pr-1', 'paymentIntentId' => 'tr_example0002', 'state' => 'pending', 'invoiceReference' => 'inv-1'],
		]);
		$this->store->seed(schema: 'ARInvoice', rows: [
			['id' => 'inv-1', 'invoiceNumber' => '2026-0412', 'lifecycleState' => 'issued'],
		]);
	}//end setUp()

	/**
	 * Build the listener over the real resolver and the real reconciliation.
	 *
	 * @return IntegriqCloudEventListener
	 */
	private function listener(): IntegriqCloudEventListener {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $service): object {
				if (str_contains($service, 'RegisterMapper') === true) {
					return $this->mapper(map: self::REGISTERS);
				}

				if (str_contains($service, 'SchemaMapper') === true) {
					return $this->mapper(map: self::SCHEMAS);
				}

				return $this->store;
			}
		);

		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$contract = $this->createStub(ListenerSlugContract::class);
		$contract->method('isEnabled')->willReturn(false);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return $default;
			}
		);

		return new IntegriqCloudEventListener(
			schemaResolver: new ListenerSchemaResolver(
				container: $container,
				settingsService: $settings,
				contract: $contract,
				logger: new NullLogger(),
			),
			reconciliation: new PaymentReconciliationService(
				container: $container,
				appConfig: $appConfig,
				logger: new NullLogger(),
				objectService: new DuckObjectServiceAdapter($this->store),
			),
			logger: new NullLogger(),
		);
	}//end listener()

	/**
	 * A stand-in for OpenRegister's RegisterMapper / SchemaMapper: find() by id.
	 *
	 * @param array<string,string> $map Id => slug.
	 *
	 * @return object
	 */
	private function mapper(array $map): object {
		return new class($map) {
			/**
			 * Constructor.
			 *
			 * @param array<string,string> $map Id => slug.
			 */
			public function __construct(
				private array $map,
			) {
			}

			/**
			 * Resolve an id to a slug-bearing entity, as the real mapper's find() does.
			 *
			 * @param string $id The id.
			 *
			 * @return ObjectEntity
			 *
			 * @throws \RuntimeException When the id is unknown.
			 */
			public function find(string $id): object {
				if (array_key_exists($id, $this->map) === false) {
					throw new \RuntimeException('not found');
				}

				return new class($this->map[$id]) {
					/**
					 * Constructor.
					 *
					 * @param string $slug The slug.
					 */
					public function __construct(
						private string $slug,
					) {
					}

					/**
					 * The slug.
					 *
					 * @return string
					 */
					public function getSlug(): string {
						return $this->slug;
					}
				};
			}
		};
	}//end mapper()

	/**
	 * The object integriq saves for one CloudEvent, id-stamped as OpenRegister does.
	 *
	 * @param string $type The CloudEvent type.
	 * @param array<string,mixed> $data The CloudEvent data.
	 * @param string $register The register id stamped on the entity.
	 * @param string $schema The schema id stamped on the entity.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function cloudEvent(string $type, array $data, string $register = '7', string $schema = '4101'): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setRegister($register);
		$entity->setSchema($schema);
		$entity->setObject(
			[
				'source' => '/payments/tr_example0002',
				'type' => $type,
				'subject' => 'tr_example0002',
				'data' => $data,
			]
		);

		return new ObjectCreatedEvent($entity);
	}//end cloudEvent()

	/**
	 * The data integriq's emitStatusEvent() writes for an outcome.
	 *
	 * @param string $outcome authorized|captured|failed|voided.
	 *
	 * @return array<string,mixed>
	 */
	private function statusData(string $outcome): array {
		return [
			'paymentIntentId' => 'tr_example0002',
			'outcome' => $outcome,
			'errorCode' => null,
			'errorMessage' => ($outcome === 'failed') ? 'Payment failed at gateway.' : null,
			'settlementReference' => ($outcome === 'captured') ? 'tr_example0002' : null,
			'gatewayFeeAmount' => null,
		];
	}//end statusData()

	/**
	 * The request with a given id, as stored.
	 *
	 * @param string $schema The schema.
	 * @param string $id The id.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(string $schema, string $id): array {
		foreach ($this->store->dump(schema: $schema) as $row) {
			if (($row['id'] ?? null) === $id) {
				return $row;
			}
		}

		self::fail($schema . ' ' . $id . ' is not in the store');
	}//end stored()

	/**
	 * Captured: the request is captured and its invoice paid, with nobody pressing settle.
	 *
	 * @return void
	 */
	public function testACapturedPaymentFromIntegriqSettlesTheRequestAndItsInvoice(): void {
		$this->listener()->handle($this->cloudEvent(type: 'nl.conduction.payment.status', data: $this->statusData(outcome: 'captured')));

		self::assertSame('captured', $this->stored(schema: 'PaymentRequest', id: 'pr-1')['state']);
		self::assertSame('mollie', $this->stored(schema: 'PaymentRequest', id: 'pr-1')['paymentGateway']);
		self::assertSame('paid', $this->stored(schema: 'ARInvoice', id: 'inv-1')['lifecycleState']);
	}//end testACapturedPaymentFromIntegriqSettlesTheRequestAndItsInvoice()

	/**
	 * Failed: the request records the failure with the reason.
	 *
	 * @return void
	 */
	public function testAFailedPaymentFromIntegriqIsRecordedWithTheReason(): void {
		$this->listener()->handle($this->cloudEvent(type: 'nl.conduction.payment.status', data: $this->statusData(outcome: 'failed')));

		$request = $this->stored(schema: 'PaymentRequest', id: 'pr-1');
		self::assertSame('failed', $request['state']);
		self::assertSame('Payment failed at gateway.', $request['failureReason']);
		self::assertSame('issued', $this->stored(schema: 'ARInvoice', id: 'inv-1')['lifecycleState']);
	}//end testAFailedPaymentFromIntegriqIsRecordedWithTheReason()

	/**
	 * A repeated capture changes nothing.
	 *
	 * @return void
	 */
	public function testARepeatedCaptureChangesNothing(): void {
		$listener = $this->listener();
		$listener->handle($this->cloudEvent(type: 'nl.conduction.payment.status', data: $this->statusData(outcome: 'captured')));
		$first = $this->stored(schema: 'PaymentRequest', id: 'pr-1');

		$listener->handle($this->cloudEvent(type: 'nl.conduction.payment.status', data: $this->statusData(outcome: 'captured')));

		self::assertSame($first, $this->stored(schema: 'PaymentRequest', id: 'pr-1'));
		self::assertSame('paid', $this->stored(schema: 'ARInvoice', id: 'inv-1')['lifecycleState']);
	}//end testARepeatedCaptureChangesNothing()

	/**
	 * Another CloudEvent type, the same type in another register or another
	 * schema, and a non-creation event all leave the request alone.
	 *
	 * @return void
	 */
	public function testOnlyAPaymentStatusObjectInIntegriqsEventSchemaIsTaken(): void {
		$listener = $this->listener();
		$listener->handle($this->cloudEvent(type: 'nl.conduction.peppol.delivery.status', data: $this->statusData(outcome: 'captured')));
		$listener->handle($this->cloudEvent(type: 'nl.conduction.payment.status', data: $this->statusData(outcome: 'captured'), register: '3'));
		$listener->handle($this->cloudEvent(type: 'nl.conduction.payment.status', data: $this->statusData(outcome: 'captured'), schema: '4102'));
		$listener->handle(new \OCP\EventDispatcher\Event());

		self::assertSame('pending', $this->stored(schema: 'PaymentRequest', id: 'pr-1')['state']);
	}//end testOnlyAPaymentStatusObjectInIntegriqsEventSchemaIsTaken()
}//end class
