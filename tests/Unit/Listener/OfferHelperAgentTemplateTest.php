<?php

/**
 * Offering the shillinq helper to hermiq: what the package holds, and when
 * it is offered (REQ-PHA-002).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-help-agent/specs/shillinq-mcp-tool-surface/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Shillinq\Listener\OfferHelperAgentTemplate;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * OfferHelperAgentTemplate with the shipped template.
 */
#[CoversClass(OfferHelperAgentTemplate::class)]
final class OfferHelperAgentTemplateTest extends TestCase {

	/**
	 * The fields hermiq's AgentTemplateSerializer reads from a package; nothing else may travel.
	 *
	 * @var array<int, string>
	 */
	private const PORTABLE = ['name', 'description', 'category', 'systemPrompt', 'suggestedProvider', 'suggestedModel', 'tools', 'skillRefs', 'suggestedSchedule', 'version'];

	/**
	 * A stand-in for hermiq's CollectAgentTemplatesEvent.
	 *
	 * @return Event The event, with its offers in `$offers`.
	 */
	private static function collectEvent(): Event {
		return new class extends Event {
			/**
			 * The offers made.
			 *
			 * @var array<int, array{appId: string, package: string}>
			 */
			public array $offers = [];

			/**
			 * Record an offer.
			 *
			 * @param string $appId   The offering app.
			 * @param string $package The package.
			 *
			 * @return void
			 */
			public function offer(string $appId, string $package): void {
				$this->offers[] = ['appId' => $appId, 'package' => $package];
			}
		};
	}//end collectEvent()

	/**
	 * Shillinq offers the shipped package under its own app id.
	 *
	 * @return void
	 */
	public function testTheHelperIsOfferedUnderShillinq(): void {
		$event = self::collectEvent();

		(new OfferHelperAgentTemplate($this->createMock(LoggerInterface::class)))->handle($event);

		self::assertCount(1, $event->offers);
		self::assertSame('shillinq', $event->offers[0]['appId']);
		self::assertSame('Shillinq helper', json_decode($event->offers[0]['package'], true)['name']);
		self::assertSame('OCA\Hermiq\Event\CollectAgentTemplatesEvent', OfferHelperAgentTemplate::EVENT);
	}//end testTheHelperIsOfferedUnderShillinq()

	/**
	 * The package carries only portable fields: no secret, tenant or lifecycle field.
	 *
	 * @return void
	 */
	public function testThePackageCarriesNoSecret(): void {
		$package = json_decode((string)(new OfferHelperAgentTemplate($this->createMock(LoggerInterface::class)))->package(), true);

		self::assertSame([], array_values(array_diff(array_keys($package), self::PORTABLE)));
		self::assertDoesNotMatchRegularExpression('/(api[_-]?key|secret|token|password|organisation|tenant)/i', (string)json_encode($package));
	}//end testThePackageCarriesNoSecret()

	/**
	 * The helper only reads help articles, and says it cites their documentation link and the user's page.
	 *
	 * @return void
	 */
	public function testTheHelperOnlyReadsHelpArticles(): void {
		$package = json_decode((string)(new OfferHelperAgentTemplate($this->createMock(LoggerInterface::class)))->package(), true);

		self::assertSame(['shillinq.HelpArticle.search', 'shillinq.HelpArticle.get'], $package['tools']);
		self::assertStringContainsString('documentationUrl', $package['systemPrompt']);
		self::assertStringContainsString('pageIds', $package['systemPrompt']);
	}//end testTheHelperOnlyReadsHelpArticles()

	/**
	 * An event that is not a collect event gets nothing; an unreadable package offers nothing and is logged.
	 *
	 * @return void
	 */
	public function testNothingIsOfferedForAnotherEventOrABrokenPackage(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$broken = tempnam(sys_get_temp_dir(), 'tpl');
		file_put_contents($broken, 'not json');
		$event = self::collectEvent();

		(new OfferHelperAgentTemplate($logger))->handle(new Event());
		(new OfferHelperAgentTemplate($logger, $broken))->handle($event);
		unlink($broken);

		self::assertSame([], $event->offers);
	}//end testNothingIsOfferedForAnotherEventOrABrokenPackage()
}//end class
