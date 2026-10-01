<?php

/**
 * Unit tests for FieldRequirementListener, FieldRequirements, ObjectApiRequest and their wiring.
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
 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\AppInfo\FieldRequirementRegistration;
use OCA\Shillinq\Listener\FieldRequirementListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\Platform\FieldRequirements;
use OCA\Shillinq\Service\Platform\ObjectApiRequest;
use OCA\Shillinq\Service\Platform\SchemaDefinitions;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An administration's required fields refuse a person's save that leaves them empty.
 */
class FieldRequirementListenerTest extends TestCase {

	private const GEMEENTE = 'adm-gov-1';

	private const VAN_DIJK = 'adm-consultancy-nl';

	private const REASON = 'Elke inkoopfactuur wordt op een kostenplaats verantwoord';

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Seed: the design's requirement, cost centre on Gemeente Voorbeeld's supplier invoices.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryObjectServiceStub(
			[
				'FieldRequirement' => [
					$this->requirement(id: 'fr-1', administration: self::GEMEENTE, schema: 'SupplierInvoice', field: 'costCenter'),
					array_merge(
						$this->requirement(id: 'fr-2', administration: self::GEMEENTE, schema: 'SupplierInvoice', field: 'projectCode'),
						['lifecycleState' => 'retired']
					),
				],
			]
		);

	}//end setUp()

	/**
	 * A requirement record.
	 *
	 * @param string $id             The id.
	 * @param string $administration The administration.
	 * @param string $schema         The schema slug.
	 * @param string $field          The field.
	 *
	 * @return array<string, mixed>
	 */
	private function requirement(string $id, string $administration, string $schema, string $field): array {
		return [
			'id'               => $id,
			'administrationId' => $administration,
			'schema'           => $schema,
			'field'            => $field,
			'reason'           => self::REASON,
			'lifecycleState'   => 'active',
		];

	}//end requirement()

	/**
	 * A supplier invoice as the form sends it.
	 *
	 * @param string               $administration The administration.
	 * @param array<string, mixed> $extra          Extra fields.
	 *
	 * @return array<string, mixed>
	 */
	private function invoice(string $administration, array $extra = []): array {
		return array_merge(
			[
				'id'               => '9b2e4c1a-5d3f-4e6a-8b7c-0d1e2f3a4b5c',
				'invoiceNumber'    => 'DR-2026-0412',
				'invoiceDate'      => '2026-09-14',
				'currency'         => 'EUR',
				'statusCode'       => 'received',
				'administrationId' => $administration,
				'totalInclVat'     => 1210,
			],
			$extra
		);

	}//end invoice()

	/**
	 * Schema definitions read from the real merged register, the way OpenRegister holds them.
	 *
	 * @return SchemaDefinitions
	 */
	private function definitions(): SchemaDefinitions {
		$definitions = $this->createStub(SchemaDefinitions::class);
		$definitions->method('definition')->willReturnCallback(
			static function (string $schema): ?array {
				$slug = str_replace('schema-id-', '', $schema);
				if (in_array($slug, ['SupplierInvoice', 'CustomerMaster', 'FieldRequirement', 'APTransaction'], true) === false) {
					return null;
				}

				$definition = RegisterSchema::schema(slug: $slug);

				return [
					'slug'       => $slug,
					'title'      => (string)($definition['title'] ?? $slug),
					'properties' => $definition['properties'],
					'required'   => ($definition['required'] ?? []),
				];
			}
		);

		return $definitions;

	}//end definitions()

	/**
	 * The requirements service over the store.
	 *
	 * @return FieldRequirements
	 */
	private function requirements(): FieldRequirements {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new FieldRequirements($this->store, $settings, $this->definitions(), $this->l10n());

	}//end requirements()

	/**
	 * A translator that fills in the parameters.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return $l10n;

	}//end l10n()

	/**
	 * A request as Nextcloud hands it to the app.
	 *
	 * @param string $method The HTTP method.
	 * @param string $uri    The request URI.
	 *
	 * @return IRequest
	 */
	private function request(string $method, string $uri): IRequest {
		$request = $this->createStub(IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$request->method('getRequestUri')->willReturn($uri);

		return $request;

	}//end request()

	/**
	 * The listener for a request.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return FieldRequirementListener
	 */
	private function listener(IRequest $request): FieldRequirementListener {
		$resolver = $this->createStub(ListenerSchemaResolver::class);
		$resolver->method('isOwnRegister')->willReturn(true);

		return new FieldRequirementListener(
			$this->requirements(),
			$this->definitions(),
			new ObjectApiRequest($request),
			$resolver,
			$this->l10n(),
			$this->createStub(LoggerInterface::class)
		);

	}//end listener()

	/**
	 * An entity as OpenRegister hands it to a pre-save listener: schema and register are ids.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($data['id'] ?? ''));
		$entity->setRegister('12');
		$entity->setSchema('schema-id-' . $schema);
		$entity->setObject($data);

		return $entity;

	}//end entity()

	/**
	 * REQ-PRF-002: a bookkeeper's new invoice without a cost centre is refused, naming the field and the reason.
	 *
	 * @return void
	 */
	public function testAPersonsSaveWithoutTheRequiredFieldIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity('SupplierInvoice', $this->invoice(self::GEMEENTE)));
		$this->listener($this->request('POST', '/index.php/apps/openregister/api/objects/shillinq/SupplierInvoice'))->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$errors = $event->getErrors();
		$this->assertStringContainsString('Cost Center', (string)$errors['message']);
		$this->assertStringContainsString(self::REASON, (string)$errors['costCenter']);
		// A retired requirement asks for nothing.
		$this->assertArrayNotHasKey('projectCode', $errors);

	}//end testAPersonsSaveWithoutTheRequiredFieldIsRefused()

	/**
	 * REQ-PRF-002: an update that empties the field is refused too; an empty string counts as empty.
	 *
	 * @return void
	 */
	public function testAnUpdateThatEmptiesTheFieldIsRefused(): void {
		$old = $this->entity('SupplierInvoice', $this->invoice(self::GEMEENTE, ['costCenter' => 'KP-100']));
		$new = $this->entity('SupplierInvoice', $this->invoice(self::GEMEENTE, ['costCenter' => ' ']));
		$event = new ObjectUpdatingEvent($new, $old);
		$this->listener($this->request('PUT', '/apps/openregister/api/objects/shillinq/SupplierInvoice/9b2e4c1a-5d3f-4e6a-8b7c-0d1e2f3a4b5c'))->handle($event);

		$this->assertTrue($event->isPropagationStopped());

	}//end testAnUpdateThatEmptiesTheFieldIsRefused()

	/**
	 * REQ-PRF-002: with the field filled in the save goes through, and the payload is valid for the real schema.
	 *
	 * @return void
	 */
	public function testASaveWithTheFieldGoesThrough(): void {
		$payload = $this->invoice(self::GEMEENTE, ['costCenter' => 'KP-100']);
		$event = new ObjectCreatingEvent($this->entity('SupplierInvoice', $payload));
		$this->listener($this->request('POST', '/apps/openregister/api/objects/shillinq/SupplierInvoice'))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], RegisterSchema::errors(slug: 'SupplierInvoice', object: $payload));

	}//end testASaveWithTheFieldGoesThrough()

	/**
	 * REQ-PRF-002 scenario: another administration is not affected.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationIsNotAffected(): void {
		$event = new ObjectCreatingEvent($this->entity('SupplierInvoice', $this->invoice(self::VAN_DIJK)));
		$this->listener($this->request('POST', '/apps/openregister/api/objects/shillinq/SupplierInvoice'))->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testAnotherAdministrationIsNotAffected()

	/**
	 * REQ-PRF-002: writes by shillinq's own services, jobs and other listeners are not refused.
	 *
	 * @return void
	 */
	public function testSystemWritesAreNotRefused(): void {
		$cases = [
			// A shillinq controller writing through its service.
			['POST', '/apps/shillinq/api/supplier-invoices/import'],
			// A listener writing an invoice while a person saves another record.
			['POST', '/apps/openregister/api/objects/shillinq/APTransaction'],
			// A lifecycle action: the transition, not the form.
			['POST', '/apps/openregister/api/objects/shillinq/SupplierInvoice/9b2e4c1a-5d3f-4e6a-8b7c-0d1e2f3a4b5c/actions/approve'],
			// A listener updating another invoice during a person's update.
			['PUT', '/apps/openregister/api/objects/shillinq/SupplierInvoice/0000aaaa-1111-4222-8333-444455556666'],
			// A background job or occ: no request.
			['', ''],
		];
		foreach ($cases as [$method, $uri]) {
			$event = new ObjectCreatingEvent($this->entity('SupplierInvoice', $this->invoice(self::GEMEENTE)));
			$this->listener($this->request($method, $uri))->handle($event);
			$this->assertFalse($event->isPropagationStopped(), $method . ' ' . $uri);
		}

	}//end testSystemWritesAreNotRefused()

	/**
	 * REQ-PRF-001: a requirement on a field the schema lacks, or on one that is always required, is refused on save.
	 *
	 * @return void
	 */
	public function testARequirementOnAnUnknownOrShippedFieldIsRefused(): void {
		$uri = '/apps/openregister/api/objects/shillinq/FieldRequirement';
		$cases = [
			'field'  => $this->requirement(id: 'fr-9', administration: self::GEMEENTE, schema: 'SupplierInvoice', field: 'costCentre'),
			'schema' => $this->requirement(id: 'fr-9', administration: self::GEMEENTE, schema: 'NoSuchThing', field: 'x'),
		];
		foreach ($cases as $key => $row) {
			$event = new ObjectCreatingEvent($this->entity('FieldRequirement', $row));
			$this->listener($this->request('POST', $uri))->handle($event);
			$this->assertTrue($event->isPropagationStopped(), $key);
			$this->assertArrayHasKey($key, $event->getErrors());
		}

		$shipped = $this->requirement(id: 'fr-9', administration: self::VAN_DIJK, schema: 'CustomerMaster', field: 'email');
		$event = new ObjectCreatingEvent($this->entity('FieldRequirement', $shipped));
		$this->listener($this->request('POST', $uri))->handle($event);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('always required', (string)$event->getErrors()['field']);

	}//end testARequirementOnAnUnknownOrShippedFieldIsRefused()

	/**
	 * REQ-PRF-001: a valid requirement saves, and its payload and the seeds validate against the real FieldRequirement schema.
	 *
	 * @return void
	 */
	public function testAValidRequirementSavesAndTheSeedsValidate(): void {
		$row = $this->requirement(id: 'fr-3', administration: self::VAN_DIJK, schema: 'CustomerMaster', field: 'kvkNumber');
		$event = new ObjectCreatingEvent($this->entity('FieldRequirement', $row));
		$this->listener($this->request('POST', '/apps/openregister/api/objects/shillinq/FieldRequirement'))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], RegisterSchema::errors(slug: 'FieldRequirement', object: $row));

		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/platform-required-fields.json'), true);
		$seeds = array_values(array_filter($fragment['objects'], static fn (array $seed): bool => ($seed['@self']['schema'] ?? '') === 'FieldRequirement'));
		$this->assertCount(2, $seeds);
		$service = $this->requirements();
		foreach ($seeds as $seed) {
			$payload = $seed;
			unset($payload['@self']);
			$this->assertSame([], RegisterSchema::errors(slug: 'FieldRequirement', object: $payload));
			$this->assertSame([], $service->refusal(requirement: $payload), (string)$payload['field']);
		}

	}//end testAValidRequirementSavesAndTheSeedsValidate()

	/**
	 * REQ-PRF-001: the field list of a requirement shows shipped fields locked and this administration's own ones.
	 *
	 * @return void
	 */
	public function testTheFieldListShowsShippedAndAdministrationRequirements(): void {
		$rows = $this->requirements()->formFields(requirement: $this->requirement(id: 'fr-1', administration: self::GEMEENTE, schema: 'SupplierInvoice', field: 'costCenter'));
		$byField = array_column($rows, 'required', 'field');

		$this->assertSame('always', $byField['invoiceNumber']);
		$this->assertSame('administration', $byField['costCenter']);
		$this->assertSame('no', $byField['projectCode']);
		$this->assertSame('Cost Center', array_column($rows, 'title', 'field')['costCenter']);

	}//end testTheFieldListShowsShippedAndAdministrationRequirements()

	/**
	 * Objects without an administration, and objects of another register, are left alone.
	 *
	 * @return void
	 */
	public function testObjectsOutsideTheCheckAreIgnored(): void {
		$event = new ObjectCreatingEvent($this->entity('SupplierInvoice', $this->invoice('')));
		$this->listener($this->request('POST', '/apps/openregister/api/objects/shillinq/SupplierInvoice'))->handle($event);
		$this->assertFalse($event->isPropagationStopped());

		$resolver = $this->createStub(ListenerSchemaResolver::class);
		$resolver->method('isOwnRegister')->willReturn(false);
		$listener = new FieldRequirementListener(
			$this->requirements(),
			$this->definitions(),
			new ObjectApiRequest($this->request('POST', '/apps/openregister/api/objects/other/SupplierInvoice')),
			$resolver,
			$this->l10n(),
			$this->createStub(LoggerInterface::class)
		);
		$event = new ObjectCreatingEvent($this->entity('SupplierInvoice', $this->invoice(self::GEMEENTE)));
		$listener->handle($event);
		$this->assertFalse($event->isPropagationStopped());

	}//end testObjectsOutsideTheCheckAreIgnored()

	/**
	 * SchemaDefinitions reads slug, title, properties and required list through the mapper's magic getters, once.
	 *
	 * @return void
	 */
	public function testSchemaDefinitionsReadTheMapperOnce(): void {
		$schema = new class {
			/**
			 * Magic getters, as OpenRegister's Schema entity serves them.
			 *
			 * @param string       $name      The getter.
			 * @param array<mixed> $arguments Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $arguments): mixed {
				return [
					'getSlug'       => 'SupplierInvoice',
					'getTitle'      => 'Supplier invoice',
					'getProperties' => ['costCenter' => ['type' => 'string', 'title' => 'Cost Center']],
					'getRequired'   => ['invoiceNumber'],
				][$name];
			}
		};
		$mapper = new class ($schema) {
			public int $calls = 0;

			/**
			 * Constructor.
			 *
			 * @param object $schema The schema to answer.
			 */
			public function __construct(private readonly object $schema) {
			}

			/**
			 * Find by id or slug.
			 *
			 * @param string|int $id The id.
			 *
			 * @return object
			 */
			public function find(string|int $id): object {
				$this->calls++;
				if ($id !== '41') {
					throw new \RuntimeException('Not found');
				}

				return $this->schema;
			}
		};
		$container = $this->createStub(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$definitions = new SchemaDefinitions($container, $this->createStub(LoggerInterface::class));

		$definition = $definitions->definition(schema: '41');
		$definitions->definition(schema: '41');
		$this->assertSame(['slug' => 'SupplierInvoice', 'title' => 'Supplier invoice', 'properties' => ['costCenter' => ['type' => 'string', 'title' => 'Cost Center']], 'required' => ['invoiceNumber']], $definition);
		$this->assertSame(1, $mapper->calls);
		$this->assertNull($definitions->definition(schema: '99'));

	}//end testSchemaDefinitionsReadTheMapperOnce()

	/**
	 * The listener is registered on both pre-save events, and the app calls the registration.
	 *
	 * @return void
	 */
	public function testTheAppWiresTheListenerOnBothPreSaveEvents(): void {
		$listeners = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$listeners): void {
				$listeners[] = [$event, $listener];
			}
		);
		(new FieldRequirementRegistration())->register($context);

		$this->assertContains([ObjectCreatingEvent::class, FieldRequirementListener::class], $listeners);
		$this->assertContains([ObjectUpdatingEvent::class, FieldRequirementListener::class], $listeners);

		$app = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');
		$this->assertStringContainsString('(new FieldRequirementRegistration())->register(context: $context);', $app);

	}//end testTheAppWiresTheListenerOnBothPreSaveEvents()
}//end class
