<?php

/**
 * Unit tests for BankRuleController (REQ-BR-011 / REQ-BR-012).
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
 * @spec openspec/changes/bank-rule-automation-ux/specs/bookkeeping-bank-reconciliation/spec.md
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
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fluent stand-in for OCA\OpenRegister\Service\ObjectService — findAll by
 * filters, find by id, and a recording saveObject.
 */
final class FakeBankRuleObjectService {

	/**
	 * Rows per schema.
	 *
	 * @var array<string, list<array<string,mixed>>>
	 */
	private array $rows;

	/**
	 * Recorded saves.
	 *
	 * @var array<int, array{schema:string,payload:array<string,mixed>}>
	 */
	public array $saves = [];

	/**
	 * Current schema.
	 */
	private string $schema = '';

	/**
	 * @param array<string, list<array<string,mixed>>> $rows Seed rows per schema.
	 */
	public function __construct(array $rows) {
		$this->rows = $rows;

	}//end __construct()

	public function setRegister(string $r): self {
		return $this;
	}//end setRegister()

	public function setSchema(string $s): self {
		$this->schema = $s;
		return $this;
	}//end setSchema()

	/**
	 * @param array<string,mixed> $query Query with filters + limit.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function findAll(array $query): array {
		$filters = ($query['filters'] ?? []);
		$out = [];
		foreach (($this->rows[$this->schema] ?? []) as $row) {
			$ok = true;
			foreach ($filters as $key => $value) {
				if ((string)($row[$key] ?? '') !== (string)$value) {
					$ok = false;
					break;
				}
			}

			if ($ok === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end findAll()

	/**
	 * @return array<string,mixed>|null
	 */
	public function find(string $id): ?array {
		foreach (($this->rows[$this->schema] ?? []) as $row) {
			if ((string)($row['id'] ?? '') === $id) {
				return $row;
			}
		}

		return null;
	}//end find()

	/**
	 * @param array<string,mixed> $payload Save payload.
	 *
	 * @return array<string,mixed>
	 */
	public function saveObject(array $payload): array {
		$this->saves[] = ['schema' => $this->schema, 'payload' => $payload];
		return array_merge(['id' => 'new-rule-1'], $payload);
	}//end saveObject()
}//end class

/**
 * Verifies the controller wiring: preview reads unmatched lines and delegates,
 * acceptSuggestion performs exactly one MatchingRule write, and validation +
 * auth guards behave.
 *
 * @spec openspec/changes/bank-rule-automation-ux/specs/bookkeeping-bank-reconciliation/spec.md
 */
final class BankRuleControllerTest extends TestCase {

	/**
	 * Build a controller with a request stub + fake OR service.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @param FakeBankRuleObjectService|null $fake OR fake (null → empty).
	 * @param bool $authed Whether a user session exists.
	 *
	 * @return BankRuleController
	 */
	private function make(array $params, ?FakeBankRuleObjectService $fake = null, bool $authed = true): BankRuleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

		$fake = ($fake ?? new FakeBankRuleObjectService([]));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$admin = $this->createMock(AdministrationContextService::class);
		$admin->method('buildContext')->willReturn(['activeAdministrationId' => 'adm-1']);

		$session = $this->createMock(IUserSession::class);
		if ($authed === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		return new BankRuleController(
			$request,
			new BankRulePreviewService(),
			new BankRuleSuggestionService($this->createMock(LoggerInterface::class)),
			$admin,
			$container,
			$session,
			$this->createMock(LoggerInterface::class),
		);

	}//end make()

