<?php

/**
 * Unit tests for LeaseReassessmentController.
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
 * @spec openspec/changes/revive-lease-capabilities/specs/revive-lease-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\LeaseReassessmentController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\LeaseAmortizationCalculator;
use OCA\Shillinq\Service\LeaseReassessmentService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Proves the wiring: each remeasurement endpoint invokes its record* method
 * and returns a balanced payload; anonymous callers get 401 and cross-tenant
 * scopes 404 (REQ-LEASE-REVIVE-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeaseReassessmentControllerTest extends TestCase {
	/**
	 * Build a controller with the given request params, auth and IDOR verdict.
	 *
	 * @param array<string,mixed> $params Request parameters.
	 * @param bool $loggedIn Whether a user session is present.
	 * @param bool $canAccess The administration IDOR verdict.
	 *
	 * @return LeaseReassessmentController
	 */
	private function buildController(array $params, bool $loggedIn = true, bool $canAccess = true): LeaseReassessmentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$userSession = $this->createMock(IUserSession::class);
		if ($loggedIn === true) {
			$userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturn($canAccess);

		return new LeaseReassessmentController(
			request: $request,
			reassessmentService: $this->buildService(),
			administrationContext: $administrationContext,
			userSession: $userSession,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildController()

	/**
	 * Build a real LeaseReassessmentService over an ObjectService stub seeded
	 * with one capitalised vehicle lease.
	 *
	 * @return LeaseReassessmentService
	 */
	private function buildService(): LeaseReassessmentService {
		$stub = new class([$this->leaseFixture()]) {

			/**
			 * LeaseContract records.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $leases;

			/**
			 * Last schema set, for the events read-back.
			 *
			 * @var string
			 */
			private string $lastSchema = '';

			/**
			 * Captured saved rows by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $saved = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $leases Lease records.
			 */
			public function __construct(array $leases) {
				$this->leases = $leases;
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
				$this->lastSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return leases / prior events matching the filter.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				if ($this->lastSchema === 'LeaseReassessmentEvent') {
					return ($this->saved['LeaseReassessmentEvent'] ?? []);
				}

				$admin = ($params['filters']['administrationId'] ?? null);
				if ($admin === null) {
					return $this->leases;
				}

				return array_values(
					array_filter(
						$this->leases,
						static fn (array $lease): bool => ($lease['administrationId'] ?? null) === $admin
					)
				);
			}//end findAll()

			/**
			 * Capture saveObject calls; echo the row back.
			 *
			 * Register and schema are optional because the real contract declares
			 * them optional: a caller that has already narrowed the scope through
			 * setRegister()/setSchema() passes the payload alone. When the schema
			 * is omitted the row is filed under the last one selected, so
			 * findAll() still finds it.
			 *
			 * @param array<string,mixed> $object The row.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$key = $schema;
				if ($key === '') {
					$key = $this->lastSchema;
				}

				$this->saved[$key] = ($this->saved[$key] ?? []);
				$this->saved[$key][] = $object;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new LeaseReassessmentService(
			appConfig: $appConfig,
			calculator: new LeaseAmortizationCalculator(),
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * A baseline capitalised vehicle lease fixture (administration adm-1).
	 *
	 * @return array<string,mixed>
	 */
	private function leaseFixture(): array {
		return [
			'@self' => ['slug' => 'lease-v', 'id' => 'lease-v'],
			'leaseNumber' => 'VH-2024-001',
			'assetClass' => 'vehicle',
			'classification' => 'IFRS16-capitalised',
			'status' => 'active',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'paymentCurrency' => 'EUR',
			'ibrPercent' => 4.0,
			'administrationId' => 'adm-1',
			'extensionOptions' => [],
		];

	}//end leaseFixture()

	/**
	 * Assert the payload's GL lines balance (sum debit == sum credit).
	 *
	 * @param array<string,mixed> $payload The persisted event payload.
	 *
	 * @return void
	 */
	private function assertGlBalanced(array $payload): void {
		self::assertArrayHasKey('glLines', $payload);
		$debit = 0.0;
		$credit = 0.0;
		foreach ($payload['glLines'] as $line) {
			if (($line['side'] ?? '') === 'debit') {
				$debit += (float)$line['amount'];
				continue;
			}

			$credit += (float)$line['amount'];
		}

		self::assertGreaterThan(0.0, $debit, 'GL lines must not be empty');
		self::assertEqualsWithDelta($debit, $credit, 0.01, 'GL lines must balance');

	}//end assertGlBalanced()

	/**
	 * Indexation endpoint records a balanced catch-up remeasurement.
	 *
	 * @return void
	 */
	public function testIndexationPostsBalancedEvent(): void {
		$controller = $this->buildController(
			[
				'lease_id' => 'lease-v',
				'administration_id' => 'adm-1',
				'new_payment_amount' => 1100.0,
				'approver' => 'person-1',
			]
		);

		$response = $controller->indexation();
		self::assertSame(Http::STATUS_CREATED, $response->getStatus());

		$payload = $response->getData();
		self::assertSame('indexation-remeasurement', $payload['eventType']);
		self::assertNotSame($payload['preEventLeaseLiability'], $payload['postEventLeaseLiability']);
		// Catch-up: RoU adjustment mirrors the liability delta.
		self::assertGreaterThan(0.0, (float)$payload['rouAssetAdjustment']);
		$this->assertGlBalanced($payload);

	}//end testIndexationPostsBalancedEvent()

	/**
	 * Impairment endpoint records a balanced write-down.
	 *
	 * @return void
	 */
	public function testImpairmentPostsBalancedEvent(): void {
		$controller = $this->buildController(
			[
				'lease_id' => 'lease-v',
				'administration_id' => 'adm-1',
				'recoverable_value' => 5000.0,
			]
		);

		$response = $controller->impairment();
		self::assertSame(Http::STATUS_CREATED, $response->getStatus());

		$payload = $response->getData();
		self::assertSame('impairment', $payload['eventType']);
		// A write-down to below carrying value books a P&L loss.
		self::assertLessThan(0.0, (float)$payload['rouAssetAdjustment']);
		self::assertGreaterThan(0.0, (float)$payload['plImpact']);
		$this->assertGlBalanced($payload);

	}//end testImpairmentPostsBalancedEvent()

	/**
	 * Modification endpoint records a balanced payment-modification event.
	 *
	 * @return void
	 */
	public function testModificationPostsBalancedEvent(): void {
		$controller = $this->buildController(
			[
				'lease_id' => 'lease-v',
				'administration_id' => 'adm-1',
				'new_terms' => ['basePaymentAmount' => 1200.0],
			]
		);

		$response = $controller->modification();
		self::assertSame(Http::STATUS_CREATED, $response->getStatus());

		$payload = $response->getData();
		self::assertSame('payment-modification', $payload['eventType']);
		$this->assertGlBalanced($payload);

	}//end testModificationPostsBalancedEvent()

	/**
	 * Extension-option endpoint records an event (persisted).
	 *
	 * @return void
	 */
	public function testExtensionOptionPostsEvent(): void {
		$controller = $this->buildController(
			[
				'lease_id' => 'lease-v',
				'administration_id' => 'adm-1',
				'extension_options' => [['periodsMonths' => 12, 'exerciseLikelihood' => 'reasonably-certain']],
			]
		);

		$response = $controller->extensionOption();
		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('extension-option-reassessment', $response->getData()['eventType']);

	}//end testExtensionOptionPostsEvent()

	/**
	 * An anonymous caller is rejected with 401 before any work.
	 *
	 * @return void
	 */
	public function testAnonymousIsRejected(): void {
		$controller = $this->buildController(
			['lease_id' => 'lease-v', 'administration_id' => 'adm-1', 'new_payment_amount' => 1100.0],
			loggedIn: false
		);

		$response = $controller->indexation();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousIsRejected()

	/**
	 * A cross-tenant administration is masked as 404, no event persisted.
	 *
	 * @return void
	 */
	public function testCrossTenantIsMasked(): void {
		$controller = $this->buildController(
			['lease_id' => 'lease-v', 'administration_id' => 'adm-X', 'new_payment_amount' => 1100.0],
			canAccess: false
		);

		$response = $controller->indexation();
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testCrossTenantIsMasked()

	/**
	 * A lease invisible in the caller's administration is 404 (IDOR-safe).
	 *
	 * @return void
	 */
	public function testUnknownLeaseIs404(): void {
		$controller = $this->buildController(
			['lease_id' => 'no-such-lease', 'administration_id' => 'adm-1', 'new_payment_amount' => 1100.0]
		);

		$response = $controller->indexation();
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUnknownLeaseIs404()
}//end class
