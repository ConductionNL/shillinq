<?php

/**
 * Golden tests for BankRuleController: refusals, the line-ownership guard, lookups, payloads and log lines.
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
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BankRuleController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BankRulePreviewService;
use OCA\Shillinq\Service\BankRuleSuggestionService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the four actions, allowed and refused, and every store call behind them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BankRuleControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $setup user (bool), params, context, rows (per schema), lines (by id), fail (Throwable), saved.
	 *
	 * @return BankRuleController
	 */
	private function controller(array $setup=[]): BankRuleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($setup): mixed {
				$this->calls[] = ['getParam', $key];
				if (array_key_exists($key, ($setup['params'] ?? [])) === true) {
					return $setup['params'][$key];
				}

				return $default;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$admin = $this->createMock(AdministrationContextService::class);
		$admin->method('buildContext')->willReturnCallback(
			function () use ($setup): array {
				$this->calls[] = ['buildContext'];
				return ($setup['context'] ?? ['activeAdministrationId' => 'adm-1']);
			}
		);
		$fail    = ($setup['fail'] ?? null);
		$preview = $this->createMock(BankRulePreviewService::class);
		$preview->method('previewRule')->willReturnCallback(
			function (array $rule, array $candidateLines, ?string $anchorDate) use ($fail): array {
				$this->calls[] = ['previewRule', $rule, $candidateLines, $anchorDate];
				if ($fail !== null) {
					throw $fail;
				}

				return ['matched' => 2];
			}
		);
		$preview->method('suggestForLine')->willReturnCallback(
			function (array $line, array $activeRules): ?array {
				$this->calls[] = ['suggestForLine', $line, $activeRules];
				return ['gl' => '4000'];
			}
		);
		$suggestion = $this->createMock(BankRuleSuggestionService::class);
		$suggestion->method('suggestRulesFromHistory')->willReturnCallback(
			function (array $history, int $k, ?object $aiRanker): array {
				$this->calls[] = ['suggestRulesFromHistory', $history, $k, $aiRanker];
				return ['p'];
			}
		);
		$store  = new class($this, $setup) {
			/**
			 * The schema set last.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct.
			 *
			 * @param BankRuleControllerGoldenTest $test  Recorder.
			 * @param array<string,mixed>          $setup Rows and lines.
			 */
			public function __construct(private BankRuleControllerGoldenTest $test, private array $setup) {
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
			public function findAll(array $params=[]): array {
				$this->test->calls[] = ['findAll', $this->schema, $params];
				return ($this->setup['rows'][$this->schema] ?? []);
			}//end findAll()

			/**
			 * Record and answer.
			 *
			 * @param string $id Id.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				$this->test->calls[] = ['find', $this->schema, $id];
				$line = ($this->setup['lines'][$id] ?? null);
				if ($line instanceof \Throwable) {
					throw $line;
				}

				return $line;
			}//end find()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $object Payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->test->calls[] = ['saveObject', $this->schema, $object];
				if (isset($this->setup['saveFails']) === true) {
					throw $this->setup['saveFails'];
				}

				return $object + ['id' => 'new-rule'];
			}//end saveObject()
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['error', $message];
			}
		);

		return new BankRuleController($request, $preview, $suggestion, $admin, new DuckObjectServiceAdapter($store), $session, $logger);
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
	 * Every action throws the forbidden exception without a session user, before reading anything.
	 *
	 * @return void
	 */
	public function testAnonymousForbidden(): void {
		foreach (['preview', 'suggestAccount', 'suggestions', 'acceptSuggestion'] as $action) {
			try {
				$this->controller(setup: ['user' => false])->$action();
				self::fail(message: $action . ' did not refuse');
			} catch (OCSForbiddenException $e) {
				self::assertSame(expected: 'authenticated session required for bank-rule operations', actual: $e->getMessage());
			}
		}

		self::assertSame(expected: [], actual: $this->calls);
	}//end testAnonymousForbidden()

	/**
	 * Preview: rule and predicate refusals, anchorDate normalisation, the candidate query and the failure line.
	 *
	 * @return void
	 */
	public function testPreview(): void {
		$noRule = [400, ['error' => 'rule (object with predicates) is required']];
		self::assertSame(expected: $noRule, actual: self::answer($this->controller(setup: ['params' => ['rule' => 'x']])->preview()));
		$noPredicates = [400, ['error' => 'rule.predicates must be a non-empty array']];
		$actual = self::answer($this->controller(setup: ['params' => ['rule' => ['predicates' => []]]])->preview());
		self::assertSame(expected: $noPredicates, actual: $actual);
		$actual = self::answer($this->controller(setup: ['params' => ['rule' => ['predicates' => 'p']]])->preview());
		self::assertSame(expected: $noPredicates, actual: $actual);
		$this->calls = [];
		$rule = ['predicates' => [['f' => 'x']]];
		$rows = ['BankStatementLine' => [['id' => 'l1'], 'scalar', ['id' => 'l2']]];
		$actual = self::answer($this->controller(setup: ['params' => ['rule' => $rule, 'anchorDate' => 5], 'rows' => $rows])->preview());
		self::assertSame(expected: [200, ['matched' => 2]], actual: $actual);
		self::assertSame(
			expected: [
				['getParam', 'rule'],
				['getParam', 'anchorDate'],
				['buildContext'],
				['setRegister', 'shillinq'],
				['findAll', 'BankStatementLine', ['filters' => ['administrationId' => 'adm-1', 'matchState' => 'unmatched'], 'limit' => 500]],
				['previewRule', $rule, [['id' => 'l1'], ['id' => 'l2']], null],
			],
			actual: $this->only(kinds: ['getParam', 'buildContext', 'setRegister', 'findAll', 'previewRule'])
		);
		$this->controller(setup: ['params' => ['rule' => $rule, 'anchorDate' => '2026-01-01']])->preview();
		self::assertSame(expected: '2026-01-01', actual: end($this->calls)[3]);
		$response = $this->controller(setup: ['params' => ['rule' => $rule], 'fail' => new \RuntimeException('p1')])->preview();
		self::assertSame(expected: [500, ['error' => 'preview failed; see server log']], actual: self::answer($response));
		self::assertSame(expected: ['error', 'BankRuleController.preview failed: p1'], actual: end($this->calls));
	}//end testPreview()

	/**
	 * Suggest-account: a missing line and a line of another administration are the same 404; an own line gets a suggestion.
	 *
	 * @return void
	 */
	public function testSuggestAccountOwnership(): void {
		$actual = self::answer($this->controller(setup: ['params' => ['lineId' => '  ']])->suggestAccount());
		self::assertSame(expected: [400, ['error' => 'lineId is required']], actual: $actual);
		$notFound = [404, ['error' => 'bank line L9 not found']];
		self::assertSame(expected: $notFound, actual: self::answer($this->controller(setup: ['params' => ['lineId' => ' L9 ']])->suggestAccount()));
		$foreign = ['params' => ['lineId' => 'L9'], 'lines' => ['L9' => ['administrationId' => 'adm-2']]];
		self::assertSame(expected: $notFound, actual: self::answer($this->controller(setup: $foreign)->suggestAccount()));
		self::assertSame(expected: [], actual: $this->only(kinds: ['suggestForLine']));
		$this->calls = [];
		$own = ['params' => ['lineId' => 'L9'], 'lines' => ['L9' => ['administrationId' => 'adm-1', 'x' => 1]], 'rows' => ['MatchingRule' => [['r' => 1]]]];
		self::assertSame(expected: [200, ['suggestion' => ['gl' => '4000']]], actual: self::answer($this->controller(setup: $own)->suggestAccount()));
		self::assertSame(
			expected: [
				['find', 'BankStatementLine', 'L9'],
				['findAll', 'MatchingRule', ['filters' => ['administrationId' => 'adm-1', 'lifecycleState' => 'active'], 'limit' => 500]],
				['suggestForLine', ['administrationId' => 'adm-1', 'x' => 1], [['r' => 1]]],
			],
			actual: $this->only(kinds: ['find', 'findAll', 'suggestForLine'])
		);
		$this->calls = [];
		$broken = ['params' => ['lineId' => 'L9'], 'lines' => ['L9' => new \RuntimeException('gone')]];
		self::assertSame(expected: $notFound, actual: self::answer($this->controller(setup: $broken)->suggestAccount()));
	}//end testSuggestAccountOwnership()

	/**
	 * Suggestions: the threshold, and history built only from confirmed matches with a GL target and a readable line.
	 *
	 * @return void
	 */
	public function testSuggestionsHistory(): void {
		$matches = [
			['targetRefs' => ['4000'], 'bankLineRefs' => ['L1']],
			['targetRefs' => []],
			['targetRefs' => 'x', 'bankLineRefs' => ['L1']],
			['targetRefs' => ['4100'], 'bankLineRefs' => []],
			['targetRefs' => [''], 'bankLineRefs' => ['L1']],
			['targetRefs' => ['4200'], 'bankLineRefs' => ['MISSING']],
			['targetRefs' => ['4300', '9'], 'bankLineRefs' => ['L2', 'L1']],
		];
		$setup = [
			'params' => ['k' => '0'],
			'rows' => ['ReconciliationMatch' => $matches],
			'lines' => ['L1' => ['counterpartyName' => 'Acme', 'counterpartyIban' => 'NL1'], 'L2' => []],
		];
		$actual = self::answer($this->controller(setup: $setup)->suggestions());
		self::assertSame(expected: [200, ['suggestions' => ['p'], 'threshold' => 3]], actual: $actual);
		self::assertSame(
			expected: [
				[
					'findAll',
					'ReconciliationMatch',
					['filters' => ['administrationId' => 'adm-1', 'state' => 'confirmed', 'targetType' => 'gl-transaction'], 'limit' => 500],
				],
				['find', 'BankStatementLine', 'L1'],
				['find', 'BankStatementLine', 'MISSING'],
				['find', 'BankStatementLine', 'L2'],
				[
					'suggestRulesFromHistory',
					[
						['counterpartyName' => 'Acme', 'counterpartyIban' => 'NL1', 'targetType' => 'gl-transaction', 'targetGlAccount' => '4000'],
						['counterpartyName' => '', 'counterpartyIban' => '', 'targetType' => 'gl-transaction', 'targetGlAccount' => '4300'],
					],
					3,
					null,
				],
			],
			actual: $this->only(kinds: ['findAll', 'find', 'suggestRulesFromHistory'])
		);
		$this->calls = [];
		$this->controller(setup: ['params' => ['k' => '7']])->suggestions();
		self::assertSame(expected: 7, actual: end($this->calls)[2]);
		$this->controller()->suggestions();
		self::assertSame(expected: 3, actual: end($this->calls)[2]);
	}//end testSuggestionsHistory()

	/**
	 * Accept: required inputs, the saved payload and its field order, the created answer and the failure line.
	 *
	 * @return void
	 */
	public function testAcceptSuggestion(): void {
		$required = [400, ['error' => 'ruleName and a non-empty predicates array are required']];
		$actual = self::answer($this->controller(setup: ['params' => ['predicates' => ['a']]])->acceptSuggestion());
		self::assertSame(expected: $required, actual: $actual);
		$actual = self::answer($this->controller(setup: ['params' => ['ruleName' => 'R', 'predicates' => []]])->acceptSuggestion());
		self::assertSame(expected: $required, actual: $actual);
		self::assertSame(
			expected: [['getParam', 'ruleName'], ['getParam', 'predicates'], ['getParam', 'targetType'], ['getParam', 'targetGlAccount']],
			actual: array_slice($this->calls, 0, 4)
		);
		$this->calls = [];
		$params = ['ruleName' => ' R1 ', 'predicates' => ['x' => ['p']], 'targetGlAccount' => ' 4000 ', 'priority' => '7x'];
		$payload = [
			'ruleName' => 'R1',
			'priority' => 7,
			'targetType' => 'gl-transaction',
			'predicates' => [['p']],
			'autoConfirm' => false,
			'administrationId' => 'adm-1',
			'lifecycleState' => 'active',
			'targetGlAccount' => '4000',
		];
		$actual = self::answer($this->controller(setup: ['params' => $params])->acceptSuggestion());
		self::assertSame(expected: [201, $payload + ['id' => 'new-rule']], actual: $actual);
		self::assertSame(
			expected: [
				['getParam', 'ruleName'],
				['getParam', 'predicates'],
				['getParam', 'targetType'],
				['getParam', 'targetGlAccount'],
				['buildContext'],
				['getParam', 'priority'],
				['setRegister', 'shillinq'],
				['saveObject', 'MatchingRule', $payload],
			],
			actual: $this->only(kinds: ['getParam', 'buildContext', 'setRegister', 'saveObject'])
		);
		$this->calls = [];
		$minimal = ['params' => ['ruleName' => 'R', 'predicates' => ['p'], 'targetType' => ' iban '], 'context' => ['activeAdministrationId' => '']];
		$this->controller(setup: $minimal)->acceptSuggestion();
		self::assertSame(
			expected: [
				'ruleName' => 'R',
				'priority' => 100,
				'targetType' => 'iban',
				'predicates' => ['p'],
				'autoConfirm' => false,
				'administrationId' => 'default',
				'lifecycleState' => 'active',
			],
			actual: $this->only(kinds: ['saveObject'])[0][2]
		);
		$failed = ['params' => ['ruleName' => 'R', 'predicates' => ['p']], 'saveFails' => new \RuntimeException('s1')];
		$actual = self::answer($this->controller(setup: $failed)->acceptSuggestion());
		self::assertSame(expected: [500, ['error' => 'accept failed; see server log']], actual: $actual);
		self::assertSame(expected: ['error', 'BankRuleController.acceptSuggestion failed: s1'], actual: end($this->calls));
	}//end testAcceptSuggestion()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
