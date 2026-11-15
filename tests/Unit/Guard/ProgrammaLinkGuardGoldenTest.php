<?php

/**
 * Golden tests for ProgrammaLinkGuard: the three programme rules, the stored-line lookup and log lines.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-provincies-bbv-variant/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\ProgrammaLinkGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins every answer, lookup and log line of the GLLine save precondition.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ProgrammaLinkGuardGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Run the precondition once.
	 *
	 * @param array<string,mixed> $glLine The GL line.
	 * @param mixed               $stored find()'s answer for the stored line, or a Throwable it throws.
	 *
	 * @return bool
	 */
	private function check(array $glLine, mixed $stored=null): bool {
		$store     = new class($this, $stored) {
			/**
			 * Construct.
			 *
			 * @param ProgrammaLinkGuardGoldenTest $test   Recorder.
			 * @param mixed                        $stored Answer.
			 */
			public function __construct(private ProgrammaLinkGuardGoldenTest $test, private mixed $stored) {
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
				$this->test->calls[] = ['setSchema', $schema];
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param string $id Id.
			 *
			 * @return mixed
			 */
			public function find(string $id): mixed {
				$this->test->calls[] = ['find', $id];
				if ($this->stored instanceof \Throwable) {
					throw $this->stored;
				}

				return $this->stored;
			}//end find()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		return (new ProgrammaLinkGuard($container, $appConfig, $logger))->validateOnSave($glLine);
	}//end check()

	/**
	 * Unmapped lines pass without anything; a non-canonical programme is refused with its raw value.
	 *
	 * @return void
	 */
	public function testUnmappedAndNonCanonical(): void {
		self::assertTrue(condition: $this->check(glLine: []));
		self::assertTrue(condition: $this->check(glLine: ['programmeStructure' => '']));
		self::assertSame(expected: [], actual: $this->calls);
		self::assertFalse(condition: $this->check(glLine: ['programmeStructure' => 7]));
		self::assertFalse(condition: $this->check(glLine: ['programmeStructure' => 'Ruimte']));
		self::assertSame(
			expected: [
				['warning', 'ProgrammaLinkGuard: rejecting non-canonical BBV provincie programme', ['programmeStructure' => 7]],
				['warning', 'ProgrammaLinkGuard: rejecting non-canonical BBV provincie programme', ['programmeStructure' => 'Ruimte']],
			],
			actual: $this->calls
		);
	}//end testUnmappedAndNonCanonical()

	/**
	 * The effective date: absent passes, malformed or future is refused, today passes.
	 *
	 * @return void
	 */
	public function testEffectiveDate(): void {
		self::assertTrue(condition: $this->check(glLine: ['programmeStructure' => 'water', 'programmeAssignedAt' => '']));
		$result = $this->check(glLine: ['programmeStructure' => 'water', 'programmeAssignedAt' => (new \DateTimeImmutable('today'))->format('Y-m-d')]);
		self::assertTrue(condition: $result);
		self::assertFalse(condition: $this->check(glLine: ['programmeStructure' => 'water', 'programmeAssignedAt' => '2026-13-01']));
		self::assertFalse(condition: $this->check(glLine: ['programmeStructure' => 'water', 'programmeAssignedAt' => '2999-01-01']));
		self::assertSame(
			expected: [
				['warning', 'ProgrammaLinkGuard: rejecting future programmaAssignedAt', ['programmeAssignedAt' => '2026-13-01']],
				['warning', 'ProgrammaLinkGuard: rejecting future programmaAssignedAt', ['programmeAssignedAt' => '2999-01-01']],
			],
			actual: $this->calls
		);
	}//end testEffectiveDate()

	/**
	 * Re-mapping a stored line to another programme is refused; the same one, an unmapped one, or a failed lookup pass.
	 *
	 * @return void
	 */
	public function testPriorAssignment(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * Serialise.
			 *
			 * @return array<string,string>
			 */
			public function jsonSerialize(): array {
				return ['programmeStructure' => 'milieu'];
			}//end jsonSerialize()
		};
		self::assertFalse(condition: $this->check(glLine: ['programmeStructure' => 'water', '@self' => ['id' => 'g1']], stored: $entity));
		self::assertSame(
			expected: [
				['setRegister', 'shillinq'],
				['setSchema', 'GLLine'],
				['find', 'g1'],
				['warning', 'ProgrammaLinkGuard: rejecting conflicting programme re-assignment (double-mapping)', ['target' => 'water']],
			],
			actual: $this->calls
		);
		self::assertTrue(condition: $this->check(glLine: ['programmeStructure' => 'milieu', 'id' => 'g1'], stored: $entity));
		self::assertTrue(condition: $this->check(glLine: ['programmeStructure' => 'water', 'id' => 'g1'], stored: ['programmeStructure' => '']));
		self::assertTrue(condition: $this->check(glLine: ['programmeStructure' => 'water', 'id' => 'g1'], stored: ['x' => 1]));
		self::assertTrue(condition: $this->check(glLine: ['programmeStructure' => 'water', 'id' => ''], stored: $entity));
		$this->calls = [];
		self::assertTrue(condition: $this->check(glLine: ['programmeStructure' => 'water', 'id' => 5], stored: new \RuntimeException('gone')));
		self::assertSame(
			expected: ['debug', 'ProgrammaLinkGuard: GLLine lookup unavailable — treating as unmapped', ['id' => '5', 'exception' => 'gone']],
			actual: end($this->calls)
		);
		self::assertFalse(condition: $this->check(glLine: ['programmeStructure' => 'water', 'id' => 'g2'], stored: ['programmeStructure' => 3]));
	}//end testPriorAssignment()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
