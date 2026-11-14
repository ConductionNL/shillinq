<?php

/**
 * Golden tests for the administration-scoped VAT listings (VATDeclarationController, VATLineController).
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
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\VATDeclarationController;
use OCA\Shillinq\Controller\VATLineController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins both listings, allowed and refused, and the per-administration queries behind them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class VatListingGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * The collaborators of one controller.
	 *
	 * @param array<string,mixed> $setup user (bool), administrations, rows (per administration, or Throwable).
	 *
	 * @return array{0:IRequest,1:ContainerInterface,2:IUserSession,3:AdministrationContextService,4:LoggerInterface}
	 */
	private function collaborators(array $setup): array {
		$store   = new class($this, ($setup['rows'] ?? [])) {
			/**
			 * The schema set last.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct.
			 *
			 * @param VatListingGoldenTest $test Recorder.
			 * @param mixed                $rows Rows per administration, or a failure.
			 */
			public function __construct(private VatListingGoldenTest $test, private mixed $rows) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param string $register Slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * Record.
			 *
			 * @param string $schema Slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $params): array {
				$this->test->calls[] = ['findAll', $this->schema, $params];
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				return ($this->rows[$params['filters']['administrationId']] ?? []);
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($store): object {
				$this->calls[] = ['get', $id];
				return $store;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('accessibleAdministrationIds')->willReturnCallback(
			function () use ($setup): array {
				$this->calls[] = ['accessibleAdministrationIds'];
				return ($setup['administrations'] ?? ['a1', 'a2']);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return [$this->createMock(IRequest::class), $container, $session, $context, $logger];
	}//end collaborators()

	/**
	 * The declaration listing.
	 *
	 * @param string              $returnId The return id.
	 * @param array<string,mixed> $setup    Collaborator setup.
	 *
	 * @return JSONResponse
	 */
	private function declarations(string $returnId, array $setup=[]): JSONResponse {
		[$request, $container, $session, $context, $logger] = $this->collaborators(setup: $setup);
		return (new VATDeclarationController($request, $container, $session, $context, $logger))->listByReturn($returnId);
	}//end declarations()

	/**
	 * A line listing.
	 *
	 * @param string              $action listByReturn or listByDeclaration.
	 * @param string              $id     The id.
	 * @param array<string,mixed> $setup  Collaborator setup.
	 *
	 * @return JSONResponse
	 */
	private function lines(string $action, string $id, array $setup=[]): JSONResponse {
		[$request, $container, $session, $context, $logger] = $this->collaborators(setup: $setup);
		return (new VATLineController($request, $container, $session, $context, $logger))->$action($id);
	}//end lines()

	/**
	 * Status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function answer(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * Declarations: 401, 400, then one query per membership, merged in order.
	 *
	 * @return void
	 */
	public function testDeclarations(): void {
		$actual = self::answer($this->declarations(returnId: 'r1', setup: ['user' => false]));
		self::assertSame(expected: [401, ['error' => 'Not logged in']], actual: $actual);
		self::assertSame(expected: [400, ['error' => 'returnId is required']], actual: self::answer($this->declarations(returnId: '')));
		self::assertSame(expected: [400, ['error' => 'returnId is required']], actual: self::answer($this->declarations(returnId: 'r/1')));
		self::assertSame(expected: [], actual: $this->calls);
		$rows = ['a1' => [['d' => 1]], 'a2' => [['d' => 2], ['d' => 3]]];
		$actual = self::answer($this->declarations(returnId: 'r1', setup: ['rows' => $rows]));
		self::assertSame(expected: [200, ['data' => [['d' => 1], ['d' => 2], ['d' => 3]], 'total' => 3]], actual: $actual);
		self::assertSame(
			expected: [
				['get', 'OCA\OpenRegister\Service\ObjectService'],
				['accessibleAdministrationIds'],
				['setRegister', 'shillinq'],
				['findAll', 'VATDeclaration', ['filters' => ['returnId' => 'r1', 'administrationId' => 'a1']]],
				['setRegister', 'shillinq'],
				['findAll', 'VATDeclaration', ['filters' => ['returnId' => 'r1', 'administrationId' => 'a2']]],
			],
			actual: $this->calls
		);
		$this->calls = [];
		$actual = self::answer($this->declarations(returnId: 'r1', setup: ['administrations' => []]));
		self::assertSame(expected: [200, ['data' => [], 'total' => 0]], actual: $actual);
		$actual = self::answer($this->declarations(returnId: 'r1', setup: ['rows' => new \RuntimeException('db')]));
		self::assertSame(expected: [500, ['error' => 'Failed to list declarations']], actual: $actual);
		$actual = end($this->calls);
		$expected = ['error', 'VATDeclarationController: failed to list declarations', ['returnId' => 'r1', 'exception' => 'db']];
		self::assertSame(expected: $expected, actual: $actual);
	}//end testDeclarations()

	/**
	 * Lines by return and by declaration: the same refusals, queries and failure line with their own key.
	 *
	 * @return void
	 */
	public function testLines(): void {
		$actual = self::answer($this->lines(action: 'listByReturn', id: 'r1', setup: ['user' => false]));
		self::assertSame(expected: [401, ['error' => 'Not logged in']], actual: $actual);
		self::assertSame(expected: [400, ['error' => 'returnId is required']], actual: self::answer($this->lines(action: 'listByReturn', id: '')));
		$actual = self::answer($this->lines(action: 'listByDeclaration', id: str_repeat('d', 65)));
		self::assertSame(expected: [400, ['error' => 'declarationId is required']], actual: $actual);
		self::assertSame(expected: [], actual: $this->calls);
		$rows = ['a2' => [['l' => 1]]];
		$actual = self::answer($this->lines(action: 'listByDeclaration', id: 'd1', setup: ['rows' => $rows]));
		self::assertSame(expected: [200, ['data' => [['l' => 1]], 'total' => 1]], actual: $actual);
		self::assertSame(
			expected: [
				['findAll', 'VATLine', ['filters' => ['declarationId' => 'd1', 'administrationId' => 'a1']]],
				['findAll', 'VATLine', ['filters' => ['declarationId' => 'd1', 'administrationId' => 'a2']]],
			],
			actual: array_values(array_filter($this->calls, fn (array $c): bool => $c[0] === 'findAll'))
		);
		$this->calls = [];
		$actual = self::answer($this->lines(action: 'listByReturn', id: 'r1', setup: ['rows' => new \RuntimeException('x')]));
		self::assertSame(expected: [500, ['error' => 'Failed to list VAT lines']], actual: $actual);
		$actual = end($this->calls);
		$expected = ['error', 'VATLineController: failed to list VAT lines', ['returnId' => 'r1', 'exception' => 'x']];
		self::assertSame(expected: $expected, actual: $actual);
	}//end testLines()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
