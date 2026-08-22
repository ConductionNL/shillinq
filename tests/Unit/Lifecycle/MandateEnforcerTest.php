<?php

/**
 * Unit tests for MandateEnforcer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\MandateEnforcer;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MandateEnforcer per REQ-VPL-002.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class MandateEnforcerTest extends TestCase {

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
	 * The guard under test.
	 *
	 * @var MandateEnforcer
	 */
	private MandateEnforcer $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new MandateEnforcer(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build a filter-aware ObjectService stub returning records by schema, honouring
	 * the exact-match filters in the findAll query.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records by schema.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * Map of schema name → record arrays.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			private array $recordsBySchema;

			/**
			 * Currently active schema name.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records by schema.
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
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return stubbed records matching the exact-match filters.
			 *
			 * @param array<string, mixed> $params Query parameters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				$records = ($this->recordsBySchema[$this->currentSchema] ?? []);
				$filters = ($params['filters'] ?? []);

				return array_values(
					array_filter(
						$records,
						static function (array $record) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($record[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);

			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * Stub the container to return the given ObjectService stub.
	 *
	 * @param object $objectService The ObjectService stub.
	 *
	 * @return void
	 */
	private function withObjectService(object $objectService): void {
		$this->container->method('get')->willReturn($objectService);

	}//end withObjectService()

	/**
	 * A valid inkoop mandate up to EUR 50.000 for the demo administration.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function mandate(array $overrides = []): array {
		return array_merge(
			[
				'administrationId' => 'adm-1',
				'mandateCode' => 'M-INKOOP-50K',
				'maximumAmount' => 5000000,
				'kind_commitment' => ['inkooporder', 'frameworkAgreement'],
				'is_override' => false,
				'valid_from' => '2020-01-01',
				'valid_to' => '2999-12-31',
				'required_second_signature_above' => null,
			],
			$overrides
		);

	}//end mandate()

	/**
	 * A commitment of the given soort and amount for the demo administration.
	 *
	 * @param string $kind Commitment soort.
	 * @param int $amount Amount in minor units.
	 *
	 * @return array<string,mixed>
	 */
	private function commitment(string $kind, int $amount): array {
		return [
			'administrationId' => 'adm-1',
			'commitmentNumber' => 'PO-1',
			'kind' => $kind,
			'total_amount_excl_vat' => $amount,
		];

	}//end commitment()

	/**
	 * REQ-VPL-002: a user with a sufficient mandate can sign within the limit.
	 *
	 * @return void
	 */
	public function testSufficientMandateWithinLimit(): void {
		$this->withObjectService($this->buildObjectServiceStub(['Mandate' => [$this->mandate()]]));

		$this->assertTrue(
			$this->guard->hasSufficientMandate('PO-1', $this->commitment('inkooporder', 3000000))
		);
		$this->assertFalse(
			$this->guard->requiresApproval('PO-1', $this->commitment('inkooporder', 3000000))
		);

	}//end testSufficientMandateWithinLimit()

	/**
	 * REQ-VPL-002: a commitment above the mandate ceiling requires approval.
	 *
	 * @return void
	 */
	public function testAmountExceedsMandateRequiresApproval(): void {
		$this->withObjectService($this->buildObjectServiceStub(['Mandate' => [$this->mandate()]]));

		$this->assertFalse(
			$this->guard->hasSufficientMandate('PO-1', $this->commitment('inkooporder', 7500000))
		);
		$this->assertTrue(
			$this->guard->requiresApproval('PO-1', $this->commitment('inkooporder', 7500000))
		);

	}//end testAmountExceedsMandateRequiresApproval()

	/**
	 * REQ-VPL-002: a soort not listed in the mandate does not apply.
	 *
	 * @return void
	 */
	public function testSoortNotCoveredRequiresApproval(): void {
		$this->withObjectService($this->buildObjectServiceStub(['Mandate' => [$this->mandate()]]));

		// An arbeidscontract is not in the mandate's soort_verplichting list.
		$this->assertFalse(
			$this->guard->hasSufficientMandate('PO-1', $this->commitment('arbeidscontract', 1000000))
		);

	}//end testSoortNotCoveredRequiresApproval()

	/**
	 * REQ-VPL-002: an expired mandate is treated as absent.
	 *
	 * @return void
	 */
	public function testExpiredMandateIsIgnored(): void {
		$expired = $this->mandate(['valid_to' => '2000-01-01']);
		$this->withObjectService($this->buildObjectServiceStub(['Mandate' => [$expired]]));

		$this->assertFalse(
			$this->guard->hasSufficientMandate('PO-1', $this->commitment('inkooporder', 1000000))
		);

	}//end testExpiredMandateIsIgnored()

	/**
	 * REQ-VPL-002: a not-yet-valid mandate is treated as absent.
	 *
	 * @return void
	 */
	public function testFutureMandateIsIgnored(): void {
		$future = $this->mandate(['valid_from' => '2999-01-01']);
		$this->withObjectService($this->buildObjectServiceStub(['Mandate' => [$future]]));

		$this->assertFalse(
			$this->guard->hasSufficientMandate('PO-1', $this->commitment('inkooporder', 1000000))
		);

	}//end testFutureMandateIsIgnored()

	/**
	 * REQ-VPL-002: a second signature is required above the mandate threshold.
	 *
	 * @return void
	 */
	public function testSecondSignatureRequiredAboveThreshold(): void {
		$mandate = $this->mandate(['required_second_signature_above' => 2500000]);
		$this->withObjectService($this->buildObjectServiceStub(['Mandate' => [$mandate]]));

		$this->assertTrue(
			$this->guard->requiresSecondSignature($this->commitment('inkooporder', 3000000))
		);
		$this->assertFalse(
			$this->guard->requiresSecondSignature($this->commitment('inkooporder', 2000000))
		);

	}//end testSecondSignatureRequiredAboveThreshold()

	/**
	 * Least-privilege: when several mandates apply, the lowest sufficient
	 * non-override ceiling is preferred.
	 *
	 * @return void
	 */
	public function testResolvesLeastPrivilegeNonOverrideMandate(): void {
		$small = $this->mandate(['mandateCode' => 'SMALL', 'maximumAmount' => 5000000]);
		$big = $this->mandate(['mandateCode' => 'BIG', 'maximumAmount' => 25000000]);
		$override = $this->mandate(['mandateCode' => 'OVR', 'maximumAmount' => 1000000000, 'is_override' => true]);

		$this->withObjectService($this->buildObjectServiceStub(['Mandate' => [$big, $override, $small]]));

		$resolved = $this->guard->resolveApplicableMandate($this->commitment('inkooporder', 3000000));
		$this->assertNotNull($resolved);
		$this->assertSame('SMALL', $resolved['mandateCode']);

	}//end testResolvesLeastPrivilegeNonOverrideMandate()

	/**
	 * No mandate at all means approval is required.
	 *
	 * @return void
	 */
	public function testNoMandateRequiresApproval(): void {
		$this->withObjectService($this->buildObjectServiceStub(['Mandate' => []]));

		$this->assertTrue(
			$this->guard->requiresApproval('PO-1', $this->commitment('inkooporder', 1000000))
		);

	}//end testNoMandateRequiresApproval()

	/**
	 * Fail-closed: when the ObjectService throws, the commitment is treated as not
	 * mandated (CWE-863).
	 *
	 * @return void
	 */
	public function testFailClosedOnException(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('boom'));

		$this->assertFalse(
			$this->guard->hasSufficientMandate('PO-1', $this->commitment('inkooporder', 1000000))
		);

	}//end testFailClosedOnException()
}//end class
