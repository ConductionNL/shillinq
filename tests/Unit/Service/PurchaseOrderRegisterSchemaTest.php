<?php

/**
 * Every PurchaseOrder the services save must pass the register schema.
 *
 * shillinq#1753: PurchaseOrderService wrote its lifecycle as `lifecycleState`
 * (with the states `pending_approval` and `rejected`), while the PurchaseOrder
 * schema declares `statusCode` as its lifecycle field, lists it in
 * `required`, and knows neither state. OpenRegister refused every create
 * ("The required property (statusCode) is missing") and the unit tests stayed
 * green, because none of them looked at the schema. This test drives the real
 * service over one in-memory store (create, approve as OpenRegister's chain
 * would, send) and
 * validates every PurchaseOrder they hand to saveObject() against the REAL
 * schema: shillinq_register.json merged with every register.d fragment by
 * SettingsService::deepMergeConfig(), the merge the app runs at install time,
 * validated with opis/json-schema, the validator OpenRegister uses.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use OCP\IUser;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Validates saved PurchaseOrders against the merged register schema.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrderRegisterSchemaTest extends TestCase {

	/**
	 * The shared store both services read and write.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The PurchaseOrder schema as the app imports it.
	 *
	 * @return array<string,mixed>
	 */
	private static function purchaseOrderSchema(): array {
		$settings = dirname(__DIR__, 3) . '/lib/Settings';
		$config = json_decode((string)file_get_contents($settings . '/shillinq_register.json'), true, flags: JSON_THROW_ON_ERROR);

		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$fragments = glob($settings . '/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			if (is_array($fragment) === true) {
				$config = $merge->invoke(null, $config, $fragment);
			}
		}

		$schema = $config['components']['schemas']['PurchaseOrder'];

		// OpenRegister reads a `$ref` on a property as a relation marker and
		// strips it before validating (ValidateObject::transformPropertyForOpenRegister):
		// a string property keeps its type and format, array items that `$ref`
		// a schema become UUID strings. Do the same, or opis tries to resolve
		// `Payee` as a URI.
		foreach ($schema['properties'] as $name => $property) {
			if (isset($property['$ref']) === true && ($property['type'] ?? null) === 'string') {
				unset($schema['properties'][$name]['$ref']);
			}

			if (isset($property['items']['$ref']) === true) {
				$schema['properties'][$name]['items'] = ['type' => 'string'];
			}
		}

		return $schema;
	}//end purchaseOrderSchema()

	/**
	 * Every PurchaseOrder saved so far, with its schema errors.
	 *
	 * @return array<int,array<string,mixed>> One entry per invalid save.
	 */
	private function invalidPurchaseOrderSaves(): array {
		$schema = json_encode(self::purchaseOrderSchema(), JSON_THROW_ON_ERROR);
		$invalid = [];
		foreach ($this->store->saved as $index => $save) {
			if ($save['schema'] !== 'PurchaseOrder') {
				continue;
			}

			$errors = self::schemaErrors(object: $save['object'], schema: $schema);
			if ($errors !== []) {
				$invalid[] = ['save' => $index, 'errors' => $errors];
			}
		}

		return $invalid;
	}//end invalidPurchaseOrderSaves()

	/**
	 * Validate one object against the schema.
	 *
	 * @param array<string,mixed> $object The object.
	 * @param string $schema The schema as JSON.
	 *
	 * @return array<string,mixed> Formatted errors, empty when valid.
	 */
	private static function schemaErrors(array $object, string $schema): array {
		unset($object['id']);

		// OpenRegister drops an empty string or an empty array on a field that
		// is not required before it validates (ValidateObject::validateObject()),
		// so a blank optional requisitionId is not a violation. Mirror that.
		$required = (json_decode($schema, true)['required'] ?? []);
		$object = array_filter(
			$object,
			static fn ($value, $key): bool => in_array($key, $required, true) === true || ($value !== '' && $value !== []),
			ARRAY_FILTER_USE_BOTH
		);

		$result = (new Validator())->validate(json_decode(json_encode($object, JSON_THROW_ON_ERROR)), $schema);
		if ($result->isValid() === true) {
			return [];
		}

		return (new ErrorFormatter())->format($result->error());
	}//end schemaErrors()

	/**
	 * Build the service over the shared store.
	 *
	 * @return array{PurchaseOrderService}
	 */
	private function services(): array {
		$this->store = new InMemoryObjectServiceStub();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'shillinq',
				'require_supplier_qualification_for_po', 'require_approved_requisition_for_po' => 'false',
				default => $default,
			}
		);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('currentUserId')->willReturn('inkoper-1');
		$administrationContext->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'ADM-001'
		);

		$logger = $this->createMock(LoggerInterface::class);

		return [
			new PurchaseOrderService(
				appConfig: $appConfig,
				administrationContext: $administrationContext,
				logger: $logger,
				objectService: $this->store,
			),
		];
	}//end services()

	/**
	 * The payload of the live-pass request.
	 *
	 * @param float $unitPrice The line's unit price.
	 *
	 * @return array<string,mixed>
	 */
	private static function payload(float $unitPrice): array {
		return [
			'supplierId' => '5b0f7a64-2a61-4c1e-9d2b-7f3e8c1a9b20',
			'costCenter' => 'LP-CC',
			'projectCode' => 'LP-1738',
			'currency' => 'EUR',
			'lines' => [['productCode' => 'LP-1', 'glAccount' => '4500', 'description' => 'probe', 'quantity' => 1, 'unitPrice' => $unitPrice, 'vatRate' => 0.21]],
			'notes' => 'probe',
		];
	}//end payload()

	/**
	 * A created purchase order passes the schema and starts in the lifecycle's initial state.
	 *
	 * @return void
	 */
	public function testCreatedPurchaseOrderValidatesAgainstRegisterSchema(): void {
		[$purchaseOrders] = $this->services();

		$purchaseOrder = $purchaseOrders->createPurchaseOrder(administrationId: 'ADM-001', payload: self::payload(unitPrice: 500.0));

		self::assertSame([], $this->invalidPurchaseOrderSaves());
		self::assertSame('draft', $purchaseOrder['statusCode'] ?? null);
		// The declared approval chain routes on this field (task 1.1): it must
		// be written, as integer cents, or every order lands in no tier.
		self::assertSame(50000, $purchaseOrder['totalExclVat'] ?? null);
	}//end testCreatedPurchaseOrderValidatesAgainstRegisterSchema()

	/**
	 * Create, approve as OpenRegister's chain does (statusCode approved), send:
	 * every save validates.
	 *
	 * @return void
	 */
	public function testApprovedAndSentPurchaseOrderValidatesAgainstRegisterSchema(): void {
		[$purchaseOrders] = $this->services();

		$created = $purchaseOrders->createPurchaseOrder(administrationId: 'ADM-001', payload: self::payload(unitPrice: 12000.0));
		self::assertSame(1200000, $created['totalExclVat']);

		$approved = $created;
		$approved['statusCode'] = 'approved';
		$this->store->saveObject(object: $approved, schema: 'PurchaseOrder');

		$sent = $purchaseOrders->markSent(administrationId: 'ADM-001', purchaseOrderId: $created['id']);
		self::assertSame('sent', $sent['statusCode'] ?? null);

		self::assertSame([], $this->invalidPurchaseOrderSaves());
	}//end testApprovedAndSentPurchaseOrderValidatesAgainstRegisterSchema()

	/**
	 * Control: the schema does refuse a purchase order without statusCode, so the green above means something.
	 *
	 * @return void
	 */
	public function testSchemaRefusesAPurchaseOrderWithoutStatusCode(): void {
		$object = ['poNumber' => 'PO-1', 'supplierId' => 'S', 'currency' => 'EUR', 'administrationId' => 'ADM-001', 'lifecycleState' => 'draft'];

		self::assertNotSame([], self::schemaErrors(object: $object, schema: json_encode(self::purchaseOrderSchema(), JSON_THROW_ON_ERROR)));
	}//end testSchemaRefusesAPurchaseOrderWithoutStatusCode()
}//end class
