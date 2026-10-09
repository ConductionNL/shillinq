<?php

/**
 * Unit tests for PaymentRequestFinder.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ObjectPaymentRequestValidator;
use OCA\Shillinq\Service\PaymentRequestFinder;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Covers paging to the last page, the subject filter, entity rows and whose
 * rights a read runs under.
 */
final class PaymentRequestFinderTest extends TestCase {
	/**
	 * A store that honours limit and offset, and records every read.
	 *
	 * @param array<int, array<string, mixed>|object> $rows The stored rows.
	 *
	 * @return object The double.
	 */
	private function pagingStore(array $rows): object {
		return new class($rows) {
			/**
			 * Every findAll call: the config and the two rights flags.
			 *
			 * @var array<int, array{config: array<string, mixed>, rbac: bool, multitenancy: bool}>
			 */
			public array $reads = [];

			/**
			 * @param array<int, array<string, mixed>|object> $rows Stored rows.
			 */
			public function __construct(private array $rows) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string, mixed> $config Query config.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 * @return array<int, array<string, mixed>|object>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads[] = ['config' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return array_slice($this->rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 20));
			}
		};
	}//end pagingStore()

	/**
	 * The finder over a store.
	 *
	 * @param object $store The store double.
	 *
	 * @return PaymentRequestFinder The finder.
	 */
	private function finder(object $store): PaymentRequestFinder {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new PaymentRequestFinder(
			objectService: new DuckObjectServiceAdapter(inner: $store),
			validator: new ObjectPaymentRequestValidator(),
			appConfig: $appConfig,
		);
	}//end finder()

	/**
	 * One stored object request.
	 *
	 * @param string $id The request id.
	 * @param string $subjectId The subject id.
	 * @param string $app The app named in the subject.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(string $id, string $subjectId, string $app = 'learniq'): array {
		return [
			'id' => $id,
			'subjectKind' => 'object',
			'subject' => ['app' => $app, 'type' => 'fee-item', 'register' => 'learniq', 'schema' => 'FeeItem', 'id' => $subjectId],
			'requestType' => 'contribution',
		];
	}//end row()

	/**
	 * 450 requests on one subject among others come back complete, read in
	 * pages of 200 until a short page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-004)
	 */
	public function testReadsEveryPageUntilAShortOne(): void {
		$rows = [];
		for ($i = 0; $i < 450; $i++) {
			$rows[] = $this->row('pr-' . $i, 'fee-1');
			if ($i % 50 === 0) {
				$rows[] = $this->row('other-' . $i, 'fee-2');
			}
		}

		$store = $this->pagingStore($rows);
		$found = $this->finder($store)->onSubject('learniq', 'FeeItem', 'fee-1');

		self::assertCount(450, $found);
		self::assertCount(3, $store->reads);
		self::assertSame([0, 200, 400], array_map(static fn (array $r): int => (int)$r['config']['offset'], $store->reads));
		self::assertSame('object', $store->reads[0]['config']['filters']['subjectKind']);
	}//end testReadsEveryPageUntilAShortOne()

	/**
	 * The subject key is register, schema and id: the app and the type describe
	 * the reference and never hide a request, and another subject never leaks.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-004)
	 */
	public function testMatchesOnRegisterSchemaAndIdOnly(): void {
		$store = $this->pagingStore(
			[
				$this->row('pr-1', 'fee-1'),
				$this->row('pr-2', 'fee-1', 'someone-else'),
				$this->row('pr-3', 'fee-9'),
				['id' => 'pr-4', 'subjectKind' => 'object'],
			]
		);

		$found = $this->finder($store)->onSubject('learniq', 'FeeItem', 'fee-1');

		self::assertSame(['pr-1', 'pr-2'], array_column($found, 'id'));
	}//end testMatchesOnRegisterSchemaAndIdOnly()

	/**
	 * An entity row keeps its uuid as `id`, which the raise reports back. A real
	 * ObjectEntity leaves the uuid out of getObject() and renders it as `id` in
	 * jsonSerialize(), so the double does exactly that.
	 *
	 * @return void
	 */
	public function testAnEntityRowKeepsItsId(): void {
		$payload = $this->row('', 'fee-1');
		unset($payload['id']);

		$entity = new class($payload) {
			/**
			 * @param array<string, mixed> $payload The object data.
			 */
			public function __construct(private array $payload) {
			}

			/**
			 * @return array<string, mixed> The object data, without the uuid.
			 */
			public function getObject(): array {
				return $this->payload;
			}

			/**
			 * @return array<string, mixed> The rendered object, with the uuid as id.
			 */
			public function jsonSerialize(): array {
				return $this->payload + ['id' => 'uuid-7'];
			}
		};

		$found = $this->finder($this->pagingStore([$entity]))->onSubject('learniq', 'FeeItem', 'fee-1');

		self::assertSame('uuid-7', $found[0]['id']);
	}//end testAnEntityRowKeepsItsId()

	/**
	 * The leaf reads as the caller; the raise's duplicate check reads past the
	 * caller's rights, because it must see every request.
	 *
	 * @return void
	 */
	public function testReadsAsTheCallerUnlessAskedToReadAsTheSystem(): void {
		$store = $this->pagingStore([$this->row('pr-1', 'fee-1')]);
		$finder = $this->finder($store);

		$finder->onSubject('learniq', 'FeeItem', 'fee-1');
		$finder->onSubject('learniq', 'FeeItem', 'fee-1', true);

		self::assertTrue($store->reads[0]['rbac']);
		self::assertTrue($store->reads[0]['multitenancy']);
		self::assertFalse($store->reads[1]['rbac']);
		self::assertFalse($store->reads[1]['multitenancy']);
	}//end testReadsAsTheCallerUnlessAskedToReadAsTheSystem()
}//end class
