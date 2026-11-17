<?php

/**
 * Unit tests for the shillinq.hours.book MCP tool.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Mcp;

use InvalidArgumentException;
use OCA\Shillinq\Kernel\Contracts\TimeIntakeServiceInterface;
use OCA\Shillinq\Mcp\McpAdministrationGate;
use OCA\Shillinq\Mcp\McpArgumentValidator;
use OCA\Shillinq\Mcp\McpHoursTools;
use OCA\Shillinq\Mcp\McpResult;
use OCA\Shillinq\Mcp\ShillinqToolProvider;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * hours.book books for the signed-in user, refuses where the time-intake route does, and is catalogued.
 */
#[CoversClass(McpHoursTools::class)]
#[UsesClass(McpAdministrationGate::class)]
#[UsesClass(McpResult::class)]
#[UsesClass(McpArgumentValidator::class)]
#[UsesClass(ShillinqToolProvider::class)]
class McpHoursToolsTest extends TestCase {

	private const ARGS = [
		'administrationId' => 'adm-1',
		'batchId' => 'b-1',
		'organisationRef' => 'org-1',
		'entries' => [['externalId' => 'e1', 'date' => '2026-11-02', 'minutes' => 90, 'description' => 'Review']],
	];

	/**
	 * The situations the tool faces.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: \Throwable|null, 3: string}>
	 */
	public static function situations(): array {
		return [
			'booked'                 => [true, true, null, 'ok'],
			'not signed in'          => [false, true, null, 'unauthorised'],
			'foreign administration' => [true, false, null, 'not_found'],
			'malformed batch'        => [true, true, new InvalidArgumentException('entries[0].minutes must be positive'), 'invalid_arguments'],
			'batch replayed'         => [true, true, new RuntimeException('Conflict: batchId "b-1" was already ingested with a different payload.'), 'conflict'],
			'not billable'           => [true, true, new RuntimeException('No rate for entry e1'), 'invalid_arguments'],
			'unexpected failure'     => [true, true, new \LogicException('db down'), 'failed'],
		];
	}//end situations()

	/**
	 * The outcome per situation, and only a permitted call reaches the intake, for the signed-in user.
	 *
	 * @param bool            $signedIn  Whether a user is signed in.
	 * @param bool            $canAccess Whether the administration is accessible.
	 * @param \Throwable|null $thrown    What the intake throws, or null.
	 * @param string          $expected  The outcome.
	 *
	 * @return void
	 */
	#[DataProvider('situations')]
	public function testTheToolBooksForTheSignedInUserOrRefuses(bool $signedIn, bool $canAccess, ?\Throwable $thrown, string $expected): void {
		$calls = [];
		$intake = $this->createMock(TimeIntakeServiceInterface::class);
		$intake->method('ingest')->willReturnCallback(
			function (string $administrationId, string $personId, array $body) use (&$calls, $thrown): array {
				$calls[] = [$administrationId, $personId, $body];
				if ($thrown !== null) {
					throw $thrown;
				}

				return ['invoiceId' => 'inv-1', 'invoiceNumber' => 'F-1', 'status' => 'draft', 'lines' => 1, 'duplicated' => false];
			}
		);

		$tool = $this->tools(signedIn: $signedIn, canAccess: $canAccess, intake: $intake)->book(self::ARGS);

		$this->assertSame($expected, McpResult::isError($tool) === true ? $tool['error'] : 'ok');
		if ($signedIn === false || $canAccess === false) {
			$this->assertSame([], $calls);
			return;
		}

		$body = self::ARGS;
		unset($body['administrationId']);
		$this->assertSame([['adm-1', 'alice', $body]], $calls);
		if ($expected === 'ok') {
			$this->assertSame('inv-1', $tool['booking']['invoiceId']);
		}
	}//end testTheToolBooksForTheSignedInUserOrRefuses()

	/**
	 * The catalogue declares hours.book as a create for the user's own hours, and its schema takes no person.
	 *
	 * @return void
	 */
	public function testTheToolIsCataloguedAsACreateOnTheUsersOwnHours(): void {
		$tool = array_values(array_filter(
			json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Mcp/tool-catalogue.json'), true),
			static fn (array $tool): bool => $tool['id'] === 'shillinq.hours.book'
		));

		$this->assertCount(1, $tool);
		$this->assertSame(['create', 'self', false], [$tool[0]['scope'], $tool[0]['reach'], $tool[0]['readOnlyHint']]);
		$this->assertArrayNotHasKey('personId', $tool[0]['inputSchema']['properties']);
		$this->assertSame(
			['personId is not an argument of this tool'],
			(new McpArgumentValidator())->problems(schema: $tool[0]['inputSchema'], arguments: self::ARGS + ['personId' => 'bob'])
		);
		$this->assertSame([], (new McpArgumentValidator())->problems(schema: $tool[0]['inputSchema'], arguments: self::ARGS));
	}//end testTheToolIsCataloguedAsACreateOnTheUsersOwnHours()

	/**
	 * The tools over a session and an administration context.
	 *
	 * @param bool                       $signedIn  Whether a user is signed in.
	 * @param bool                       $canAccess Whether adm-1 is accessible.
	 * @param TimeIntakeServiceInterface $intake    The intake.
	 *
	 * @return McpHoursTools The tools.
	 */
	private function tools(bool $signedIn, bool $canAccess, TimeIntakeServiceInterface $intake): McpHoursTools {
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $canAccess === true && $id === 'adm-1');

		return new McpHoursTools(new McpAdministrationGate($session, $context), $intake, $this->createMock(LoggerInterface::class));
	}//end tools()
}//end class
