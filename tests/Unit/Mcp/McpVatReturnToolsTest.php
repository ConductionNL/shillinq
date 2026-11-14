<?php

/**
 * shillinq.vat_return.* refuse exactly where their REST twins do.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Mcp;

use OCA\Shillinq\Controller\VATReturnController;
use OCA\Shillinq\Kernel\Contracts\VATReturnServiceInterface;
use OCA\Shillinq\Mcp\McpAdministrationGate;
use OCA\Shillinq\Mcp\McpResult;
use OCA\Shillinq\Mcp\McpVatReturnTools;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The tools and VATReturnController run against the same session,
 * administration access and service, and must agree on every outcome.
 */
#[CoversClass(McpVatReturnTools::class)]
#[CoversClass(McpAdministrationGate::class)]
#[CoversClass(McpResult::class)]
#[UsesClass(VATReturnController::class)]
class McpVatReturnToolsTest extends TestCase {

	/**
	 * HTTP status of the REST twin to the tool's outcome.
	 */
	private const OUTCOME = [200 => 'ok', 201 => 'ok', 400 => 'invalid_arguments', 401 => 'unauthorised', 404 => 'not_found', 409 => 'conflict', 500 => 'failed'];

	private const ARGS = ['administrationId' => 'adm-1', 'returnId' => 'ret-7'];

	/**
	 * The service double.
	 *
	 * @var VATReturnServiceInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private VATReturnServiceInterface $service;

	/**
	 * Calls that reached a lifecycle method of the service.
	 *
	 * @var list<string>
	 */
	private array $serviceCalls = [];

	/**
	 * The situations a submit or rebase faces.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: array<string, mixed>|null, 3: \Throwable|null, 4: string}>
	 */
	public static function situations(): array {
		$draft = ['id' => 'ret-7', 'administrationId' => 'adm-1', 'statusCode' => 'draft'];
		return [
			'submitted'              => [true, true, $draft, null, 'ok'],
			'not signed in'          => [false, true, $draft, null, 'unauthorised'],
			'foreign administration' => [true, false, $draft, null, 'not_found'],
			'return not found'       => [true, true, null, null, 'not_found'],
			'lifecycle refusal'      => [true, true, $draft, new RuntimeException('Only draft returns can be submitted'), 'conflict'],
			'unexpected failure'     => [true, true, $draft, new \LogicException('db down'), 'failed'],
		];
	}//end situations()

	/**
	 * Submit: both surfaces reach the same outcome, and only a permitted call reaches the service.
	 *
	 * @param bool                      $signedIn  Whether a user is signed in.
	 * @param bool                      $canAccess Whether adm-1 is accessible.
	 * @param array<string, mixed>|null $found     The return findReturn() yields.
	 * @param \Throwable|null           $thrown    What submitReturn() throws.
	 * @param string                    $expected  The outcome both must reach.
	 *
	 * @return void
	 */
	#[DataProvider('situations')]
	public function testSubmitRefusesExactlyWhereTheControllerDoes(bool $signedIn, bool $canAccess, ?array $found, ?\Throwable $thrown, string $expected): void {
		[$controller, $tools] = $this->surfaces(signedIn: $signedIn, canAccess: $canAccess, found: $found);
		$this->service->method('submitReturn')->willReturnCallback(
			function (string $returnId, string $userId) use ($thrown): array {
				$this->serviceCalls[] = $returnId . ':' . $userId;
				if ($thrown !== null) {
					throw $thrown;
				}

				return ['id' => $returnId, 'statusCode' => 'submitted'];
			}
		);

		$rest = self::OUTCOME[$controller->submit('ret-7')->getStatus()];
		$tool = $tools->submit(self::ARGS);

		$this->assertSame($expected, $rest, 'the REST twin');
		$this->assertSame($expected, McpResult::isError($tool) === true ? $tool['error'] : 'ok', 'the tool');
		$reached = ($expected === 'ok' || $thrown !== null);
		$this->assertSame($reached === true ? ['ret-7:alice', 'ret-7:alice'] : [], $this->serviceCalls);
		if ($expected === 'ok') {
			$this->assertSame(['vatReturn' => ['id' => 'ret-7', 'statusCode' => 'submitted']], $tool);
		}
	}//end testSubmitRefusesExactlyWhereTheControllerDoes()

