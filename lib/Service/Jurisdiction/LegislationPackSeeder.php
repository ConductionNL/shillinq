<?php

/**
 * Shillinq LegislationPackSeeder.
 *
 * Reads a legislation pack (`lib/Settings/seeds/legislation/<cc>/pack.json`:
 * country, name, chart file, VAT rate file and the outputs it disables) and
 * seeds an administration of that jurisdiction from it: the chart's accounts
 * under the administration, and the pack's VAT rates.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Jurisdiction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Jurisdiction;

use OCA\Shillinq\Service\Seed\SeedImport;
use OCA\Shillinq\Service\Seed\SeedImporter;
use OCA\Shillinq\Service\Seed\SeedMessages;

/**
 * Seeds an administration from the legislation pack of its jurisdiction.
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */
class LegislationPackSeeder {

	/**
	 * The packs' directory under the seed directory.
	 */
	public const PACK_DIR = 'legislation';

	/**
	 * The setup wizard's chart-template value of a pack: this prefix and the lower-case country.
	 */
	public const TEMPLATE_PREFIX = 'legislation-';

	/**
	 * Constructor.
	 *
	 * @param SeedImporter $importer Reads seed files and imports their rows.
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function __construct(
		private readonly SeedImporter $importer,
	) {
	}//end __construct()

	/**
	 * The country of a wizard chart-template value that names a pack, or null.
	 *
	 * @param string $template The chart-template value, e.g. `legislation-be` or `mkb`.
	 *
	 * @return string|null The upper-case country, e.g. `BE`.
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public static function countryOfTemplate(string $template): ?string {
		if (str_starts_with($template, self::TEMPLATE_PREFIX) === false) {
			return null;
		}

		$country = strtoupper(substr($template, strlen(self::TEMPLATE_PREFIX)));
		if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
			return null;
		}

		return $country;
	}//end countryOfTemplate()

	/**
	 * The pack of a country, or an error.
	 *
	 * @param string $country ISO 3166 alpha-2, any case.
	 *
	 * @return array{pack: array<string,mixed>}|array{error: array<string,mixed>}
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function pack(string $country): array {
		$code = strtoupper(trim($country));
		if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
			return ['error' => ['success' => false, 'message' => 'Not a country code: ' . $country]];
		}

		$file = $this->importer->read(fileName: $this->path(country: $code, file: 'pack.json'));
		if (isset($file['error']) === true) {
			return ['error' => ['success' => false, 'message' => 'No legislation pack for ' . $code . '.']];
		}

		$pack = $file['data'];
		if (is_array($pack) === false || strtoupper((string)($pack['country'] ?? '')) !== $code
			|| is_string($pack['chart'] ?? null) === false || is_string($pack['vatRates'] ?? null) === false
		) {
			return ['error' => ['success' => false, 'message' => 'The legislation pack for ' . $code . ' is malformed.']];
		}

		return ['pack' => $pack];
	}//end pack()

	/**
	 * Seed an administration's chart of accounts and the VAT rates from its country's pack.
	 *
	 * Idempotent: an account already in the administration (by number) and a
	 * rate already present (by code) are skipped.
	 *
	 * @param string $country          ISO 3166 alpha-2.
	 * @param string $administrationId The administration the accounts belong to.
	 *
	 * @return array<string,mixed> `success`, `message`, and the `chart` and `vatRates` import results.
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function seed(string $country, string $administrationId): array {
		if ($administrationId === '') {
			return ['success' => false, 'message' => 'administrationId must not be empty.'];
		}

		$found = $this->pack(country: $country);
		if (isset($found['error']) === true) {
			return $found['error'];
		}

		$pack = $found['pack'];
		$code = strtoupper((string)$pack['country']);

		$accounts = $this->rows(country: $code, file: (string)$pack['chart'], key: 'accounts');
		if (isset($accounts['error']) === true) {
			return $accounts['error'];
		}

		$rates = $this->rows(country: $code, file: (string)$pack['vatRates'], key: 'tariffs');
		if (isset($rates['error']) === true) {
			return $rates['error'];
		}

		$chart = $this->importer->import(
			import: new SeedImport(
				schema: 'Account',
				rows: $accounts['rows'],
				match: static fn ($account): array => [
					'accountNumber' => $account['accountNumber'],
					'administrationId' => $administrationId,
				],
				administrationId: $administrationId,
			),
			messages: new SeedMessages(
				seededLog: 'Shillinq: legislation pack chart seeded',
				seededMessage: 'Chart of accounts seeded from the ' . $code . ' legislation pack.',
				failedLog: 'Shillinq: legislation pack chart seeding failed',
				logContext: ['country' => $code],
			)
		);

		$vatRates = $this->importer->importAsSystem(
			import: new SeedImport(
				schema: 'VatTariff',
				rows: array_map(static fn (array $rate): array => ($rate + ['jurisdiction' => $code]), $rates['rows']),
				match: static fn ($rate): array => ['code' => $rate['code']],
			),
			messages: new SeedMessages(
				seededLog: 'Shillinq: legislation pack VAT rates seeded',
				seededMessage: 'VAT rates seeded from the ' . $code . ' legislation pack.',
				failedLog: 'Shillinq: legislation pack VAT rate seeding failed',
				logContext: ['country' => $code],
			)
		);

		$success = (($chart['success'] ?? false) === true) && (($vatRates['success'] ?? false) === true);
		$message = 'Seeding from the ' . $code . ' legislation pack failed.';
		if ($success === true) {
			$message = 'Seeded from the ' . $code . ' legislation pack.';
		}

		return [
			'success' => $success,
			'message' => $message,
			'country' => $code,
			'chart' => $chart,
			'vatRates' => $vatRates,
		];
	}//end seed()

	/**
	 * The rows of a pack file.
	 *
	 * @param string $country The upper-case country.
	 * @param string $file    The file name inside the pack.
	 * @param string $key     The key that holds the rows.
	 *
	 * @return array{rows: list<array<string,mixed>>}|array{error: array<string,mixed>}
	 */
	private function rows(string $country, string $file, string $key): array {
		if (preg_match('/^[a-z0-9-]+\.json$/', $file) !== 1) {
			return ['error' => ['success' => false, 'message' => 'The ' . $country . ' legislation pack names an invalid file.']];
		}

		$read = $this->importer->read(fileName: $this->path(country: $country, file: $file));
		if (isset($read['error']) === true) {
			return ['error' => $read['error']];
		}

		$rows = array_values(array_filter((array)($read['data'][$key] ?? []), 'is_array'));
		if ($rows === []) {
			return ['error' => ['success' => false, 'message' => 'The ' . $country . ' legislation pack file ' . $file . ' has no ' . $key . '.']];
		}

		return ['rows' => $rows];
	}//end rows()

	/**
	 * A pack file's path under the seed directory.
	 *
	 * @param string $country The upper-case country.
	 * @param string $file    The file name.
	 *
	 * @return string
	 */
	private function path(string $country, string $file): string {
		return self::PACK_DIR . '/' . strtolower($country) . '/' . $file;
	}//end path()
}//end class
