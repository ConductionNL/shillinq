<?php

/**
 * Unit tests for LogCcmRuleEngineAdapter.
 *
 * Without an outbound rule-engine connector compiling is deferred and every
 * evaluation fails soft: the rule is reported as not fired.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\CcmRuleEngine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\External\CcmRuleEngine;

use OCA\Shillinq\Service\External\CcmRuleEngine\LogCcmRuleEngineAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Service\External\CcmRuleEngine\LogCcmRuleEngineAdapter
 * @covers \OCA\Shillinq\Service\External\CcmRuleEngine\CcmRuleEngineResult
 */
final class LogCcmRuleEngineAdapterTest extends TestCase {

	/**
	 * The info lines the adapter logged, as [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line.
	 *
	 * @return LogCcmRuleEngineAdapter The adapter.
	 */
	private function adapter(): LogCcmRuleEngineAdapter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		return new LogCcmRuleEngineAdapter(logger: $logger);
	}//end adapter()

	/**
	 * Compiling is deferred and answers an AST handle.
	 *
	 * @return void
	 */
	public function testCompilingIsDeferred(): void {
		$result = $this->adapter()->compileRule('CCM-01', ['gt' => ['amount', 10000]]);

		$this->assertSame('DEFERRED', $result->status);
		$this->assertMatchesRegularExpression('/^ccm_ast_log_[0-9a-f]{14}$/', $result->evaluationId);
		$this->assertFalse($result->fired);
		$this->assertTrue($result->dormant);
		$this->assertSame('no-outbound-connector-bound', $result->extras['reason']);
		$this->assertStringStartsWith('compile deferred;', $result->diagnostics['note']);
		$this->assertSame(['astHandle' => $result->evaluationId, 'ruleCode' => 'CCM-01', 'ruleLogic' => ['gt' => ['amount', 10000]]], $this->logged[0][1]);
	}//end testCompilingIsDeferred()

	/**
	 * An evaluation fails soft: not fired, and says so.
	 *
	 * @return void
	 */
	public function testAnEvaluationFailsSoft(): void {
		$result = $this->adapter()->evaluate('ccm_ast_1', ['amount' => 25000]);

		$this->assertSame('DEFERRED', $result->status);
		$this->assertFalse($result->fired);
		$this->assertTrue($result->diagnostics['failSoft']);
		$this->assertTrue($result->dormant);
		$this->assertSame('no-outbound-connector-bound', $result->extras['reason']);
		$this->assertMatchesRegularExpression('/^ccm_eval_log_[0-9a-f]{14}$/', $result->evaluationId);
		$this->assertSame(['evaluationId' => $result->evaluationId, 'astHandle' => 'ccm_ast_1', 'transactionContext' => ['amount' => 25000]], $this->logged[0][1]);
	}//end testAnEvaluationFailsSoft()


	/**
	 * The adapter reports itself dormant.
	 *
	 * @return void
	 */
	public function testTheAdapterIsDormant(): void {
		$this->assertTrue($this->adapter()->isDormant());
		$this->assertSame([], $this->logged);
	}//end testTheAdapterIsDormant()
}//end class
