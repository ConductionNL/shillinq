<?php

/**
 * Unit tests for BackfillLinkedObjects.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\BackfillLinkedObjects;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An in-memory PaymentRequest store with the methods the duck adapter forwards.
 */
final class LinkedObjectsBackfillStore {
	/**
	 * Rows by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Every save.
	 *
	 * @var array<int, array{object: array<string, mixed>, uuid: string|null, rbac: bool}>
	 */
	public array $saved = [];

	/**
	 * Make reads throw.
	 *
	 * @var bool
	 */
	public bool $down = false;

	public function setRegister(string|int $register): static {
		return $this;
	}//end setRegister()

	public function setSchema(string|int $schema): static {
		return $this;
	}//end setSchema()

	/**
	 * @param array<string, mixed> $config Filters, limit and offset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		if ($this->down === true) {
			throw new RuntimeException('PaymentRequest schema is not imported yet');
		}

		$matches = [];
		foreach ($this->rows as $uuid => $row) {
			foreach (($config['filters'] ?? []) as $key => $value) {
				if (($row[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$matches[] = $row + ['id' => $uuid];
		}

		return array_slice($matches, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? count($matches)));
	}//end findAll()

	/**
	 * @param array<string, mixed> $object The object.
	 * @param array|null $extend Unused.
	 * @param mixed $register Unused.
	 * @param mixed $schema Unused.
	 * @param string|null $uuid The uuid to update.
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->saved[] = ['object' => $object, 'uuid' => $uuid, 'rbac' => $_rbac];
		$this->rows[(string)$uuid] = $object;

		return $object + ['id' => $uuid];
	}//end saveObject()
}//end class

/**
 * A payment request's subject becomes its one linked object.
 */
final class BackfillLinkedObjectsTest extends TestCase {
	private const CASE_1 = ['type' => 'case', 'register' => 'dossiq', 'schema' => 'case', 'id' => 'c-1'];

	/**
	 * The step over this store.
	 *
	 * @param LinkedObjectsBackfillStore $store The store.
	 *
	 * @return BackfillLinkedObjects
	 */
	private function step(LinkedObjectsBackfillStore $store): BackfillLinkedObjects {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new BackfillLinkedObjects(
			settingsService: $settings,
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($store),
		);
	}//end step()

	/**
	 * A store with a request on a case, one already listed, and one on an invoice.
	 *
	 * @return LinkedObjectsBackfillStore
	 */
	private function store(): LinkedObjectsBackfillStore {
		$store = new LinkedObjectsBackfillStore();
		$store->rows = [
			'pr-case' => ['subjectKind' => 'object', 'subject' => self::CASE_1, 'state' => 'pending', 'amount' => 125.0],
			'pr-listed' => ['subjectKind' => 'object', 'subject' => self::CASE_1, 'linkedObjects' => [self::CASE_1], 'state' => 'captured'],
			'pr-invoice' => ['subjectKind' => 'invoice', 'invoiceReference' => 'inv-2', 'state' => 'pending'],
		];

		return $store;
	}//end store()

	public function testTheSubjectBecomesAOneItemListOnce(): void {
		$store = $this->store();
		$output = $this->createMock(IOutput::class);

		$this->step(store: $store)->run(output: $output);

		self::assertCount(1, $store->saved);
		self::assertSame('pr-case', $store->saved[0]['uuid']);
		self::assertFalse($store->saved[0]['rbac']);
		self::assertSame([self::CASE_1], $store->saved[0]['object']['linkedObjects']);
		self::assertSame(self::CASE_1, $store->saved[0]['object']['subject']);
		self::assertSame(125.0, $store->saved[0]['object']['amount']);
		self::assertArrayNotHasKey('id', $store->saved[0]['object']);
		self::assertArrayNotHasKey('linkedObjects', $store->rows['pr-invoice']);

		$this->step(store: $store)->run(output: $output);
		self::assertCount(1, $store->saved, 'a second run wrote again');
	}//end testTheSubjectBecomesAOneItemListOnce()

	public function testAFailingReadWarnsInsteadOfBlockingTheUpgrade(): void {
		$store = $this->store();
		$store->down = true;
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with(self::stringContains('not imported yet'));

		$this->step(store: $store)->run(output: $output);

		self::assertSame([], $store->saved);
	}//end testAFailingReadWarnsInsteadOfBlockingTheUpgrade()
}//end class
