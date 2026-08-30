<?php

/**
 * Unit tests for BarcodeLookupController.
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
 * @spec openspec/changes/inventory-barcode-sku/specs/inventory-barcode-sku/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BarcodeLookupController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BarcodeLookupController::lookup per REQ-SKU-007/008.
 *
 * Covers:
 * - Valid barcode returns 200 with barcode + product envelope.
 * - Unknown barcode returns 404.
 * - Inactive barcode returns 404 (REQ-SKU-008).
 * - UoM filter selects the carton GTIN-14 over the unit EAN.
 * - Missing API key (with a key configured) returns 401.
 * - Valid Bearer key authorizes the request.
 * - No configured key + anonymous caller returns 401 (fail-secure, ADR-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BarcodeLookupControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

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
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Configure app config: register slug 'shillinq' + the given API key.
	 *
	 * @param string $apiKey The configured POS API key ('' = none).
	 *
	 * @return void
	 */
	private function configureAppConfig(string $apiKey): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($apiKey): string {
				if ($key === 'barcode_lookup_api_key') {
					return $apiKey;
				}

				if ($key === 'register') {
					return 'shillinq';
				}

				return $default;
			}
		);

	}//end configureAppConfig()

	/**
	 * Wire the container to return a filter-aware ObjectService stub.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records by schema.
	 *
	 * @return void
	 */
	private function withRecords(array $recordsBySchema): void {
		$stub = new class($recordsBySchema) {

			/**
			 * Records keyed by schema.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			private array $recordsBySchema;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records.
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;

			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Filter the stubbed records by the provided exact-match filters.
			 *
			 * @param array<string, mixed> $params Query params with a 'filters' map.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				$records = ($this->recordsBySchema[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);

				return array_values(
					array_filter(
						$records,
						static function (array $record) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($record[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);

			}//end findAll()
		};

		$this->container->method('get')->willReturn($stub);

	}//end withRecords()

	/**
	 * Construct the controller under test.
	 *
	 * @return BarcodeLookupController
	 */
	private function controller(): BarcodeLookupController {
		return new BarcodeLookupController(
			request: $this->request,
			container: $this->container,
			appConfig: $this->appConfig,
			userSession: $this->userSession,
			logger: $this->logger,
		);

	}//end controller()

	/**
	 * Return the seeded EAN (unit) + GTIN-14 (carton) barcodes for the cat food SKU.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function catFoodBarcodes(): array {
		return [
			[
				'id' => 'barcode-001',
				'barcode' => '5410317126589',
				'barcodeType' => 'EAN',
				'format' => 'EAN-13',
				'productSku' => 'DV-KAT-SENIOR-2KG',
				'uomCode' => 'EA',
				'quantity' => 1,
				'isDefault' => true,
				'isActive' => true,
			],
			[
				'id' => 'barcode-002',
				'barcode' => '15410317126586',
				'barcodeType' => 'GTIN',
				'format' => 'GTIN-14',
				'productSku' => 'DV-KAT-SENIOR-2KG',
				'uomCode' => 'CA',
				'quantity' => 4,
				'isDefault' => false,
				'isActive' => true,
			],
		];
	}//end catFoodBarcodes()

	/**
	 * REQ-SKU-007: a valid barcode returns 200 with the barcode + product data.
	 *
	 * @return void
	 */
	public function testValidBarcodeReturns200WithProduct(): void {
		$this->configureAppConfig(apiKey: '');
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->withRecords(
			[
				'Barcode' => $this->catFoodBarcodes(),
				'Product' => [
					[
						'sku' => 'DV-KAT-SENIOR-2KG',
						'name' => 'Dragonvale Cat Senior 2kg',
						'category' => 'food_beverage',
					],
				],
			]
		);

		$response = $this->controller()->lookup(code: '5410317126589');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('5410317126589', $data['barcode']['barcode']);
		self::assertSame(1, $data['barcode']['quantity']);
		self::assertSame('DV-KAT-SENIOR-2KG', $data['product']['sku']);

	}//end testValidBarcodeReturns200WithProduct()

	/**
	 * REQ-SKU-007: an unknown barcode returns 404.
	 *
	 * @return void
	 */
	public function testUnknownBarcodeReturns404(): void {
		$this->configureAppConfig(apiKey: '');
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->withRecords(['Barcode' => $this->catFoodBarcodes()]);

		$response = $this->controller()->lookup(code: '9999999999999');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUnknownBarcodeReturns404()

	/**
	 * REQ-SKU-008: an inactive barcode is never returned (404).
	 *
	 * @return void
	 */
	public function testInactiveBarcodeReturns404(): void {
		$this->configureAppConfig(apiKey: '');
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->withRecords(
			[
				'Barcode' => [
					[
						'id' => 'barcode-004',
						'barcode' => 'OLD-VIT-001',
						'barcodeType' => 'INTERNAL',
						'format' => 'INTERNAL',
						'productSku' => 'VIT-C-1000MG-100CT',
						'uomCode' => 'EA',
						'quantity' => 1,
						'isDefault' => false,
						'isActive' => false,
					],
				],
			]
		);

		$response = $this->controller()->lookup(code: 'OLD-VIT-001');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testInactiveBarcodeReturns404()

	/**
	 * REQ-SKU-007: the UoM filter selects the carton GTIN-14 over the unit EAN.
	 *
	 * @return void
	 */
	public function testUomFilterSelectsCarton(): void {
		$this->configureAppConfig(apiKey: '');
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->withRecords(['Barcode' => $this->catFoodBarcodes()]);

		$response = $this->controller()->lookup(code: '15410317126586', uomCode: 'CA');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('GTIN', $data['barcode']['barcodeType']);
		self::assertSame(4, $data['barcode']['quantity']);
		self::assertSame('CA', $data['barcode']['uomCode']);

	}//end testUomFilterSelectsCarton()

	/**
	 * ADR-005: with an API key configured, a missing Bearer token returns 401.
	 *
	 * @return void
	 */
	public function testMissingApiKeyReturns401(): void {
		$this->configureAppConfig(apiKey: 'secret-pos-key');
		$this->request->method('getHeader')->with('Authorization')->willReturn('');

		$response = $this->controller()->lookup(code: '5410317126589');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testMissingApiKeyReturns401()

	/**
	 * ADR-005: a valid Bearer API key authorizes the lookup.
	 *
	 * @return void
	 */
	public function testValidApiKeyAuthorizes(): void {
		$this->configureAppConfig(apiKey: 'secret-pos-key');
		$this->request->method('getHeader')->with('Authorization')->willReturn('Bearer secret-pos-key');
		$this->withRecords(['Barcode' => $this->catFoodBarcodes()]);

		$response = $this->controller()->lookup(code: '5410317126589');

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testValidApiKeyAuthorizes()

	/**
	 * ADR-005 fail-secure: no API key configured + anonymous caller returns 401.
	 *
	 * @return void
	 */
	public function testNoKeyAnonymousReturns401(): void {
		$this->configureAppConfig(apiKey: '');
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller()->lookup(code: '5410317126589');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testNoKeyAnonymousReturns401()
}//end class
