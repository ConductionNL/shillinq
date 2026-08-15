<?php

/**
 * BBV taakveld catalogue translation invariants.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The BBV taakveld catalogues carry an English display name alongside the
 * statutory Dutch one, and the two must not drift.
 *
 * WHY THESE ASSERTIONS AND NOT "THE TRANSLATION IS GOOD".
 *
 * Translation quality is not machine-checkable; three structural properties
 * are, and each one has a specific way of going wrong:
 *
 *  1. THE DUTCH IS AN IDENTIFIER. `naam` / `name` / `hoofdfunctieNaam` /
 *     `programmaFocus` are the statutory strings from Besluit BBV bijlage IV.
 *     They are what `legalBasis` cites and what a CBS Iv3 submission carries.
 *     The obvious way to "translate a catalogue" is to overwrite them, and
 *     doing so would produce a submission CBS rejects — silently, at the far
 *     end of a quarterly export. So the test pins the Dutch as well as the
 *     English.
 *
 *  2. PAIRING, IN BOTH DIRECTIONS. A Dutch field with no English partner is an
 *     untranslated row that a UI renders in Dutch — the defect being fixed. An
 *     English field with no Dutch partner is worse: an invented description
 *     with no statutory basis. Several entries legitimately have NO
 *     `omschrijvingIv3` because the source regulation supplies none, and those
 *     must have no `omschrijvingIv3En` either.
 *
 *  3. THE SCHEMA MUST DECLARE THE FIELDS. OpenRegister's MagicMapper DISCARDS
 *     properties the schema does not declare — it logs and drops them, it does
 *     not fail. So a seed carrying `naamEn` against a schema that never
 *     declared it would import "successfully" and store nothing, and the UI
 *     would fall back to Dutch with no error anywhere. That is the failure this
 *     file exists to make loud, and it is why the schema assertion is here
 *     rather than left to a reviewer's eye.
 *
 * This is a data-integrity test over the shipped seed files and the register
 * declaration, so it covers no class. `@coversNothing` is the valid way to say
 * that — `@covers ::nothing` is NOT a target PHPUnit recognises, and with
 * `beStrictAboutCoverageMetadata="true"` it raised one warning per test method
 * (9 in total) and took the whole PHPUnit job to exit 1 in CI while the local
 * run reported OK.
 *
 * @coversNothing
 */
class BbvTaakveldTranslationTest extends TestCase {
	/**
	 * The seed catalogues, and the Dutch => English field pairs each uses.
	 *
	 * The 2024 file feeds the `BbvTaakveld` schema and the three 2025 files
	 * feed `Taakveld`; they use different field names for the same idea, which
	 * is why the pairs are per-file rather than global.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public static function catalogueProvider(): array {
		$iv3Pairs = [
			'name' => 'nameEn',
			'mainFunctionName' => 'mainFunctionNameEn',
			'descriptionIv3' => 'descriptionIv3And',
		];

		return [
			'gemeente 2025' => ['bbv-taakvelden-gemeente-2025.json', $iv3Pairs],
			'provincie 2025' => ['bbv-taakvelden-provincia-2025.json', $iv3Pairs],
			'waterschap 2025' => ['bbv-taakvelden-waterschap-2025.json', $iv3Pairs],
			'BBV 2024' => [
				'bbv-taakvelden-2024.json',
				[
					'name' => 'nameEn',
					'description' => 'descriptionEn',
					'programmeFocus' => 'programmeFocusAnd',
				],
			],
		];
	}

	/**
	 * Read a seed catalogue's taakveld list.
	 *
	 * @param string $file The seed file name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function taskFields(string $file): array {
		$path = __DIR__ . '/../../../lib/Settings/seeds/' . $file;
		self::assertFileExists($path);

		$data = json_decode((string)file_get_contents($path), associative: true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), $file . ' is not valid JSON');
		self::assertNotEmpty($data['taskFields'], $file . ' declares no taakvelden');

		return $data['taskFields'];
	}

	/**
	 * Every Dutch field has an English partner and vice versa.
	 *
	 * @param string $file Seed file name.
	 * @param array<string, string> $pairs Dutch field => English field.
	 *
	 * @return void
	 *
	 * @dataProvider catalogueProvider
	 */
	public function testEveryDutchFieldIsPairedWithAnEnglishOne(string $file, array $pairs): void {
		foreach ($this->taskFields($file) as $taskField) {
			$code = $taskField['code'] ?? '(no code)';

			foreach ($pairs as $dutch => $english) {
				$hasDutch = ($taskField[$dutch] ?? '') !== '';
				$hasEnglish = ($taskField[$english] ?? '') !== '';

				self::assertSame(
					$hasDutch,
					$hasEnglish,
					sprintf(
						'%s taakveld %s: "%s" is %s but "%s" is %s. A Dutch field without an '
						. 'English partner renders in Dutch in an English UI; an English field '
						. 'without a Dutch one is an invented string with no statutory basis.',
						$file,
						$code,
						$dutch,
						$hasDutch ? 'present' : 'absent',
						$english,
						$hasEnglish ? 'present' : 'absent'
					)
				);
			}
		}
	}

