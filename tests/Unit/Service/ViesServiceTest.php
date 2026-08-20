<?php

/**
 * Unit tests for ViesService.
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
 * @spec openspec/changes/bookkeeping-icp-opgaaf/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ViesService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests VIES VAT-ID validation, canonicalisation, response parsing, outage
 * fallback (reuse of a recent valid record), and immutable evidence persistence.
 *
 * Covers REQ-ICP-001 (VAT-ID verification + audit proof) and REQ-ICP-009 (outage
 * vs definitive rejection, prior-valid reuse window).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ViesServiceTest extends TestCase {

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
	 * Mock IClientService.
	 *
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $clientService;

	/**
	 * The last ObjectService stub built (for save-capture assertions).
	 *
	 * @var object
	 */
	private object $lastStub;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build a ViesService whose ObjectService stub holds the given prior validations.
	 *
	 * @param array<int,array<string,mixed>> $priorValidations Existing ViesValidation rows.
	 *
	 * @return ViesService
	 */
	private function buildService(array $priorValidations = []): ViesService {
		$stub = new class($priorValidations) {

			/**
			 * Existing ViesValidation rows.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $rows;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Captured saved objects keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $saved = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $rows Existing ViesValidation rows.
			 */
			public function __construct(array $rows) {
				$this->rows = $rows;
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
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return ViesValidation rows matching the equality filters.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				if ($this->schema !== 'ViesValidation') {
					return [];
				}

				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						$this->rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Capture a saved object.
			 *
			 * @param array<string,mixed> $object The object.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$target = $schema;
				if ($target === '') {
					// The contract adapter passes the payload alone and applies
					// the caller's `schema:` through setSchema() first.
					$target = $this->schema;
				}

				$this->saved[$target][] = $object;
				return $object;
			}//end saveObject()
		};

		$this->container->method('get')->willReturn($stub);
		$this->lastStub = $stub;

		return new ViesService(
			appConfig: $this->appConfig,
			clientService: $this->clientService,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * VAT-IDs are canonicalised to upper-case, alphanumeric-only (REQ-ICP-001).
	 *
	 * @return void
	 */
	public function testCanonicalVatId(): void {
		$service = $this->buildService();
		self::assertSame('BE0123456789', $service->canonicalVatId(vatId: 'be 0123.456-789'));
		self::assertSame('DE123456789', $service->canonicalVatId(vatId: '  DE123456789  '));

	}//end testCanonicalVatId()

	/**
	 * A valid VIES REST response is parsed into a valid, non-outage outcome.
	 *
	 * @return void
	 */
	public function testParseValidResponse(): void {
		$service = $this->buildService();
		$body = json_encode(
			['valid' => true, 'name' => 'ACME BVBA', 'address' => 'Brussels', 'requestIdentifier' => 'WAPIAAAAX3p']
		);

		$outcome = $service->parseViesResponse(body: (string)$body, vatId: 'BE0123456789', now: '2026-06-15T10:00:00+00:00');

		self::assertTrue($outcome['valid']);
		self::assertFalse($outcome['outage']);
		self::assertSame('WAPIAAAAX3p', $outcome['requestId']);
		self::assertSame('ACME BVBA', $outcome['name']);
		// The validUntil window is +1 day from validationTimestamp.
		self::assertSame('2026-06-16T10:00:00+00:00', $outcome['validUntil']);

	}//end testParseValidResponse()

	/**
	 * A definitive VIES rejection parses as valid=false, outage=false (REQ-ICP-009).
	 *
	 * @return void
	 */
	public function testParseInvalidResponse(): void {
		$service = $this->buildService();
		$body = json_encode(['valid' => false, 'name' => '', 'address' => '']);

		$outcome = $service->parseViesResponse(body: (string)$body, vatId: 'BE0000000000', now: '2026-06-15T10:00:00+00:00');

		self::assertFalse($outcome['valid']);
		self::assertFalse($outcome['outage']);

	}//end testParseInvalidResponse()

	/**
	 * A transient MS_UNAVAILABLE envelope parses as an outage, not a rejection (REQ-ICP-009).
	 *
	 * @return void
	 */
	public function testParseOutageResponse(): void {
		$service = $this->buildService();
		$body = json_encode(['errorWrappers' => [['error' => 'MS_UNAVAILABLE']]]);

		$outcome = $service->parseViesResponse(body: (string)$body, vatId: 'BE0123456789', now: '2026-06-15T10:00:00+00:00');

		self::assertFalse($outcome['valid']);
		self::assertTrue($outcome['outage']);

	}//end testParseOutageResponse()

	/**
	 * On a network outage, a recent (< 30d) valid record is reused (REQ-ICP-009).
	 *
	 * @return void
	 */
	public function testValidateReusesPriorValidOnOutage(): void {
		$prior = [
			[
				'administrationId' => 'adm-1',
				'vatId' => 'BE0123456789',
				'valid' => true,
				'requestId' => 'PRIOR-REQ-1',
				'validationTimestamp' => '2026-06-10T08:00:00+00:00',
			],
		];
		$service = $this->buildService($prior);

		// Force the HTTP call to throw (network outage).
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->method('get')->willThrowException(new \RuntimeException('connection refused'));
		$this->clientService->method('newClient')->willReturn($client);

		$result = $service->validate(administrationId: 'adm-1', vatId: 'BE0123456789', now: '2026-06-20T09:00:00+00:00');

		self::assertTrue($result['outage']);
		self::assertTrue($result['valid']);
		self::assertTrue($result['reusedPrior']);
		self::assertSame('PRIOR-REQ-1', $result['requestId']);
		// Evidence is persisted (immutable record, REQ-ICP-001).
		self::assertTrue($result['saved']);
		self::assertNotEmpty($this->lastStub->saved['ViesValidation']);

	}//end testValidateReusesPriorValidOnOutage()

	/**
	 * On an outage with no recent valid prior, the result is a non-valid outage.
	 *
	 * @return void
	 */
	public function testValidateOutageWithoutPriorIsNotValid(): void {
		$service = $this->buildService([]);

		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->method('get')->willThrowException(new \RuntimeException('timeout'));
		$this->clientService->method('newClient')->willReturn($client);

		$result = $service->validate(administrationId: 'adm-1', vatId: 'BE0123456789', now: '2026-06-20T09:00:00+00:00');

		self::assertTrue($result['outage']);
		self::assertFalse($result['valid']);
		self::assertFalse($result['reusedPrior']);

	}//end testValidateOutageWithoutPriorIsNotValid()

	/**
	 * Build a compact ViesValidation row for the adm-1 / BE0123456789 fixture.
	 *
	 * @param bool $valid Whether the record is valid.
	 * @param string $requestId The VIES request identifier.
	 * @param string $ts The validation timestamp (ISO-8601).
	 *
	 * @return array<string,mixed>
	 */
	private function vrow(bool $valid, string $requestId, string $ts): array {
		return [
			'administrationId' => 'adm-1',
			'vatId' => 'BE0123456789',
			'valid' => $valid,
			'requestId' => $requestId,
			'validationTimestamp' => $ts,
		];

	}//end vrow()

	/**
	 * The findRecentValid lookup returns the newest valid record for a VAT-ID (REQ-ICP-008).
	 *
	 * @return void
	 */
	public function testFindRecentValidPicksNewest(): void {
		$rows = [
			$this->vrow(valid: true, requestId: 'OLD', ts: '2026-01-01T00:00:00+00:00'),
			$this->vrow(valid: true, requestId: 'NEW', ts: '2026-05-01T00:00:00+00:00'),
			$this->vrow(valid: false, requestId: 'NO', ts: '2026-06-01T00:00:00+00:00'),
		];
		$service = $this->buildService($rows);

		$found = $service->findRecentValid(administrationId: 'adm-1', vatId: 'BE0123456789');

		self::assertNotNull($found);
		self::assertSame('NEW', $found['requestId']);

	}//end testFindRecentValidPicksNewest()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
