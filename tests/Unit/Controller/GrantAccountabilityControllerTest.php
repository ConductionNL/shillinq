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
use OCA\Shillinq\Kernel\Contracts\EuDeclarationBuilderInterface;
use OCA\Shillinq\Kernel\Contracts\GrantSettlementInterface;
use OCA\Shillinq\Kernel\Contracts\SisaAppendixInterface;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
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
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return GrantAccountabilityController
	 */
	private function controller(?string $user='u1', bool $member=true, ?\Throwable $fail=null, array $params=[]): GrantAccountabilityController {
		$entity = $this->createStub(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn(['id' => 'grant-1', 'administrationId' => 'gem-1', 'subsidyNumber' => 'SUB-1', 'grantedAmount' => 150000.0]);
		$entity->method('getUuid')->willReturn('grant-1');

		$project = $this->createStub(ObjectEntityInterface::class);
		$project->method('getObject')->willReturn(['id' => 'eu-1', 'administrationId' => 'gem-1', 'euCoFundingRate' => 0.4]);

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
			static function (int|string $id) use ($entity, $project, &$scope): ?ObjectEntityInterface {
				TestCase::assertSame('shillinq', $scope['register']);
				if ($id === 'grant-1' && $scope['schema'] === 'Subsidie') {
					return $entity;
				}

				if ($id === 'eu-1' && $scope['schema'] === 'EuProject') {
					return $project;
				}

				return null;
			}
		);

		$context = $this->createStub(AdministrationContextServiceInterface::class);
		$context->method('currentUserId')->willReturn($user);
		$context->method('buildContext')->willReturn(['activeAdministrationId' => 'gem-1']);
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

		$sisa = $this->createStub(SisaAppendixInterface::class);
		$sisa->method('forYear')->willReturnCallback(
			function (string $administrationId, string $fiscalYear) use ($fail): array {
				if ($fail !== null) {
					throw $fail;
				}

				$this->reported[] = $administrationId . '/' . $fiscalYear;
				return [['indicatorCode' => 'D8/01', 'value' => 120000.0]];
			}
		);
		$sisa->method('toCsv')->willReturn("\"Code\"\r\n");

		$declarations = $this->createStub(EuDeclarationBuilderInterface::class);
		$declarations->method('propose')->willReturnCallback(
			function (array $project, string $periodFrom, string $periodTo) use ($fail): array {
				if ($fail !== null) {
					throw $fail;
				}

				$this->reported[] = $project['id'] . ':' . $periodFrom . '..' . $periodTo;
				return ['id' => 'decl-1', 'state' => 'draft'];
			}
		);

		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		return new GrantAccountabilityController(
			request: $request,
			settlement: $settlement,
			sisa: $sisa,
			declarations: $declarations,
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
	/**
	 * The appendix of the active administration as JSON, and as CSV on request.
	 *
	 * @return void
	 */
	public function testTheSisaAppendixIsServedAsTableAndCsv(): void {
		$table = $this->controller(params: ['year' => '2026'])->sisa();
		self::assertInstanceOf(JSONResponse::class, $table);
		self::assertSame(200, $table->getStatus());
		self::assertSame(['year' => 2026, 'administrationId' => 'gem-1', 'rows' => [['indicatorCode' => 'D8/01', 'value' => 120000.0]]], $table->getData());

		$csv = $this->controller(params: ['year' => '2026', 'administrationId' => 'gem-1', 'format' => 'csv'])->sisa();
		self::assertInstanceOf(DataDownloadResponse::class, $csv);
		self::assertSame('text/csv', $csv->getHeaders()['Content-Type']);
		self::assertStringContainsString('sisa-2026.csv', $csv->getHeaders()['Content-Disposition']);
		self::assertSame(['gem-1/2026', 'gem-1/2026'], $this->reported);

	}//end testTheSisaAppendixIsServedAsTableAndCsv()

	/**
	 * Logged out 401, another administration 404, no year 400, a failure 500;
	 * none of the refusals reads the books.
	 *
	 * @return void
	 */
	public function testTheSisaAppendixRefusals(): void {
		self::assertSame(401, $this->controller(user: null, params: ['year' => '2026'])->sisa()->getStatus());
		self::assertSame(404, $this->controller(params: ['year' => '2026', 'administrationId' => 'gem-9'])->sisa()->getStatus());
		self::assertSame(404, $this->controller(member: false, params: ['year' => '2026'])->sisa()->getStatus());
		self::assertSame(400, $this->controller(params: ['year' => 'last'])->sisa()->getStatus());
		self::assertSame([], $this->reported);

		$failed = $this->controller(fail: new RuntimeException('down'), params: ['year' => '2026'])->sisa();
		self::assertSame(500, $failed->getStatus());

	}//end testTheSisaAppendixRefusals()
	/**
	 * A member proposes a declaration for a quarter, for given dates, or for
	 * the current quarter when nothing is given.
	 *
	 * @return void
	 */
	public function testAMemberProposesADeclarationForAQuarter(): void {
		$response = $this->controller(params: ['period' => '2026-Q3'])->proposeEuDeclaration(id: 'eu-1');
		self::assertSame(201, $response->getStatus());
		self::assertSame(['id' => 'decl-1', 'state' => 'draft'], $response->getData());

		$this->controller(params: ['periodFrom' => '2026-01-01', 'periodTo' => '2026-06-30'])->proposeEuDeclaration(id: 'eu-1');
		$this->controller(params: ['period' => '2024-Q1'])->proposeEuDeclaration(id: 'eu-1');
		$this->controller()->proposeEuDeclaration(id: 'eu-1');

		$quarter = intdiv(((int)gmdate('n')) + 2, 3);
		$start = sprintf('%s-%02d-01', gmdate('Y'), (($quarter - 1) * 3) + 1);
		self::assertSame(
			[
				'eu-1:2026-07-01..2026-09-30',
				'eu-1:2026-01-01..2026-06-30',
				'eu-1:2024-01-01..2024-03-31',
				'eu-1:' . $start . '..' . (new \DateTimeImmutable($start))->modify('+2 months')->format('Y-m-t'),
			],
			$this->reported
		);

	}//end testAMemberProposesADeclarationForAQuarter()

	/**
	 * Refusals of the proposal: 401, 404 for another administration's or a
	 * missing project, 400 for an unreadable quarter, 422 for a refusal in
	 * words and 500 for anything else.
	 *
	 * @return void
	 */
	public function testTheDeclarationRefusals(): void {
		self::assertSame(401, $this->controller(user: null)->proposeEuDeclaration(id: 'eu-1')->getStatus());
		self::assertSame(404, $this->controller(member: false)->proposeEuDeclaration(id: 'eu-1')->getStatus());
		self::assertSame(404, $this->controller()->proposeEuDeclaration(id: 'grant-1')->getStatus());
		self::assertSame(400, $this->controller(params: ['period' => '2026-Q5'])->proposeEuDeclaration(id: 'eu-1')->getStatus());
		self::assertSame([], $this->reported);

		$blocked = $this->controller(fail: new DomainException('An open irregularity on this project blocks new declarations until its correction is booked.'))
			->proposeEuDeclaration(id: 'eu-1');
		self::assertSame(422, $blocked->getStatus());
		self::assertSame(['message' => 'An open irregularity on this project blocks new declarations until its correction is booked.'], $blocked->getData());
		self::assertSame(500, $this->controller(fail: new RuntimeException('x'))->proposeEuDeclaration(id: 'eu-1')->getStatus());

	}//end testTheDeclarationRefusals()
}//end class
