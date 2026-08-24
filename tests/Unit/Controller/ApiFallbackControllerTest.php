<?php

/**
 * Unit tests for ApiFallbackController.
 *
 * The controller is small on purpose — it is a net, not a policy — but the
 * two things it must get right are exactly the two that made the bug it
 * closes invisible: the STATUS must be 404 rather than 200, and the body must
 * NAME the path so the caller learns which url was wrong. A 404 with an empty
 * body would still be a large improvement on the SPA shell, but it would
 * leave the reader hunting.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ApiFallbackController;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests the 404 fallback for unmatched shillinq API paths.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
final class ApiFallbackControllerTest extends TestCase {

	/**
	 * Build the controller with a mocked request.
	 *
	 * @return ApiFallbackController
	 */
	private function controller(): ApiFallbackController {
		return new ApiFallbackController('shillinq', $this->createMock(IRequest::class));

	}//end controller()

	/**
	 * The status is 404, not 200.
	 *
	 * This is the whole point: the SPA catch-all answered these with 200, which
	 * made `axios.get()` RESOLVE and the caller render an empty list.
	 *
	 * @return void
	 */
	public function testStatusIs404(): void {
		$response = $this->controller()->notFound('openregister/objects/CommitmentLine');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testStatusIs404()

	/**
	 * The body names the unmatched path.
	 *
	 * Without this the caller knows only that something 404'd, which is the
	 * state the old behaviour left them in.
	 *
	 * @return void
	 */
	public function testBodyNamesTheUnmatchedPath(): void {
		$data = $this->controller()->notFound('openregister/objects/CommitmentLine')->getData();

		self::assertArrayHasKey('message', $data);
		self::assertStringContainsString(
			'api/openregister/objects/CommitmentLine',
			$data['message'],
			'The response must name the path that did not match'
		);
		self::assertArrayHasKey('hint', $data);
		self::assertNotSame('', (string)$data['hint']);

	}//end testBodyNamesTheUnmatchedPath()

	/**
	 * A multi-segment path survives intact in the message.
	 *
	 * The url that motivated this is six segments deep; a fallback that
	 * reported only the first segment would misdirect the reader.
	 *
	 * @return void
	 */
	public function testMultiSegmentPathIsReportedWhole(): void {
		$path = 'openregister/objects/CommitmentLine/aggregations/committedVsRealisedPerBudgetLine';
		$data = $this->controller()->notFound($path)->getData();

		self::assertStringContainsString($path, (string)$data['message']);

	}//end testMultiSegmentPathIsReportedWhole()

	/**
	 * An empty path still answers 404 rather than erroring.
	 *
	 * The route declares a default, so the method must tolerate being called
	 * with nothing.
	 *
	 * @return void
	 */
	public function testEmptyPathStillAnswers404(): void {
		$response = $this->controller()->notFound();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertArrayHasKey('message', $response->getData());

	}//end testEmptyPathStillAnswers404()
}//end class