	/**
	 * preview reads unmatched lines for the administration and returns the
	 * matched set.
	 *
	 * @return void
	 */
	public function testPreviewWiresLinesToService(): void {
		$fake = new FakeBankRuleObjectService(
			[
				'BankStatementLine' => [
					['id' => 'L1', 'amount' => 500.0, 'counterpartyIban' => 'NL91ABNA0417164300', 'matchState' => 'unmatched', 'administrationId' => 'adm-1'],
					['id' => 'L2', 'amount' => 9.0, 'counterpartyIban' => 'NL91ABNA0417164300', 'matchState' => 'unmatched', 'administrationId' => 'adm-1'],
					['id' => 'L3', 'amount' => 500.0, 'counterpartyIban' => 'NL91ABNA0417164300', 'matchState' => 'confirmed', 'administrationId' => 'adm-1'],
				],
			]
		);

		$controller = $this->make(
			[
				'rule' => ['predicates' => [['op' => 'counterparty-iban', 'iban' => 'NL91ABNA0417164300'], ['op' => 'amount-range', 'min' => 100, 'max' => 2000]]],
			],
			$fake,
		);

		$response = $controller->preview();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		// Only L1 + L2 are unmatched; of those only L1 is in range.
		self::assertSame(['L1'], $data['matchedLineIds']);
		self::assertSame(2, $data['totalEvaluated']);

	}//end testPreviewWiresLinesToService()

	/**
	 * preview rejects a missing/empty predicate list.
	 *
	 * @return void
	 */
	public function testPreviewRejectsEmptyPredicates(): void {
		$controller = $this->make(['rule' => ['predicates' => []]]);
		$response = $controller->preview();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testPreviewRejectsEmptyPredicates()

	/**
	 * acceptSuggestion performs EXACTLY ONE MatchingRule write, server-stamped
	 * with the administration + active state — a suggestion never auto-applies.
	 *
	 * @return void
	 */
	public function testAcceptSuggestionCreatesExactlyOneRule(): void {
		$fake = new FakeBankRuleObjectService([]);
		$controller = $this->make(
			[
				'ruleName' => 'Acme B.V. → 4000',
				'predicates' => [['op' => 'counterparty-iban', 'iban' => 'NL91ABNA0417164300']],
				'targetType' => 'gl-transaction',
				'targetGlAccount' => '4000',
			],
			$fake,
		);

		$response = $controller->acceptSuggestion();
		self::assertSame(Http::STATUS_CREATED, $response->getStatus());

		self::assertCount(1, $fake->saves);
		self::assertSame('MatchingRule', $fake->saves[0]['schema']);
		$payload = $fake->saves[0]['payload'];
		self::assertSame('adm-1', $payload['administrationId']);
		self::assertSame('active', $payload['lifecycleState']);
		self::assertFalse($payload['autoConfirm']);
		self::assertSame('4000', $payload['targetGlAccount']);

	}//end testAcceptSuggestionCreatesExactlyOneRule()

	/**
	 * acceptSuggestion rejects a payload missing the required shape.
	 *
	 * @return void
	 */
	public function testAcceptSuggestionRejectsBadPayload(): void {
		$controller = $this->make(['ruleName' => '', 'predicates' => []]);
		$response = $controller->acceptSuggestion();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testAcceptSuggestionRejectsBadPayload()

	/**
	 * suggestAccount returns the matching rule's GL account for a line.
	 *
	 * @return void
	 */
	public function testSuggestAccountReturnsGlAccount(): void {
		$fake = new FakeBankRuleObjectService(
			[
				'BankStatementLine' => [
					['id' => 'bl-1', 'amount' => 200.0, 'counterpartyIban' => 'NL91ABNA0417164300', 'administrationId' => 'adm-1'],
				],
				'MatchingRule' => [
					['id' => 'r1', 'ruleName' => 'Acme', 'priority' => 10, 'targetType' => 'gl-transaction', 'targetGlAccount' => '4000', 'lifecycleState' => 'active', 'administrationId' => 'adm-1', 'predicates' => [['op' => 'counterparty-iban', 'iban' => 'NL91ABNA0417164300']]],
				],
			]
		);

		$controller = $this->make(['lineId' => 'bl-1'], $fake);
		$response = $controller->suggestAccount();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('4000', $data['suggestion']['targetGlAccount']);

	}//end testSuggestAccountReturnsGlAccount()

	/**
	 * An unauthenticated session is rejected (fail-closed).
	 *
	 * @return void
	 */
	public function testUnauthenticatedIsRejected(): void {
		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
		$controller = $this->make(['rule' => ['predicates' => [['op' => 'exact-amount', 'amount' => 1]]]], null, false);
		$controller->preview();

	}//end testUnauthenticatedIsRejected()
}//end class