	/**
	 * Rebase: both surfaces reach the same outcome.
	 *
	 * @param bool                      $signedIn  Whether a user is signed in.
	 * @param bool                      $canAccess Whether adm-1 is accessible.
	 * @param array<string, mixed>|null $found     The return findReturn() yields.
	 * @param \Throwable|null           $thrown    What rebaseReturn() throws.
	 * @param string                    $expected  The outcome both must reach.
	 *
	 * @return void
	 */
	#[DataProvider('situations')]
	public function testRebaseRefusesExactlyWhereTheControllerDoes(bool $signedIn, bool $canAccess, ?array $found, ?\Throwable $thrown, string $expected): void {
		[$controller, $tools] = $this->surfaces(signedIn: $signedIn, canAccess: $canAccess, found: $found);
		$this->service->method('rebaseReturn')->willReturnCallback(
			function (string $returnId, string $userId) use ($thrown): array {
				$this->serviceCalls[] = $returnId . ':' . $userId;
				if ($thrown !== null) {
					throw $thrown;
				}

				return ['id' => $returnId, 'statusCode' => 'draft'];
			}
		);

		$this->assertSame($expected, self::OUTCOME[$controller->rebase('ret-7')->getStatus()], 'the REST twin');
		$tool = $tools->rebase(self::ARGS);
		$this->assertSame($expected, McpResult::isError($tool) === true ? $tool['error'] : 'ok', 'the tool');
	}//end testRebaseRefusesExactlyWhereTheControllerDoes()

	/**
	 * A return of another administration than the one the call names is the
	 * masked not_found, even when the caller may reach both, and nothing runs.
	 *
	 * @return void
	 */
	public function testAReturnOfAnotherAdministrationIsNotFound(): void {
		[, $tools] = $this->surfaces(signedIn: true, canAccess: true, found: ['id' => 'ret-7', 'administrationId' => 'adm-2', 'statusCode' => 'draft']);
		$this->service->expects($this->never())->method('submitReturn');

		$this->assertSame(McpResult::error(code: 'not_found', message: McpVatReturnTools::NOT_FOUND), $tools->submit(self::ARGS));
	}//end testAReturnOfAnotherAdministrationIsNotFound()

	/**
	 * Nothing is loaded for an anonymous caller.
	 *
	 * @return void
	 */
	public function testNothingLoadsForAnAnonymousCaller(): void {
		[, $tools] = $this->surfaces(signedIn: false, canAccess: true, found: null);
		$this->service->expects($this->never())->method('findReturn');

		$this->assertSame('unauthorised', $tools->rebase(self::ARGS)['error']);
	}//end testNothingLoadsForAnAnonymousCaller()

	/**
	 * Destroy deletes a draft through OpenRegister, as the controller does.
	 *
	 * @return void
	 */
	public function testDestroyDeletesADraft(): void {
		$objects = new class {
			/**
			 * @var list<string>
			 */
			public array $calls = [];

			public function setRegister(string $register): self {
				$this->calls[] = 'register:' . $register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->calls[] = 'schema:' . $schema;
				return $this;
			}

			public function deleteObject(string $id): bool {
				$this->calls[] = 'delete:' . $id;
				return true;
			}
		};
		[, $tools] = $this->surfaces(signedIn: true, canAccess: true, found: ['id' => 'ret-7', 'administrationId' => 'adm-1', 'statusCode' => 'draft'], objects: $objects);

		$this->assertSame(['id' => 'ret-7', 'deleted' => true], $tools->destroy(self::ARGS));
		$this->assertSame(['register:shillinq', 'schema:BtwAangifte', 'delete:ret-7'], $objects->calls);
	}//end testDestroyDeletesADraft()

	/**
	 * Destroy refuses a submitted return with the controller's conflict, and touches nothing.
	 *
	 * @return void
	 */
	public function testDestroyRefusesASubmittedReturnAsTheControllerDoes(): void {
		$found = ['id' => 'ret-7', 'administrationId' => 'adm-1', 'statusCode' => 'submitted'];
		[$controller, $tools] = $this->surfaces(signedIn: true, canAccess: true, found: $found);

		$this->assertSame(409, $controller->destroy('ret-7')->getStatus());
		$this->assertSame(McpResult::error(code: 'conflict', message: 'Only draft returns can be deleted'), $tools->destroy(self::ARGS));
	}//end testDestroyRefusesASubmittedReturnAsTheControllerDoes()

	/**
	 * Destroy of a foreign return is the masked not_found, as the controller's 404.
	 *
	 * @return void
	 */
	public function testDestroyMasksAForeignReturn(): void {
		$found = ['id' => 'ret-7', 'administrationId' => 'adm-1', 'statusCode' => 'draft'];
		[$controller, $tools] = $this->surfaces(signedIn: true, canAccess: false, found: $found);

		$this->assertSame(404, $controller->destroy('ret-7')->getStatus());
		$this->assertSame('not_found', $tools->destroy(self::ARGS)['error']);
	}//end testDestroyMasksAForeignReturn()

