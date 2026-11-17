<?php

/**
 * Tests for GrantAccountabilityController::report: the caller is logged in,
 * the grant is read as the caller, and another administration's grant is
 * answered as one that does not exist.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Controller;

use DomainException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Controller\GrantAccountabilityController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\GrantSettlementInterface;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the grant report endpoint.
 *
 * @covers \OCA\Shillinq\Controller\GrantAccountabilityController
 * @uses   \OCA\Shillinq\Support\RegisterSlug
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class GrantAccountabilityControllerTest extends TestCase {

	/**
	 * The ids the report was asked for.
	 *
	 * @var list<string>
	 */
	private array $reported = [];

	/**
	 * The controller over a store holding one grant of administration gem-1.
	 *
	 * @param string|null     $user   The logged-in user.
	 * @param bool            $member Whether the caller can access gem-1.
	 * @param \Throwable|null $fail   What the report throws.
	 *
	 * @return GrantAccountabilityController
	 */
	private function controller(?string $user='u1', bool $member=true, ?\Throwable $fail=null): GrantAccountabilityController {
		$entity = $this->createStub(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn(['id' => 'grant-1', 'administrationId' => 'gem-1', 'subsidyNumber' => 'SUB-1', 'grantedAmount' => 150000.0]);
		$entity->method('getUuid')->willReturn('grant-1');

		$scope = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnCallback(
			static function (string|int $register) use (&$scope, &$objects): ObjectServiceInterface {
				$scope['register'] = $register;
				return $objects;
			}
		);
		$objects->method('setSchema')->willReturnCallback(
			static function (string|int $schema) use (&$scope, &$objects): ObjectServiceInterface {
				$scope['schema'] = $schema;
				return $objects;
			}
		);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id) use ($entity, &$scope): ?ObjectEntityInterface {
				TestCase::assertSame(['register' => 'shillinq', 'schema' => 'Subsidie'], $scope);
				if ($id === 'grant-1') {
					return $entity;
				}

				return null;
			}
		);

		$context = $this->createStub(AdministrationContextServiceInterface::class);
		$context->method('currentUserId')->willReturn($user);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => ($member === true && $administrationId === 'gem-1')
		);

		$settlement = $this->createStub(GrantSettlementInterface::class);
		$settlement->method('report')->willReturnCallback(
			function (array $grant) use ($fail): array {
				if ($fail !== null) {
					throw $fail;
				}

				$this->reported[] = $grant['id'];
				return ['verantwoordingId' => 'sv-1', 'auditorStatementRequired' => true, 'auditorStatementId' => 'as-1'];
			}
		);

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new GrantAccountabilityController(
			request: $this->createStub(IRequest::class),
			settlement: $settlement,
			objectService: $objects,
			context: $context,
			appConfig: $config,
			l10n: $l10n,
			logger: $this->createStub(LoggerInterface::class)
		);

	}//end controller()

	/**
	 * A member reports on the grant: 201 with the report's ids.
	 *
	 * @return void
	 */
	public function testAMemberStoresTheReport(): void {
		$response = $this->controller()->report(id: 'grant-1');

		self::assertSame(201, $response->getStatus());
		self::assertSame(['verantwoordingId' => 'sv-1', 'auditorStatementRequired' => true, 'auditorStatementId' => 'as-1'], $response->getData());
		self::assertSame(['grant-1'], $this->reported);

	}//end testAMemberStoresTheReport()

	/**
	 * Logged out is 401; another administration's grant, a missing grant and an
	 * empty id are 404, and none of them reaches the report.
	 *
	 * @return void
	 */
	public function testRefusalsReachNoReport(): void {
		self::assertSame(401, $this->controller(user: null)->report(id: 'grant-1')->getStatus());
		self::assertSame(404, $this->controller(member: false)->report(id: 'grant-1')->getStatus());
		self::assertSame(404, $this->controller()->report(id: 'grant-9')->getStatus());
		self::assertSame(404, $this->controller()->report(id: ' ')->getStatus());
		self::assertSame([], $this->reported);

	}//end testRefusalsReachNoReport()

	/**
	 * A refusal of the report is a 422 in words; anything else a 500 without the cause.
	 *
	 * @return void
	 */
	public function testAFailedReportAnswersInWords(): void {
		$refused = $this->controller(fail: new DomainException('The grant has no grant number, so its report cannot be named.'))->report(id: 'grant-1');
		self::assertSame(422, $refused->getStatus());
		self::assertSame(['message' => 'The grant has no grant number, so its report cannot be named.'], $refused->getData());

		$failed = $this->controller(fail: new RuntimeException('database gone'))->report(id: 'grant-1');
		self::assertSame(500, $failed->getStatus());
		self::assertSame(['message' => 'The accountability report could not be stored.'], $failed->getData());

	}//end testAFailedReportAnswersInWords()
}//end class
