<?php

/**
 * Unit tests for ENSIABevindingGenerator.
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

use OCA\Shillinq\Service\ENSIABevindingGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests REQ-ENSIA-005 — Bevinding auto-generation from low maturity scores.
 */
class ENSIABevindingGeneratorTest extends TestCase {

	private ENSIABevindingGenerator $generator;

	protected function setUp(): void {
		parent::setUp();
		$this->generator = new ENSIABevindingGenerator();

	}//end setUp()

	/**
	 * REQ-ENSIA-005: score below normniveau on akkoord question → finding.
	 *
	 * @return void
	 */
	public function testGeneratesFindingForBelowNormScore(): void {
		$cyclus = ['id' => 'cyc-2026', 'administrationId' => 'adm-1'];
		$vragen = [
			[
				'id' => 'q1',
				'questionCode' => 'BIO-9.1.1',
				'questionText' => 'Logische toegangsbeveiliging',
				'peerReviewStatus' => 'akkoord',
				'maturityScore' => 2,
				'normniveau' => 3,
			],
		];

		$findings = $this->generator->generate($cyclus, $vragen);

		$this->assertCount(1, $findings);
		$this->assertSame('cyc-2026', $findings[0]['cyclusId']);
		$this->assertSame('adm-1', $findings[0]['administrationId']);
		$this->assertSame('q1', $findings[0]['questionId']);
		$this->assertSame('tekortkoming', $findings[0]['type']);
		$this->assertSame('open', $findings[0]['status']);
		$this->assertStringContainsString('BIO-9.1.1', $findings[0]['description']);
		$this->assertStringContainsString('2', $findings[0]['description']);
		$this->assertStringContainsString('3', $findings[0]['description']);

	}//end testGeneratesFindingForBelowNormScore()

	/**
	 * REQ-ENSIA-005: score at-or-above normniveau yields NO finding.
	 *
	 * @return void
	 */
	public function testNoFindingWhenScoreMeetsNorm(): void {
		$cyclus = ['id' => 'cyc-2026', 'administrationId' => 'adm-1'];
		$vragen = [
			[
				'id' => 'q1',
				'questionCode' => 'BIO-9.1.1',
				'peerReviewStatus' => 'akkoord',
				'maturityScore' => 3,
				'normniveau' => 3,
			],
			[
				'id' => 'q2',
				'questionCode' => 'BIO-9.2.1',
				'peerReviewStatus' => 'akkoord',
				'maturityScore' => 5,
				'normniveau' => 3,
			],
		];

		$this->assertSame([], $this->generator->generate($cyclus, $vragen));

	}//end testNoFindingWhenScoreMeetsNorm()

	/**
	 * REQ-ENSIA-005: questions still in wijziging-gevraagd are SKIPPED —
	 * their answer is expected to change before a finding is generated.
	 *
	 * @return void
	 */
	public function testSkipsQuestionsAwaitingChange(): void {
		$cyclus = ['id' => 'cyc-2026', 'administrationId' => 'adm-1'];
		$vragen = [
			[
				'id' => 'q1',
				'questionCode' => 'BIO-9.1.1',
				'peerReviewStatus' => 'wijziging-gevraagd',
				'maturityScore' => 2,
				'normniveau' => 3,
			],
			[
				'id' => 'q2',
				'questionCode' => 'BIO-9.2.1',
				'peerReviewStatus' => 'nog-niet-beoordeeld',
				'maturityScore' => 1,
				'normniveau' => 3,
			],
		];

		$this->assertSame([], $this->generator->generate($cyclus, $vragen));

	}//end testSkipsQuestionsAwaitingChange()

	/**
	 * REQ-ENSIA-005: questions without normniveau or score are skipped.
	 *
	 * @return void
	 */
	public function testSkipsQuestionsWithoutScoreOrNorm(): void {
		$cyclus = ['id' => 'cyc-2026', 'administrationId' => 'adm-1'];
		$vragen = [
			[
				'id' => 'q1',
				'questionCode' => 'BIO-9.1.1',
				'peerReviewStatus' => 'akkoord',
				'maturityScore' => null,
				'normniveau' => 3,
			],
			[
				'id' => 'q2',
				'questionCode' => 'BIO-9.2.1',
				'peerReviewStatus' => 'akkoord',
				'maturityScore' => 2,
				'normniveau' => null,
			],
		];

		$this->assertSame([], $this->generator->generate($cyclus, $vragen));

	}//end testSkipsQuestionsWithoutScoreOrNorm()

}//end class
