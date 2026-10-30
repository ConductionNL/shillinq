<?php

/**
 * Unit tests for PeppolDeliveryStatusListener (REQ-EINV-005 / REQ-AR-011).
 *
 * Every event is the one integriq really produces: an `ObjectCreatedEvent`
 * for the CloudEvent object integriq saves in its `integriq` register, `event`
 * schema, with numeric register and schema ids as OpenRegister stamps them.
 * The real ListenerSchemaResolver turns the ids back into slugs, and the
 * invoices sit in the OpenRegister-faithful store, where a `filters['id']`
 * lookup matches nothing, as in production (issue #1111).
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
 * @spec openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-005
 * @spec openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-accounts-receivable-core/spec.md#req-ar-011
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Listener\PeppolDeliveryStatusListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\ListenerSlugContract;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\OpenRegisterFaithfulObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\EventDispatcher\GenericEvent;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionNamedType;

require_once __DIR__ . '/../Service/Support/OpenRegisterFaithfulObjectService.php';

/**
 * Covers: sent -> delivered advances + persists detail; in-flight -> rejected
 * advances + persists detail + notifies the receivables roles; illegal
 * transitions are skipped (fail-soft, no corruption); only integriq's
 * delivery-status object is taken.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PeppolDeliveryStatusListenerTest extends TestCase {
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
	private const SCHEMAS = ['4101' => 'event', '4102' => 'source'];

	/**
	 * The invoice uuid used across the scenarios.
	 *
	 * @var string
	 */
	private const INVOICE_ID = 'a1b2c3d4-0000-4000-8000-000000000051';

	/**
	 * The store behind the listener's ObjectService.
	 *
	 * @var OpenRegisterFaithfulObjectService
	 */
	private OpenRegisterFaithfulObjectService $store;

	/**
	 * Captured notifications: [user, subject, parameters].
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $notifications = [];

	/**
	 * Per-notification mock state-bag (spl_object_id => stdClass).
	 *
	 * @var array<int,object>
	 */
	private array $notificationState = [];

	/**
	 * Fresh store per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new OpenRegisterFaithfulObjectService();
	}//end setUp()

	/**
	 * REQ-AR-011 scenario: sent -> delivered advances the sub-lifecycle and
	 * persists the event detail.
	 *
	 * @return void
	 */
	public function testSentToDeliveredAdvancesAndPersistsDetail(): void {
		$this->seedInvoice(deliveryStatus: 'sent');

		$this->listener()->handle(
			$this->deliveryStatus(
				data: [
					'objectUri' => 'openregister://shillinq/ARInvoice/' . self::INVOICE_ID,
					'transmissionId' => 'urn:uuid:old',
					'status' => 'delivered',
					'timestamp' => '2026-06-20T10:00:00+00:00',
					'detail' => 'Delivered to recipient AP',
				]
			)
		);

		$invoice = $this->invoice();
		self::assertSame('delivered', $invoice['deliveryStatus']);
		self::assertSame('Delivered to recipient AP', $invoice['deliveryDetail']);
		self::assertCount(1, $this->store->dump(schema: 'ARInvoice'), 'the invoice is updated, not copied');

	}//end testSentToDeliveredAdvancesAndPersistsDetail()

	/**
	 * REQ-AR-011 / REQ-EINV-005 scenario: an in-flight invoice rejected;
	 * deliveryStatus -> rejected, detail persisted, receivables roles notified with
	 * what EInvoiceNotifier needs to render and link it.
	 *
	 * @return void
	 */
	public function testInFlightRejectedNotifiesFinanceOperators(): void {
		$this->seedInvoice(deliveryStatus: 'queued', invoiceNumber: '2026-0060');
		$this->seedMemberships(rows: [
			['id' => 'm-1', 'administrationId' => 'adm-1', 'role' => 'debiteurenadmin', 'userId' => 'controller-1'],
			['id' => 'm-2', 'administrationId' => 'adm-1', 'role' => 'crediteurenadmin', 'userId' => 'buyer-1'],
		]);

		$this->listener()->handle(
			$this->deliveryStatus(
				data: [
					'objectUri' => 'openregister://shillinq/ARInvoice/' . self::INVOICE_ID,
					'status' => 'rejected',
					'detail' => 'Unknown recipient participant',
				]
			)
		);

		self::assertSame('rejected', $this->invoice()['deliveryStatus']);
		self::assertSame('Unknown recipient participant', $this->invoice()['deliveryDetail']);

		self::assertCount(1, $this->notifications);
		self::assertSame('controller-1', $this->notifications[0]['user']);
		self::assertSame('einvoice_delivery_rejected', $this->notifications[0]['subject']);
		self::assertSame(
			['invoiceNumber' => '2026-0060', 'detail' => 'Unknown recipient participant', 'invoiceId' => self::INVOICE_ID],
			$this->notifications[0]['parameters']
		);

	}//end testInFlightRejectedNotifiesFinanceOperators()

	/**
	 * An objectUri that carries the invoice number instead of the uuid still
	 * finds the invoice.
	 *
	 * @return void
	 */
	/**
	 * #1754: the recipients are the membership roles that own receivables,
	 * every one of them a role the AdministrationMembership schema allows,
	 * and nobody else. Each seeded membership is first validated against the
	 * real register schema, so a role the register would refuse (as
	 * `ar-controller` was) cannot be seeded here.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 */
	public function testRejectedNotifiesEveryReceivablesRoleTheSchemaAllows(): void {
		$this->seedInvoice(deliveryStatus: 'sent', invoiceNumber: '2026-0420');
		$this->seedMemberships(rows: [
			['id' => 'm-1', 'administrationId' => 'adm-1', 'role' => 'debiteurenadmin', 'userId' => 'debiteuren-1'],
			['id' => 'm-2', 'administrationId' => 'adm-1', 'role' => 'boekhouder', 'userId' => 'boekhouder-1'],
			['id' => 'm-3', 'administrationId' => 'adm-1', 'role' => 'controller', 'userId' => 'controller-1'],
			['id' => 'm-4', 'administrationId' => 'adm-1', 'role' => 'eigenaar', 'userId' => 'admin'],
			['id' => 'm-5', 'administrationId' => 'adm-1', 'role' => 'inkijker', 'userId' => 'viewer-1'],
			['id' => 'm-6', 'administrationId' => 'adm-1', 'role' => 'crediteurenadmin', 'userId' => 'crediteuren-1'],
			['id' => 'm-7', 'administrationId' => 'adm-1', 'role' => 'accountant_extern', 'userId' => 'accountant-1'],
			['id' => 'm-8', 'administrationId' => 'adm-1', 'role' => 'salarisadministrateur', 'userId' => 'salaris-1'],
			['id' => 'm-9', 'administrationId' => 'adm-2', 'role' => 'debiteurenadmin', 'userId' => 'other-admin-1'],
		]);

		$this->listener()->handle(
			$this->deliveryStatus(data: ['objectUri' => 'openregister://shillinq/ARInvoice/' . self::INVOICE_ID, 'status' => 'rejected', 'detail' => 'Ordernummer ontbreekt'])
		);

		$users = array_column($this->notifications, 'user');
		sort($users);
		self::assertSame(['admin', 'boekhouder-1', 'controller-1', 'debiteuren-1'], $users);

		foreach (PeppolDeliveryStatusListener::RECEIVABLES_ROLES as $role) {
			self::assertContains($role, self::schemaRoles(), $role . ' is not a role the AdministrationMembership schema allows');
		}

	}//end testRejectedNotifiesEveryReceivablesRoleTheSchemaAllows()

	/**
	 * #1754: OpenRegister's findAll() answers ObjectEntity instances, not
	 * arrays, so the membership lookup must read those too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 */
	public function testRejectedNotifiesWhenFindAllAnswersEntities(): void {
		$membership = ['id' => 'm-1', 'administrationId' => 'adm-1', 'role' => 'eigenaar', 'userId' => 'admin'];
		self::assertSame([], RegisterSchema::errors(slug: 'AdministrationMembership', object: $membership));

		$store = new InMemoryObjectServiceStub(
			data: [
				'ARInvoice' => [['id' => self::INVOICE_ID, 'invoiceNumber' => '2026-0421', 'administrationId' => 'adm-1', 'deliveryStatus' => 'sent']],
				'AdministrationMembership' => [$membership],
			],
			findAllRendersEntities: true
		);

		$this->listener(objectService: $store)->handle(
			$this->deliveryStatus(data: ['objectUri' => 'openregister://shillinq/ARInvoice/' . self::INVOICE_ID, 'status' => 'rejected', 'detail' => 'Unknown participant'])
		);

		self::assertSame(['admin'], array_column($this->notifications, 'user'));

	}//end testRejectedNotifiesWhenFindAllAnswersEntities()

	/**
	 * Seed memberships after checking each one against the register schema.
	 *
	 * @param array<int,array<string,mixed>> $rows Membership rows.
	 *
	 * @return void
	 */
	private function seedMemberships(array $rows): void {
		foreach ($rows as $row) {
			self::assertSame([], RegisterSchema::errors(slug: 'AdministrationMembership', object: $row), 'the register would refuse membership ' . $row['id']);
		}

		$this->store->seed(schema: 'AdministrationMembership', rows: $rows);

	}//end seedMemberships()

	/**
	 * The roles the AdministrationMembership schema allows.
	 *
	 * @return array<int,string>
	 */
	private static function schemaRoles(): array {
		return RegisterSchema::schema(slug: 'AdministrationMembership')['properties']['role']['enum'];

	}//end schemaRoles()

	public function testAnInvoiceNumberInTheObjectUriFindsTheInvoice(): void {
		$this->seedInvoice(deliveryStatus: 'sent', invoiceNumber: '2026-0052');

		$this->listener()->handle(
			$this->deliveryStatus(data: ['objectUri' => 'openregister://shillinq/ARInvoice/2026-0052', 'status' => 'delivered'])
		);

		self::assertSame('delivered', $this->invoice()['deliveryStatus']);

	}//end testAnInvoiceNumberInTheObjectUriFindsTheInvoice()

	/**
	 * An illegal transition (e.g. not-sent -> delivered, skipping queued/sent)
	 * is skipped: fail-soft, no state corruption.
	 *
	 * @return void
	 */
	public function testIllegalTransitionIsSkipped(): void {
		$this->seedInvoice(deliveryStatus: 'not-sent');

		$this->listener()->handle(
			$this->deliveryStatus(
				data: [
					'objectUri' => 'openregister://shillinq/ARInvoice/' . self::INVOICE_ID,
					'status' => 'delivered',
					'detail' => 'should not apply',
				]
			)
		);

		self::assertSame('not-sent', $this->invoice()['deliveryStatus'], 'an illegal transition must never be persisted');

	}//end testIllegalTransitionIsSkipped()

	/**
	 * Only integriq's delivery-status object moves the invoice: another
	 * CloudEvent type, the same type in another register or schema, the old
	 * named GenericEvent and a bare event all leave it alone.
	 *
	 * @return void
	 */
	public function testOnlyIntegriqsDeliveryStatusObjectIsTaken(): void {
		$this->seedInvoice(deliveryStatus: 'sent');
		$data = ['objectUri' => 'openregister://shillinq/ARInvoice/' . self::INVOICE_ID, 'status' => 'delivered'];
		$listener = $this->listener();

		$listener->handle($this->deliveryStatus(data: $data, type: 'nl.conduction.peppol.inbound.received'));
		$listener->handle($this->deliveryStatus(data: $data, register: '3'));
		$listener->handle($this->deliveryStatus(data: $data, schema: '4102'));
		$listener->handle(new GenericEvent(null, $data));
		$listener->handle(new \OCP\EventDispatcher\Event());

		self::assertSame('sent', $this->invoice()['deliveryStatus']);

	}//end testOnlyIntegriqsDeliveryStatusObjectIsTaken()

	/**
	 * Seed the invoice the scenarios act on.
	 *
	 * @param string $deliveryStatus The current delivery status.
	 * @param string $invoiceNumber The invoice number.
	 *
	 * @return void
	 */
	private function seedInvoice(string $deliveryStatus, string $invoiceNumber = '2026-0051'): void {
		$this->store->seed(schema: 'ARInvoice', rows: [
			[
				'id' => self::INVOICE_ID,
				'invoiceNumber' => $invoiceNumber,
				'administrationId' => 'adm-1',
				'deliveryStatus' => $deliveryStatus,
				'transmissionId' => 'urn:uuid:old',
			],
		]);
	}//end seedInvoice()

	/**
	 * The invoice as stored now.
	 *
	 * @return array<string,mixed>
	 */
	private function invoice(): array {
		foreach ($this->store->dump(schema: 'ARInvoice') as $row) {
			if (($row['id'] ?? null) === self::INVOICE_ID) {
				return $row;
			}
		}

		self::fail('the invoice is not in the store');
	}//end invoice()

	/**
	 * The object integriq saves for one delivery-status CloudEvent, id-stamped.
	 *
	 * @param array<string,mixed> $data The CloudEvent data.
	 * @param string $type The CloudEvent type.
	 * @param string $register The register id stamped on the entity.
	 * @param string $schema The schema id stamped on the entity.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function deliveryStatus(
		array $data,
		string $type = 'nl.conduction.peppol.delivery.status',
		string $register = '7',
		string $schema = '4101',
	): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setRegister($register);
		$entity->setSchema($schema);
		$entity->setObject(['source' => '/peppol/transmissions/t-1', 'type' => $type, 'subject' => 't-1', 'data' => $data]);

		return new ObjectCreatedEvent($entity);
	}//end deliveryStatus()

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
			 * @return object
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
	 * Build the listener over the real resolver, the faithful store and a
	 * notification manager that records what it is asked to send.
	 *
	 * Built from its own constructor by parameter type, the way the DI
	 * container autowires it, so the test does not pin the parameter list.
	 *
	 * @return PeppolDeliveryStatusListener
	 */
	private function listener(?object $objectService = null): PeppolDeliveryStatusListener {
		$objectService ??= new DuckObjectServiceAdapter($this->store);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $service) use ($objectService): object {
				if (str_contains($service, 'RegisterMapper') === true) {
					return $this->mapper(map: self::REGISTERS);
				}

				if (str_contains($service, 'SchemaMapper') === true) {
					return $this->mapper(map: self::SCHEMAS);
				}

				return $objectService;
			}
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$contract = $this->createStub(ListenerSlugContract::class);
		$contract->method('isEnabled')->willReturn(false);

		$byType = [
			ContainerInterface::class => $container,
			IAppConfig::class => $appConfig,
			INotificationManager::class => $this->notificationManager(),
			LoggerInterface::class => new NullLogger(),
			ListenerSchemaResolver::class => new ListenerSchemaResolver(
				container: $container,
				settingsService: $settings,
				contract: $contract,
				logger: new NullLogger(),
			),
		];

		$arguments = [];
		foreach ((new ReflectionClass(PeppolDeliveryStatusListener::class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			self::assertArrayHasKey($type->getName(), $byType, 'No test double for ' . $type->getName());
			$arguments[$parameter->getName()] = $byType[$type->getName()];
		}

		return new PeppolDeliveryStatusListener(...$arguments);

	}//end listener()

	/**
	 * A notification manager that records every notify() call.
	 *
	 * @return INotificationManager
	 */
	private function notificationManager(): INotificationManager {
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$state = (object)['user' => '', 'subject' => '', 'parameters' => []];

				$notification = $this->createMock(INotification::class);
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('setObject')->willReturnSelf();
				$notification->method('setUser')->willReturnCallback(
					function (string $user) use ($notification, $state): INotification {
						$state->user = $user;
						return $notification;
					}
				);
				$notification->method('setSubject')->willReturnCallback(
					function (string $subject, array $parameters = []) use ($notification, $state): INotification {
						$state->subject = $subject;
						$state->parameters = $parameters;
						return $notification;
					}
				);

				$this->notificationState[spl_object_id($notification)] = $state;
				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$state = ($this->notificationState[spl_object_id($notification)] ?? null);
				if ($state !== null) {
					$this->notifications[] = ['user' => $state->user, 'subject' => $state->subject, 'parameters' => $state->parameters];
				}
			}
		);

		return $manager;
	}//end notificationManager()
}//end class