	/**
	 * The primary name is translated for every single entry.
	 *
	 * Pairing alone would be satisfied by a catalogue with no names at all in
	 * either language, so the name specifically is required to be present in
	 * both.
	 *
	 * @param string $file Seed file name.
	 * @param array<string, string> $pairs Dutch field => English field.
	 *
	 * @return void
	 *
	 * @dataProvider catalogueProvider
	 */
	public function testEveryTaakveldHasBothANameAndAnEnglishName(string $file, array $pairs): void {
		$dutchName = isset($pairs['name']) ? 'name' : 'name';
		$englishName = $pairs[$dutchName];

		$taskFields = $this->taskFields($file);

		foreach ($taskFields as $taskField) {
			$code = $taskField['code'] ?? '(no code)';

			self::assertNotEmpty(
				$taskField[$dutchName] ?? '',
				$file . ' taakveld ' . $code . ': the STATUTORY Dutch name must never be dropped — '
				. 'it is what a CBS Iv3 submission carries and what legalBasis cites.'
			);
			self::assertNotEmpty(
				$taskField[$englishName] ?? '',
				$file . ' taakveld ' . $code . ' has no ' . $englishName . ', so an English UI shows Dutch here.'
			);
		}
	}

	/**
	 * The English fields are DECLARED by the schema, or OpenRegister drops them.
	 *
	 * MagicMapper discards properties the schema does not declare. It logs and
	 * continues — the import reports success and the values are simply not
	 * stored — so without this assertion a shipped translation could be
	 * invisible at runtime with nothing anywhere reporting a failure.
	 *
	 * The register declaration is assembled from `shillinq_register.json` plus
	 * every fragment under `register.d/`, so all of them are merged here the
	 * same way the loader merges them.
	 *
	 * @return void
	 */
	public function testTheRegisterDeclaresEveryEnglishField(): void {
		$settings = __DIR__ . '/../../../lib/Settings';

		$sources = [$settings . '/shillinq_register.json'];
		foreach ((array)glob($settings . '/register.d/*.json') as $fragment) {
			$sources[] = $fragment;
		}

		$declared = [];
		foreach ($sources as $source) {
			$data = json_decode((string)file_get_contents($source), associative: true);
			if (is_array($data) === false) {
				continue;
			}

			$this->collectSchemaProperties($data, $declared);
		}

		$expected = [
			'Taakveld' => ['nameEn', 'mainFunctionNameEn', 'descriptionIv3And'],
			'BbvTaakveld' => ['nameEn', 'descriptionEn', 'programmeFocusAnd'],
		];

		foreach ($expected as $schema => $fields) {
			self::assertArrayHasKey($schema, $declared, 'schema ' . $schema . ' is not declared anywhere');

			foreach ($fields as $field) {
				self::assertContains(
					$field,
					$declared[$schema],
					sprintf(
						'Schema "%s" does not declare "%s". OpenRegister\'s MagicMapper DISCARDS '
						. 'undeclared properties — the seed would import "successfully" and store '
						. 'nothing, and the UI would silently fall back to Dutch.',
						$schema,
						$field
					)
				);
			}
		}
	}

	/**
	 * Walk a decoded register document collecting `<SchemaName> => [props]`.
	 *
	 * @param mixed $node Current node.
	 * @param array<string, array<int, string>> $declared Accumulator, by reference.
	 *
	 * @return void
	 */
	private function collectSchemaProperties(mixed $node, array &$declared): void {
		if (is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $value) {
			if (is_array($value) === true
				&& isset($value['properties']) === true
				&& is_array($value['properties']) === true
				&& is_string($key) === true
			) {
				$declared[$key] = array_merge(
					($declared[$key] ?? []),
					array_keys($value['properties'])
				);
			}

			$this->collectSchemaProperties($value, $declared);
		}
	}
}
