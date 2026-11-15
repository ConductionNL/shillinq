<?php

/**
 * Unit tests for ExtractionDraftCommitter.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Extraction
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

namespace OCA\Shillinq\Tests\Unit\Service\Extraction;

use OCA\Shillinq\Kernel\Contracts\GlAccountSuggestionClientInterface;
use OCA\Shillinq\Service\Extraction\ExtractionDraftCommitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The commit step saves the draft in the shillinq register and feeds a
 * booked account back only when both the extraction id and the account are known.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */
#[CoversClass(ExtractionDraftCommitter::class)]
class ExtractionDraftCommitterTest extends TestCase {

	/**
	 * The calls the fake ObjectService saw.
	 *
	 * @var list<array{0: string, 1: mixed}>
	 */
	private array $calls = [];

	/**
	 * A saved record with an extraction id and an account is fed back with its label.
	 *
	 * @return void
	 */
	public function testACommittedBookingIsSavedThenFedBack(): void {
		$saved = ['id' => 'rcp-1', 'docudeskExtractionId' => 'ext-9', 'glAccount' => ' 4500 ', 'glAccountLabel' => 'Travel'];
		$client = $this->createMock(GlAccountSuggestionClientInterface::class);
		$client->expects($this->once())->method('postCorrection')->with('ext-9', '4500', 'Travel')->willReturn([]);

		$record = $this->committer(answer: $saved, client: $client)->commit(schema: 'Receipt', draft: ['id' => 'rcp-1']);

		$this->assertSame($saved, $record);
		$this->assertSame([['setRegister', 'shillinq'], ['setSchema', 'Receipt'], ['saveObject', ['id' => 'rcp-1']]], $this->calls);
	}//end testACommittedBookingIsSavedThenFedBack()

	/**
	 * A save that answers no array leaves the draft as the record; no extraction id or no account feeds nothing back.
	 *
	 * @return void
	 */
	public function testNothingIsFedBackWithoutAnExtractionIdAndAnAccount(): void {
		$client = $this->createMock(GlAccountSuggestionClientInterface::class);
		$client->expects($this->never())->method('postCorrection');

		$draft = ['id' => 'rcp-1', 'glAccount' => '4500'];
		$this->assertSame($draft, $this->committer(answer: new \stdClass(), client: $client)->commit(schema: 'Receipt', draft: $draft));
		$this->committer(answer: null, client: $client)->feedBack(record: ['docudeskExtractionId' => 'ext-9', 'glAccount' => '  ']);
	}//end testNothingIsFedBackWithoutAnExtractionIdAndAnAccount()

	/**
	 * A label that is not a string is sent as none.
	 *
	 * @return void
	 */
	public function testANonStringLabelIsSentAsNone(): void {
		$client = $this->createMock(GlAccountSuggestionClientInterface::class);
		$client->expects($this->once())->method('postCorrection')->with('ext-9', '4500', null)->willReturn([]);

		$this->committer(answer: null, client: $client)->feedBack(record: ['docudeskExtractionId' => 'ext-9', 'glAccount' => '4500', 'glAccountLabel' => 7]);
	}//end testANonStringLabelIsSentAsNone()

	/**
	 * A refused save is thrown and nothing is fed back.
	 *
	 * @return void
	 */
	public function testARefusedSaveFeedsNothingBack(): void {
		$client = $this->createMock(GlAccountSuggestionClientInterface::class);
		$client->expects($this->never())->method('postCorrection');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('schema validation failed');
		$this->committer(answer: new RuntimeException('schema validation failed'), client: $client)
			->commit(schema: 'SupplierInvoice', draft: ['docudeskExtractionId' => 'ext-9', 'glAccount' => '4500']);
	}//end testARefusedSaveFeedsNothingBack()

	/**
	 * The committer over a container whose ObjectService answers or throws.
	 *
	 * @param mixed                              $answer What saveObject answers; a Throwable is thrown.
	 * @param GlAccountSuggestionClientInterface $client The suggestion client.
	 *
	 * @return ExtractionDraftCommitter
	 */
	private function committer(mixed $answer, GlAccountSuggestionClientInterface $client): ExtractionDraftCommitter {
		$this->calls = [];
		$objects = new class($this->calls, $answer) {
			/**
			 * Constructor.
			 *
			 * @param array $calls  The calls seen, by reference.
			 * @param mixed $answer What saveObject answers.
			 */
			public function __construct(private array &$calls, private readonly mixed $answer) {
			}

			/**
			 * Fluent register selector.
			 *
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->calls[] = ['setRegister', $register];
				return $this;
			}

			/**
			 * Fluent schema selector.
			 *
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->calls[] = ['setSchema', $schema];
				return $this;
			}

			/**
			 * Save one object.
			 *
			 * @param array $object The object.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object): mixed {
				$this->calls[] = ['saveObject', $object];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);

		return new ExtractionDraftCommitter(container: $container, suggestionClient: $client);
	}//end committer()
}//end class
