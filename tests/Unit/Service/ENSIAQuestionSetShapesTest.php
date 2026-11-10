<?php

/**
 * Characterisation tests for the ENSIAQuestionSetLoader output shapes.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-ensia-zelfevaluatie/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ENSIAQuestionSetLoader;
use PHPUnit\Framework\TestCase;

/**
 * Pins every record field, the defaults and every unreadable-seed outcome.
 */
final class ENSIAQuestionSetShapesTest extends TestCase {

	/**
	 * Seed files written by the test.
	 *
	 * @var array<int, string>
	 */
	private array $paths = [];

	/**
	 * Remove the seed files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->paths as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A loader over a seed file with the given contents.
	 *
	 * @param string $contents The raw seed.
	 *
	 * @return ENSIAQuestionSetLoader
	 */
	private function loaderOver(string $contents): ENSIAQuestionSetLoader {
		$path          = sys_get_temp_dir() . '/ensia-shapes-' . uniqid() . '.json';
		$this->paths[] = $path;
		file_put_contents($path, $contents);

		return new ENSIAQuestionSetLoader($path);
	}//end loaderOver()

	/**
	 * Every field of a kept question, its defaults, and the skips.
	 *
	 * @return void
	 */
	public function testRecordsAndDefaults(): void {
		$seed = [
			'vragen' => [
				'junk',
				['subject' => 'no domain'],
				['domain' => 'SUWI', 'questionCode' => 'S-1'],
				['domain' => 'BIO', 'subject' => 'A', 'questionCode' => 'B-1', 'questionText' => 'T', 'answerType' => 'x', 'normniveau' => '4'],
				['domain' => 'DigiD'],
			],
		];

		$result = $this->loaderOver(json_encode($seed))->load(2031, ['BIO', 7 => 'DigiD'], 'cyc', 'adm');

		self::assertSame(
			[
				'questionSetVersion' => 'BIO-1.04-2031',
				'vragen' => [
					[
						'cyclusId' => 'cyc',
						'administrationId' => 'adm',
						'domain' => 'BIO',
						'subject' => 'A',
						'questionCode' => 'B-1',
						'questionText' => 'T',
						'answerType' => 'x',
						'normniveau' => 4,
						'peerReviewStatus' => 'nog-niet-beoordeeld',
						'supportingDocuments' => [],
					],
					[
						'cyclusId' => 'cyc',
						'administrationId' => 'adm',
						'domain' => 'DigiD',
						'subject' => '',
						'questionCode' => '',
						'questionText' => '',
						'answerType' => 'ja-nee-nvt',
						'normniveau' => null,
						'peerReviewStatus' => 'nog-niet-beoordeeld',
						'supportingDocuments' => [],
					],
				],
			],
			$result
		);
	}//end testRecordsAndDefaults()

	/**
	 * Unreadable seeds give the unknown version; a seed without a usable
	 * catalogue keeps its version with no questions.
	 *
	 * @return void
	 */
	public function testUnreadableSeeds(): void {
		$unknown = ['questionSetVersion' => 'unknown', 'vragen' => []];

		self::assertSame($unknown, (new ENSIAQuestionSetLoader('/nonexistent/ensia.json'))->load(2026, ['BIO'], 'c', 'a'));
		self::assertSame($unknown, $this->loaderOver('{not json')->load(2026, ['BIO'], 'c', 'a'));
		self::assertSame($unknown, $this->loaderOver('"a string"')->load(2026, ['BIO'], 'c', 'a'));
		self::assertSame(
			['questionSetVersion' => 'V-9', 'vragen' => []],
			$this->loaderOver('{"questionSetVersion":"V-9","vragen":"none"}')->load(2026, ['BIO'], 'c', 'a')
		);
		self::assertSame(
			['questionSetVersion' => '12', 'vragen' => []],
			$this->loaderOver('{"questionSetVersion":12}')->load(2026, ['BIO'], 'c', 'a')
		);
	}//end testUnreadableSeeds()
}//end class
