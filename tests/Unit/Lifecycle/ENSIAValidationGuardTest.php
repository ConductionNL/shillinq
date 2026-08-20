<?php

/**
 * Unit tests for ENSIAValidationGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
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

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\ENSIAValidationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests REQ-ENSIA-003 (maturity evidence), REQ-ENSIA-004 (college-akkoord gating),
 * and REQ-ENSIA-008 (post-peer-review reden enforcement).
 */
class ENSIAValidationGuardTest extends TestCase {
	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var ENSIAValidationGuard
	 */
	private ENSIAValidationGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new ENSIAValidationGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * REQ-ENSIA-003: score below 3 needs no evidence — return true.
	 *
	 * @return void
	 */
	public function testMaturityScoreBelowThreeNeedsNoEvidence(): void {
		$this->assertTrue(
			$this->guard->maturityEvidenceSatisfied([
				'questionCode' => 'BIO-9.1.1',
				'maturityScore' => 2,
				'supportingDocuments' => [],
				'notes' => 'short',
			])
		);

	}//end testMaturityScoreBelowThreeNeedsNoEvidence()

	/**
	 * REQ-ENSIA-003: null score needs no evidence — return true.
	 *
	 * @return void
	 */
	public function testNullMaturityScoreNeedsNoEvidence(): void {
		$this->assertTrue(
			$this->guard->maturityEvidenceSatisfied([
				'questionCode' => 'BIO-9.1.1',
				'maturityScore' => null,
				'supportingDocuments' => [],
				'notes' => '',
			])
		);

	}//end testNullMaturityScoreNeedsNoEvidence()

	/**
	 * REQ-ENSIA-003: score ≥ 3 with no evidence — return false.
	 *
	 * @return void
	 */
	public function testMaturityScoreThreeWithoutEvidenceBlocks(): void {
		$this->assertFalse(
			$this->guard->maturityEvidenceSatisfied([
				'questionCode' => 'BIO-9.1.1',
				'maturityScore' => 3,
				'supportingDocuments' => [],
				'notes' => str_repeat('a', 100),
			])
		);

	}//end testMaturityScoreThreeWithoutEvidenceBlocks()

	/**
	 * REQ-ENSIA-003: score ≥ 3 with short toelichting — return false.
	 *
	 * @return void
	 */
	public function testMaturityScoreThreeWithShortToelichtingBlocks(): void {
		$this->assertFalse(
			$this->guard->maturityEvidenceSatisfied([
				'questionCode' => 'BIO-9.1.1',
				'maturityScore' => 3,
				'supportingDocuments' => [['fileRef' => 'docudesk://x', 'description' => 'doc']],
				'notes' => 'too short',
			])
		);

	}//end testMaturityScoreThreeWithShortToelichtingBlocks()

	/**
	 * REQ-ENSIA-003: score ≥ 3 with evidence and 50+ chars — pass.
	 *
	 * @return void
	 */
	public function testMaturityScoreThreeWithEvidenceAndLongToelichtingAllowed(): void {
		$this->assertTrue(
			$this->guard->maturityEvidenceSatisfied([
				'questionCode' => 'BIO-9.1.1',
				'maturityScore' => 4,
				'supportingDocuments' => [['fileRef' => 'docudesk://x', 'description' => 'doc']],
				'notes' => str_repeat('a', 50),
			])
		);

	}//end testMaturityScoreThreeWithEvidenceAndLongToelichtingAllowed()

	/**
	 * REQ-ENSIA-008: pre-peer-review edit needs no reden — return true.
	 *
	 * @return void
	 */
	public function testPrePeerReviewEditNeedsNoReden(): void {
		$this->assertTrue(
			$this->guard->postPeerReviewReasonRequired([
				'questionCode' => 'BIO-9.1.1',
				'answer' => '3',
				'peerReviewStatus' => 'nog-niet-beoordeeld',
				'peerReviewedAt' => null,
				'reason' => null,
			])
		);

	}//end testPrePeerReviewEditNeedsNoReden()

