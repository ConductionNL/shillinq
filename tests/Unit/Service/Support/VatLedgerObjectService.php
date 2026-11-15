<?php

/**
 * In-memory ledger store for the VAT supplementary-return tests.
 *
 * Duck-typed ObjectService (wrap it in DuckObjectServiceAdapter): equality
 * filters, find by id and saveObject with synthetic ids, so the real
 * VATReturnService and VatSuppletieDetectionService run over it end to end.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Support
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

namespace OCA\Shillinq\Tests\Unit\Service\Support;

/**
 * Rows per schema, written back in place.
 */
final class VatLedgerObjectService {

	/**
	 * Records keyed by schema slug.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $data;

	/**
	 * Counter for synthetic ids.
	 *
	 * @var integer
	 */
	private int $idCounter = 0;

	/**
	 * The active schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, list<array<string, mixed>>> $seed Rows keyed by schema slug.
	 */
	public function __construct(array $seed) {
		$defaults = ['Account' => [], 'GLTransaction' => [], 'GLLine' => [], 'BtwAangifte' => [], 'VATDeclaration' => [], 'VATLine' => [], 'VatCorrection' => []];
		$this->data = array_merge($defaults, $seed);
	}//end __construct()

	/**
	 * Select the register (ignored).
	 *
	 * @param string $register The register.
	 *
	 * @return static
	 */
	public function setRegister(string $register): static {
		return $this;
	}//end setRegister()

	/**
	 * Select the schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return static
	 */
	public function setSchema(string $schema): static {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * Rows of the active schema matching every equality filter.
	 *
	 * @param array<string, mixed> $params `filters`.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findAll(array $params = []): array {
		$filters = ($params['filters'] ?? []);
		return array_values(
			array_filter(
				($this->data[$this->schema] ?? []),
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end findAll()

	/**
	 * One row of the active schema by id.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id): ?array {
		foreach (($this->data[$this->schema] ?? []) as $row) {
			if ((string)($row['id'] ?? '') === $id) {
				return $row;
			}
		}

		return null;
	}//end find()

	/**
	 * Insert or replace a row of the active schema.
	 *
	 * @param array<string, mixed> $data The row.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $data): array {
		if (isset($data['id']) === false || $data['id'] === '') {
			$this->idCounter++;
			$data['id'] = $this->schema . '-' . $this->idCounter;
		}

		foreach (($this->data[$this->schema] ?? []) as $index => $row) {
			if ((string)($row['id'] ?? '') === (string)$data['id']) {
				$this->data[$this->schema][$index] = $data;
				return $data;
			}
		}

		$this->data[$this->schema][] = $data;
		return $data;
	}//end saveObject()

	/**
	 * Every row of a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function dump(string $schema): array {
		return ($this->data[$schema] ?? []);
	}//end dump()
}//end class
