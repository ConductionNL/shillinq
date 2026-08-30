<?php

/**
 * Unit tests for SmsTemplateRenderer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sms;

use OCA\Shillinq\Service\Sms\SmsTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies {{variable}} substitution, truncation, segmentation and the
 * allowed-variable validation.
 */
final class SmsTemplateRendererTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var SmsTemplateRenderer
	 */
	private SmsTemplateRenderer $renderer;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->renderer = new SmsTemplateRenderer();

	}//end setUp()

	/**
	 * Sample data renders to the expected body and stays within one segment.
	 *
	 * @return void
	 */
	public function testRenderSubstitutesSampleData(): void {
		$tpl = 'Hallo {{customerName}}, boeking {{bookingRef}} op {{bookingDate}} om {{bookingTime}}.';
		$body = $this->renderer->render(
			$tpl,
			[
				'customerName' => 'Jan Jansen',
				'bookingRef' => 'BK001',
				'bookingDate' => '21 mei',
				'bookingTime' => '14:30',
			]
		);

		self::assertSame('Hallo Jan Jansen, boeking BK001 op 21 mei om 14:30.', $body);
		self::assertTrue($this->renderer->fitsSingleSegment($body));
		self::assertSame(1, $this->renderer->segmentCount($body));

	}//end testRenderSubstitutesSampleData()

	/**
	 * Undefined variables render as an empty string.
	 *
	 * @return void
	 */
	public function testUndefinedVariableRendersEmpty(): void {
		self::assertSame('Hi !', $this->renderer->render('Hi {{customerName}}!', []));

	}//end testUndefinedVariableRendersEmpty()

	/**
	 * Whitespace inside the braces is tolerated.
	 *
	 * @return void
	 */
	public function testWhitespaceInPlaceholderIsTolerated(): void {
		self::assertSame('Ref BK9', $this->renderer->render('Ref {{ bookingRef }}', ['bookingRef' => 'BK9']));

	}//end testWhitespaceInPlaceholderIsTolerated()

	/**
	 * Location > 30 and organization > 20 chars are truncated with an ellipsis.
	 *
	 * @return void
	 */
	public function testTruncatesLongVariables(): void {
		$longLocation = 'Kantoor Amsterdam Zuidas Toren Hoog 42 Etage';
		$body = $this->renderer->render('{{bookingLocation}}', ['bookingLocation' => $longLocation]);

		self::assertSame(30, mb_strlen($body));
		self::assertStringEndsWith('…', $body);

		$org = 'Een Hele Lange Organisatienaam BV';
		$bodyOrg = $this->renderer->render('{{organizationName}}', ['organizationName' => $org]);
		self::assertSame(20, mb_strlen($bodyOrg));
		self::assertStringEndsWith('…', $bodyOrg);

	}//end testTruncatesLongVariables()

	/**
	 * Short values are not truncated.
	 *
	 * @return void
	 */
	public function testShortValuesNotTruncated(): void {
		self::assertSame('Kantoor', $this->renderer->render('{{bookingLocation}}', ['bookingLocation' => 'Kantoor']));

	}//end testShortValuesNotTruncated()

	/**
	 * Segment counting: ≤160 = 1, longer splits into 153-char concat segments.
	 *
	 * @return void
	 */
	public function testSegmentCount(): void {
		self::assertSame(1, $this->renderer->segmentCount(str_repeat('a', 160)));
		self::assertSame(2, $this->renderer->segmentCount(str_repeat('a', 161)));
		self::assertSame(2, $this->renderer->segmentCount(str_repeat('a', 306)));
		self::assertSame(3, $this->renderer->segmentCount(str_repeat('a', 307)));
		self::assertFalse($this->renderer->fitsSingleSegment(str_repeat('a', 161)));

	}//end testSegmentCount()

	/**
	 * Disallowed variables are reported; an all-allowed template is clean.
	 *
	 * @return void
	 */
	public function testUnknownVariables(): void {
		self::assertSame([], $this->renderer->unknownVariables('Hi {{customerName}} ref {{bookingRef}}'));
		self::assertSame(['totallyBogus'], $this->renderer->unknownVariables('Hi {{customerName}} {{totallyBogus}}'));

	}//end testUnknownVariables()

}//end class
