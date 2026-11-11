<?php

/**
 * Golden tests for DunningPreviewController: refusals, the scope checks, lookups and preview rows.
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
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Shillinq\Controller\DunningPreviewController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Dunning\DunningStageRenderer;
use OCA\Shillinq\Service\Dunning\DunningTemplateRegistry;
use OCA\Shillinq\Service\Dunning\DunningTickRunner;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins both previews, allowed and refused, and every scope check and lookup behind them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class DunningPreviewControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * The moment the clock answers.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $setup user, params, memberOf, records (schema => id => record), byProperty, language.
	 *
	 * @return DunningPreviewController
	 */
	private function controller(array $setup=[]): DunningPreviewController {
		$this->now = new DateTimeImmutable('2026-03-01T10:00:00Z');
		$request   = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default=null): mixed => ($setup['params'][$key] ?? $default));
		$context = $this->createMock(AdministrationContextService::class);
		$user    = 'u1';
		if (array_key_exists('user', $setup) === true) {
			$user = $setup['user'];
		}

		$context->method('currentUserId')->willReturn($user);
		$context->method('accessibleAdministrationIds')->willReturnCallback(
			function () use ($setup): array {
				$this->calls[] = ['accessibleAdministrationIds'];
				return ($setup['memberOf'] ?? []);
			}
		);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return in_array($administrationId, ($setup['memberOf'] ?? []), true);
			}
		);
		$runner = $this->createMock(DunningTickRunner::class);
		$runner->method('lastReportFor')->willReturnCallback(
			function (array $administration): ?array {
				$this->calls[] = ['lastReportFor', $administration['id'] ?? null];
				return ['sent' => 1];
			}
		);
		$runner->method('previewAdministration')->willReturnCallback(
			function (array $administration, DateTimeImmutable $now): array {
				$this->calls[] = ['previewAdministration', $administration['id'] ?? null, $now === $this->now];
				return [['row' => $administration['id'] ?? null]];
			}
		);
		$store     = new class($this, $setup) {
			/**
			 * The schema set last.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct.
			 *
			 * @param DunningPreviewControllerGoldenTest $test  Recorder.
			 * @param array<string,mixed>                $setup Records.
			 */
			public function __construct(private DunningPreviewControllerGoldenTest $test, private array $setup) {
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
			 * Find by id.
			 *
			 * @param string $id Id.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				$this->test->calls[] = ['find', $this->schema, $id];
				return ($this->setup['records'][$this->schema][$id] ?? null);
			}//end find()

			/**
			 * Find by the fallback property.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $params=[]): array {
				$this->test->calls[] = ['findAll', $this->schema, $params];
				$value = (string)array_values($params['filters'])[0];
				$found = ($this->setup['byProperty'][$this->schema][$value] ?? null);
				if ($found === null) {
					return [];
				}

				return [$found];
			}//end findAll()
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default=''): string => $default);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturnCallback(
			function (): DateTimeImmutable {
				$this->calls[] = ['now'];
				return $this->now;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn(($setup['language'] ?? 'en'));
		$l10n->method('t')->willReturnCallback(fn (string $text): string => 'T:' . $text);
		$renderer = new DunningStageRenderer(registry: new DunningTemplateRegistry(appConfig: $appConfig));

		return new DunningPreviewController(
			$request,
			$context,
			$runner,
			$renderer,
			new DuckObjectServiceAdapter($store),
			$appConfig,
			$time,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

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
	 * The calls of the given kinds.
	 *
	 * @param array<int,string> $kinds Call names.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function only(array $kinds): array {
		return array_values(array_filter($this->calls, fn (array $call): bool => in_array($call[0], $kinds, true)));
	}//end only()

	/**
	 * Next run: 401, 400 for a malformed id, and the masked 404 for an unknown or unreadable administration.
	 *
	 * @return void
	 */
	public function testNextRunRefusals(): void {
		$actual = self::answer($this->controller(setup: ['user' => null])->nextRun());
		self::assertSame(expected: [401, ['error' => 'Not authenticated']], actual: $actual);
		$malformed = [400, ['error' => 'administrationId must be a valid identifier']];
		$actual = self::answer($this->controller(setup: ['params' => ['administrationId' => 'a/b']])->nextRun());
		self::assertSame(expected: $malformed, actual: $actual);
		self::assertSame(expected: [], actual: $this->calls);
		$notFound = [404, ['error' => 'Administration not found']];
		$actual = self::answer($this->controller(setup: ['params' => ['administrationId' => ' ADM-9 ']])->nextRun());
		self::assertSame(expected: $notFound, actual: $actual);
		$record  = ['id' => 'r1', 'administrationCode' => 'ADM-1', 'uuid' => 'u-1'];
		$foreign = ['params' => ['administrationId' => 'ADM-1'], 'records' => ['Administration' => ['ADM-1' => $record]]];
		self::assertSame(expected: $notFound, actual: self::answer($this->controller(setup: $foreign)->nextRun()));
		self::assertSame(expected: [['canAccess', 'ADM-1'], ['canAccess', 'r1'], ['canAccess', 'u-1']], actual: $this->only(kinds: ['canAccess']));
		self::assertSame(expected: [], actual: $this->only(kinds: ['lastReportFor', 'previewAdministration']));
	}//end testNextRunRefusals()

	/**
	 * Next run over the caller's memberships: each administration once, missing and unreadable ones skipped.
	 *
	 * @return void
	 */
	public function testNextRunListing(): void {
		$records = [
			'Administration' => [
				'ADM-1' => ['id' => 'r1', 'administrationCode' => 'ADM-1', 'name' => 'One', DunningTickRunner::ENABLED_PROPERTY => true],
				'r1' => ['id' => 'r1', 'administrationCode' => 'ADM-1', 'name' => 'One'],
				'r2' => ['id' => 'r2', DunningTickRunner::ENABLED_PROPERTY => 'yes'],
				'r3' => ['id' => 'r3', 'administrationCode' => 'ADM-3'],
			],
		];
		$setup = [
			'memberOf' => ['ADM-1', 'r1', 'r2', 'gone', 'r3x'],
			'records' => $records,
			'byProperty' => ['Administration' => ['r3x' => ['id' => 'r3', 'administrationCode' => 'ADM-3']]],
		];
		self::assertSame(
			expected: [
				200,
				[
					'administrations' => [
						[
							'administrationId' => 'ADM-1',
							'id' => 'r1',
							'name' => 'One',
							'enabled' => true,
							'lastRun' => ['sent' => 1],
							'rows' => [['row' => 'r1'],
						]],
						[
							'administrationId' => 'r2',
							'id' => 'r2',
							'name' => '',
							'enabled' => false,
							'lastRun' => ['sent' => 1],
							'rows' => [['row' => 'r2'],
						]],
					],
				],
			],
			actual: self::answer($this->controller(setup: $setup)->nextRun())
		);
		self::assertSame(
			expected: [
				['accessibleAdministrationIds'],
				['now'],
				['find', 'Administration', 'ADM-1'],
				['canAccess', 'ADM-1'],
				['lastReportFor', 'r1'],
				['previewAdministration', 'r1', true],
				['find', 'Administration', 'r1'],
				['canAccess', 'ADM-1'],
				['find', 'Administration', 'r2'],
				['canAccess', 'r2'],
				['lastReportFor', 'r2'],
				['previewAdministration', 'r2', true],
				['find', 'Administration', 'gone'],
				['findAll', 'Administration', ['filters' => ['administrationCode' => 'gone'], 'limit' => 1]],
				['find', 'Administration', 'r3x'],
				['findAll', 'Administration', ['filters' => ['administrationCode' => 'r3x'], 'limit' => 1]],
				['canAccess', 'ADM-3'],
				['canAccess', 'r3'],
			],
			actual: $this->only(kinds: ['accessibleAdministrationIds', 'now', 'find', 'findAll', 'canAccess', 'lastReportFor', 'previewAdministration'])
		);
	}//end testNextRunListing()

	/**
	 * Ladder stages: 401, masked 404s, then the stages sorted by number with labels and texts in the caller's language.
	 *
	 * @return void
	 */
	public function testLadderStages(): void {
		self::assertSame(expected: 401, actual: $this->controller(setup: ['user' => null])->ladderStages('l1')->getStatus());
		self::assertSame(expected: [404, ['error' => 'Ladder not found']], actual: self::answer($this->controller()->ladderStages('l1')));
		$foreign = ['records' => ['DunningLadder' => ['l1' => ['administrationId' => 'A9']]], 'memberOf' => ['A1']];
		$actual = self::answer($this->controller(setup: $foreign)->ladderStages('l1'));
		self::assertSame(expected: [404, ['error' => 'Ladder not found']], actual: $actual);
		$this->calls = [];
		$stages = [
			['nr' => 2, 'daysAfterExpiryDate' => '14', 'channel' => 'REGISTERED_POST'],
			'junk',
			['nr' => 1, 'daysAfterExpiryDate' => 7],
			['nr' => 3, 'channel' => 'FAX'],
		];
		$own = ['records' => ['DunningLadder' => ['l1' => ['administrationId' => 'A1', 'stages' => $stages]]], 'memberOf' => ['A1'], 'language' => 'nl_NL'];
		$rows = $this->controller(setup: $own)->ladderStages('l1')->getData()['rows'];
		self::assertSame(expected: [1, 2, 3], actual: array_column($rows, 'nr'));
		self::assertSame(expected: [7, 14, 0], actual: array_column($rows, 'days'));
		self::assertSame(expected: ['EMAIL', 'REGISTERED_POST', 'FAX'], actual: array_column($rows, 'channel'));
		self::assertSame(expected: ['T:Email', 'T:Registered post', 'FAX'], actual: array_column($rows, 'channelLabel'));
		self::assertSame(expected: ['nr', 'days', 'channel', 'channelLabel', 'subject', 'body'], actual: array_keys($rows[0]));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default=''): string => $default);
		$renderer = new DunningStageRenderer(registry: new DunningTemplateRegistry(appConfig: $appConfig));
		$text     = $renderer->textFor(stage: ['nr' => 2, 'daysAfterExpiryDate' => '14', 'channel' => 'REGISTERED_POST'], language: 'nl');
		self::assertSame(expected: [$text['subject'], $text['body']], actual: [$rows[1]['subject'], $rows[1]['body']]);
		$actual = $this->only(kinds: ['setRegister', 'find', 'findAll', 'canAccess']);
		self::assertSame(expected: [['setRegister', 'shillinq'], ['find', 'DunningLadder', 'l1'], ['canAccess', 'A1']], actual: $actual);
	}//end testLadderStages()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