	/**
	 * REQ-ENSIA-008: post-peer-review edit with no reden — return false.
	 *
	 * @return void
	 */
	public function testPostPeerReviewEditWithoutRedenBlocks(): void {
		$this->assertFalse(
			$this->guard->postPeerReviewReasonRequired([
				'questionCode' => 'BIO-9.1.1',
				'answer' => '4',
				'peerReviewStatus' => 'akkoord',
				'peerReviewedAt' => '2026-03-15T12:00:00+00:00',
				'reason' => '',
			])
		);

	}//end testPostPeerReviewEditWithoutRedenBlocks()

	/**
	 * REQ-ENSIA-008: post-peer-review edit with reden — pass.
	 *
	 * @return void
	 */
	public function testPostPeerReviewEditWithRedenAllowed(): void {
		$this->assertTrue(
			$this->guard->postPeerReviewReasonRequired([
				'questionCode' => 'BIO-9.1.1',
				'answer' => '4',
				'peerReviewStatus' => 'akkoord',
				'peerReviewedAt' => '2026-03-15T12:00:00+00:00',
				'reason' => 'Aanvullend bewijs ontvangen na peer-review; antwoord verhoogd van 3 naar 4.',
			])
		);

	}//end testPostPeerReviewEditWithRedenAllowed()

	/**
	 * REQ-ENSIA-008: whitespace-only reden is rejected post peer-review.
	 *
	 * @return void
	 */
	public function testWhitespaceOnlyRedenIsRejected(): void {
		$this->assertFalse(
			$this->guard->postPeerReviewReasonRequired([
				'questionCode' => 'BIO-9.1.1',
				'peerReviewStatus' => 'wijziging-gevraagd',
				'peerReviewedAt' => '2026-03-15T12:00:00+00:00',
				'reason' => "   \t\n  ",
			])
		);

	}//end testWhitespaceOnlyRedenIsRejected()

	/**
	 * REQ-ENSIA-004: cyclus with no id short-circuits to allow.
	 *
	 * @return void
	 */
	public function testCollegeAkkoordAllowedShortCircuitsOnMissingId(): void {
		$this->assertTrue(
			$this->guard->collegeAkkoordAllowed([
				'year' => 2026,
				'status' => 'peer-review',
			])
		);

	}//end testCollegeAkkoordAllowedShortCircuitsOnMissingId()

	/**
	 * REQ-ENSIA-004: ObjectService unavailable → permissive bypass (returns
	 * true). Lifecycle engine itself enforces RBAC + state-machine rules; we
	 * do not want a transient OR outage to wedge a clean cycle.
	 *
	 * @return void
	 */
	public function testCollegeAkkoordAllowedPermissiveOnObjectServiceFailure(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('OR not installed'));
		$this->assertTrue(
			$this->guard->collegeAkkoordAllowed([
				'id' => 'ensia-2026-gemeente-1',
				'status' => 'peer-review',
			])
		);

	}//end testCollegeAkkoordAllowedPermissiveOnObjectServiceFailure()

	/**
	 * REQ-ENSIA-004: cyclus with no unresolved wijzigingen — allow.
	 *
	 * @return void
	 */
	public function testCollegeAkkoordAllowedWhenNoWijzigingenFound(): void {
		$objectService = new class {
			public function setRegister(string $slug): self {
				return $this;
			}

			public function setSchema(string $slug): self {
				return $this;
			}

			public function findAll(array $args): array {
				return [];
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->assertTrue(
			$this->guard->collegeAkkoordAllowed([
				'id' => 'ensia-2026-gemeente-1',
				'status' => 'peer-review',
			])
		);

	}//end testCollegeAkkoordAllowedWhenNoWijzigingenFound()

	/**
	 * REQ-ENSIA-004: cyclus with at least one unresolved wijziging — block.
	 *
	 * @return void
	 */
	public function testCollegeAkkoordBlockedWhenUnresolvedWijzigingExists(): void {
		$objectService = new class {
			public function setRegister(string $slug): self {
				return $this;
			}

			public function setSchema(string $slug): self {
				return $this;
			}

			public function findAll(array $args): array {
				return [['id' => 'q1', 'peerReviewStatus' => 'wijziging-gevraagd']];
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->assertFalse(
			$this->guard->collegeAkkoordAllowed([
				'id' => 'ensia-2026-gemeente-1',
				'status' => 'peer-review',
			])
		);

	}//end testCollegeAkkoordBlockedWhenUnresolvedWijzigingExists()

}//end class
