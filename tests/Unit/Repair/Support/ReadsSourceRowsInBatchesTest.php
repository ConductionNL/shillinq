<?php

/**
 * Unit tests for the ReadsSourceRowsInBatches repair helper.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair\Support
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

namespace OCA\Shillinq\Tests\Unit\Repair\Support;

use OCA\Shillinq\Kernel\Support\ReadsSourceRowsInBatches;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A repair step stand-in exposing the trait's helpers.
 */
final class BatchReadingStep {
	use ReadsSourceRowsInBatches;

	/**
	 * Read every row.
	 *
	 * @param object              $objectService The object service.
	 * @param string              $registerSlug  The register slug.
	 * @param string              $schema        The schema slug.
	 * @param array<string,mixed> $filters       The filters.
	 *
	 * @return array<int,mixed>
	 */
	public function all(object $objectService, string $registerSlug, string $schema, array $filters = []): array {
		return $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: $schema, filters: $filters);
	}//end all()

	/**
	 * Resolve one row.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string,mixed>
	 */
	public function payload(mixed $row): array {
		return $this->rowPayload(row: $row);
	}//end payload()
}//end class

/**
 * A fluent object service answering pages from a fixed row list.
 */
final class PagedObjectService {
	/**
	 * The register and schema set, and the config of each findAll().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * The register last set.
	 *
	 * @var string
	 */
	private string $register = '';

	/**
	 * The schema last set.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Constructor.
	 *
	 * @param array<int, mixed> $rows   The rows to page through.
	 * @param mixed             $answer A fixed answer instead of a page, when not null.
	 */
	public function __construct(private array $rows, private mixed $answer = null) {
	}//end __construct()

	/**
	 * Set the register.
	 *
	 * @param string $register The register slug.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		$this->register = $register;
		return $this;
	}//end setRegister()

	/**
	 * Set the schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * Answer one page.
	 *
	 * @param array<string, mixed> $config The paging config.
	 *
	 * @return mixed
	 */
	public function findAll(array $config): mixed {
		$this->calls[] = ['register' => $this->register, 'schema' => $this->schema] + $config;
		if ($this->answer !== null) {
			return $this->answer;
		}

		return array_slice($this->rows, (int)$config['offset'], (int)$config['limit']);
	}//end findAll()
}//end class

/**
 * @covers \OCA\Shillinq\Kernel\Support\ReadsSourceRowsInBatches
 */
final class ReadsSourceRowsInBatchesTest extends TestCase {

	/**
	 * Every row is read in explicit, unscoped pages until a short page.
	 *
	 * @return void
	 */
	public function testEveryRowIsReadInUnscopedPages(): void {
		$rows = range(1, (BatchReadingStep::READ_BATCH_SIZE * 2) + 3);
		$service = new PagedObjectService(rows: $rows);

		$read = (new BatchReadingStep())->all(objectService: $service, registerSlug: 'shillinq', schema: 'ARInvoice');

		self::assertSame(expected: $rows, actual: $read);
		self::assertSame(expected: [0, 200, 400], actual: array_column($service->calls, 'offset'));
		self::assertSame(
			expected: ['register' => 'shillinq', 'schema' => 'ARInvoice', 'limit' => 200, 'offset' => 0, '_rbac' => false, '_multitenancy' => false],
			actual: $service->calls[0]
		);
	}//end testEveryRowIsReadInUnscopedPages()

	/**
	 * A full last page costs one more, empty read; filters ride along.
	 *
	 * @return void
	 */
	public function testAFullLastPageEndsOnTheEmptyPageAndFiltersRideAlong(): void {
		$service = new PagedObjectService(rows: range(1, BatchReadingStep::READ_BATCH_SIZE));

		$read = (new BatchReadingStep())->all(
			objectService: $service,
			registerSlug: 'shillinq',
			schema: 'ARInvoice',
			filters: ['status' => 'sent']
		);

		self::assertCount(expectedCount: 200, haystack: $read);
		self::assertCount(expectedCount: 2, haystack: $service->calls);
		self::assertSame(expected: ['status' => 'sent'], actual: $service->calls[1]['filters']);
	}//end testAFullLastPageEndsOnTheEmptyPageAndFiltersRideAlong()

	/**
	 * A page that is not a list ends the read with nothing.
	 *
	 * @return void
	 */
	public function testANonListAnswerReadsAsNoRows(): void {
		$service = new PagedObjectService(rows: [], answer: 'unexpected');

		self::assertSame(
			expected: [],
			actual: (new BatchReadingStep())->all(objectService: $service, registerSlug: 'shillinq', schema: 'ARInvoice')
		);
		self::assertArrayNotHasKey(key: 'filters', array: $service->calls[0]);
	}//end testANonListAnswerReadsAsNoRows()

	/**
	 * Arrays pass through, entities give their payload, and anything else
	 * falls back to public properties or nothing.
	 *
	 * @return void
	 */
	public function testARowResolvesToItsSchemaPayload(): void {
		$step = new BatchReadingStep();

		$entity = new class {
			/**
			 * Not part of the payload.
			 *
			 * @var string
			 */
			public string $internal = 'x';

			/**
			 * The schema payload.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['invoiceNumber' => 'INV-1'];
			}
		};
		$throwing = new class {
			/**
			 * A public property.
			 *
			 * @var string
			 */
			public string $invoiceNumber = 'INV-2';

			/**
			 * Not an OpenRegister entity.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				throw new RuntimeException('no payload');
			}
		};
		$scalarPayload = new class {
			/**
			 * A public property.
			 *
			 * @var string
			 */
			public string $invoiceNumber = 'INV-3';

			/**
			 * A payload that is not an array.
			 *
			 * @return string
			 */
			public function getObject(): string {
				return 'json';
			}
		};

		self::assertSame(expected: ['a' => 1], actual: $step->payload(row: ['a' => 1]));
		self::assertSame(expected: ['invoiceNumber' => 'INV-1'], actual: $step->payload(row: $entity));
		self::assertSame(expected: ['invoiceNumber' => 'INV-2'], actual: $step->payload(row: $throwing));
		self::assertSame(expected: ['invoiceNumber' => 'INV-3'], actual: $step->payload(row: $scalarPayload));
		self::assertSame(expected: [], actual: $step->payload(row: 'scalar'));
	}//end testARowResolvesToItsSchemaPayload()
}//end class