	/**
	 * Create passes the period through to the service for a permitted administration.
	 *
	 * @return void
	 */
	public function testCreateRunsTheService(): void {
		[, $tools] = $this->surfaces(signedIn: true, canAccess: true, found: null);
		$this->service->expects($this->once())->method('createReturn')
			->with('adm-1', 'quarter', 2024, 3, 'kor')
			->willReturn(['id' => 'ret-new']);

		$result = $tools->create(['administrationId' => 'adm-1', 'period' => 'quarter', 'periodYear' => 2024, 'periodNumber' => 3, 'regime' => 'kor']);

		$this->assertSame(['vatReturn' => ['id' => 'ret-new']], $result);
	}//end testCreateRunsTheService()

	/**
	 * Create refuses a foreign administration before the period check, as the controller does.
	 *
	 * @return void
	 */
	public function testCreateRefusesAForeignAdministrationFirst(): void {
		[, $tools] = $this->surfaces(signedIn: true, canAccess: false, found: null);
		$this->service->expects($this->never())->method('createReturn');

		$result = $tools->create(['administrationId' => 'adm-1', 'period' => 'quarter', 'periodYear' => 2099, 'periodNumber' => 9, 'regime' => 'kor']);

		$this->assertSame(McpResult::error(code: 'not_found', message: McpVatReturnTools::ADMINISTRATION_NOT_FOUND), $result);
	}//end testCreateRefusesAForeignAdministrationFirst()

	/**
	 * Create refuses an impossible or future period with the controller's wording.
	 *
	 * @return void
	 */
	public function testCreateRefusesAFuturePeriod(): void {
		[, $tools] = $this->surfaces(signedIn: true, canAccess: true, found: null);
		$this->service->expects($this->never())->method('createReturn');

		$result = $tools->create(['administrationId' => 'adm-1', 'period' => 'year', 'periodYear' => 2099, 'periodNumber' => 1, 'regime' => 'standard']);

		$this->assertSame(McpResult::error(code: 'invalid_arguments', message: 'Cannot create returns for future periods'), $result);
	}//end testCreateRefusesAFuturePeriod()

	/**
	 * The period rules, against a fixed today.
	 *
	 * @return array<string, array{0: string, 1: int, 2: int, 3: string|null}>
	 */
	public static function periods(): array {
		return [
			'past quarter'          => ['quarter', 2026, 2, null],
			'current quarter'       => ['quarter', 2026, 3, null],
			'next quarter'          => ['quarter', 2026, 4, 'Cannot create returns for future periods'],
			'fifth quarter'         => ['quarter', 2025, 5, 'periodNumber must be 1..4 for quarter'],
			'current month'         => ['month', 2026, 8, null],
			'next month'            => ['month', 2026, 9, 'Cannot create returns for future periods'],
			'thirteenth month'      => ['month', 2025, 13, 'periodNumber must be 1..12 for month'],
			'current year'          => ['year', 2026, 1, null],
			'next year'             => ['year', 2027, 1, 'Cannot create returns for future periods'],
			'last year, any month'  => ['month', 2025, 12, null],
		];
	}//end periods()

	/**
	 * The period rules match the controller's, today being 2026-08-15.
	 *
	 * @param string      $period   The period kind.
	 * @param int         $year     The year.
	 * @param int         $number   The period number.
	 * @param string|null $expected The refusal, or null.
	 *
	 * @return void
	 */
	#[DataProvider('periods')]
	public function testThePeriodRules(string $period, int $year, int $number, ?string $expected): void {
		$this->assertSame($expected, McpVatReturnTools::periodProblem(period: $period, periodYear: $year, periodNumber: $number, today: '2026-08-15'));
	}//end testThePeriodRules()

	/**
	 * The controller and the tools over the same doubles.
	 *
	 * @param bool                      $signedIn  Whether a user is signed in.
	 * @param bool                      $canAccess Whether adm-1 is accessible.
	 * @param array<string, mixed>|null $found     The return findReturn() yields.
	 * @param object|null               $objects   The ObjectService double.
	 *
	 * @return array{0: VATReturnController, 1: McpVatReturnTools}
	 */
	private function surfaces(bool $signedIn, bool $canAccess, ?array $found, ?object $objects = null): array {
		$this->service = $this->createMock(VATReturnServiceInterface::class);
		$this->service->method('findReturn')->willReturnCallback(static fn (string $id): ?array => $id === 'ret-7' ? $found : null);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $canAccess === true && in_array($id, ['adm-1', 'adm-2'], true));
		$container = $this->createMock(ContainerInterface::class);
		if ($objects !== null) {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		} else {
			$container->expects($this->never())->method('get');
		}

		$logger = $this->createMock(LoggerInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$controller = new VATReturnController($this->createMock(IRequest::class), $this->service, $container, $session, $context, $logger, $l10n);
		$tools = new McpVatReturnTools(new McpAdministrationGate($session, $context), $this->service, $container, $logger);

		return [$controller, $tools];
	}//end surfaces()
}//end class
