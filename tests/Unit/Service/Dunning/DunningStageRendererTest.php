<?php

/**
 * Unit tests for DunningStageRenderer: the stage texts on the ladder, in the
 * customer's language, with the registry's merge fields filled.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use OCA\Shillinq\Service\Dunning\DunningStageDefaultTexts;
use OCA\Shillinq\Service\Dunning\DunningStageRenderer;
use OCA\Shillinq\Service\Dunning\DunningTemplateRegistry;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * DunningStageRenderer unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DunningStageRendererTest extends TestCase {

	/**
	 * The register fragment that seeds the ladders.
	 *
	 * @var string
	 */
	private const FRAGMENT = __DIR__ . '/../../../../lib/Settings/register.d/bookkeeping-credit-control-dunning.json';

	/**
	 * The renderer under test, on the real template registry.
	 *
	 * @return DunningStageRenderer
	 */
	private function renderer(): DunningStageRenderer {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		return new DunningStageRenderer(registry: new DunningTemplateRegistry(appConfig: $appConfig));
	}//end renderer()

	/**
	 * The ladders the fragment seeds.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function seededLadders(): array {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true);
		$ladders  = [];
		foreach ((array)($fragment['objects'] ?? []) as $object) {
			if (($object['@self']['schema'] ?? '') === 'DunningLadder') {
				$ladders[] = $object;
			}
		}

		return $ladders;
	}//end seededLadders()

	/**
	 * Invoice 2026-0412 of the spec's scenario.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function invoice(array $extra = []): array {
		return array_merge(
			[
				'id' => 'inv-0412',
				'invoiceNumber' => '2026-0412',
				'invoiceDate' => '2026-02-10',
				'dueDate' => '2026-03-12',
				'grossAmount' => 1210.0,
				'currency' => 'EUR',
				'buyerCountryCode' => 'NL',
			],
			$extra
		);
	}//end invoice()

	/**
	 * The amounts the caller works out for a stage: the bank account, the
	 * collection costs and the interest.
	 *
	 * @return array<string, mixed>
	 */
	private static function values(): array {
		return ['iban' => 'NL91ABNA0417164300', 'incassokosten' => 181.5, 'rente' => 12.4];
	}//end values()

	/**
	 * REQ-RAD-003: every seeded stage renders for a Dutch customer, with every
	 * merge field filled and the invoice named.
	 *
	 * @return void
	 */
	public function testEverySeededStageRendersInDutch(): void {
		$customer = ['legalName' => 'Bakkerij De Korenaar B.V.'];
		$count    = 0;
		foreach (self::seededLadders() as $ladder) {
			foreach ($ladder['stages'] as $stage) {
				$mail = $this->renderer()->render(stage: $stage, invoice: self::invoice(), customer: $customer, values: self::values());
				self::assertSame('nl', $mail['language']);
				self::assertSame(strtr($stage['subject']['nl'], ['{factuurNummer}' => '2026-0412']), $mail['subject']);
				self::assertStringNotContainsString('{', $mail['subject'] . $mail['body'], $ladder['name'] . ' stage ' . $stage['nr']);
				self::assertStringContainsString('2026-0412', $mail['subject'] . $mail['body']);
				self::assertStringContainsString('Bakkerij De Korenaar B.V.', $mail['body']);
				$count++;
			}
		}

		self::assertSame(9, $count, 'Both seeded ladders, nine stages.');
	}//end testEverySeededStageRendersInDutch()

	/**
	 * REQ-RAD-003: every seeded stage renders for an English customer, in
	 * English and with English dates and amounts.
	 *
	 * @return void
	 */
	public function testEverySeededStageRendersInEnglish(): void {
		$customer = ['legalName' => 'Harbour Freight Ltd'];
		foreach (self::seededLadders() as $ladder) {
			foreach ($ladder['stages'] as $stage) {
				$mail = $this->renderer()->render(
					stage: $stage,
					invoice: self::invoice(['buyerCountryCode' => 'GB']),
					customer: $customer,
					values: self::values()
				);
				self::assertSame('en', $mail['language']);
				self::assertSame(strtr($stage['subject']['en'], ['{factuurNummer}' => '2026-0412']), $mail['subject']);
				self::assertStringNotContainsString('{', $mail['subject'] . $mail['body']);
				self::assertStringContainsString('Harbour Freight Ltd', $mail['body']);
			}
		}

		$first = self::seededLadders()[0]['stages'][0];
		$mail  = $this->renderer()->render(stage: $first, invoice: self::invoice(['buyerCountryCode' => 'GB']), customer: $customer, values: self::values());
		self::assertStringContainsString('€1,210.00', $mail['body']);
		self::assertStringContainsString('12 March 2026', $mail['body']);
	}//end testEverySeededStageRendersInEnglish()

	/**
	 * The spec's scenario: the third stage announces the collection costs and
	 * names the invoice and the outstanding amount.
	 *
	 * @return void
	 */
	public function testTheThirdStageAnnouncesCollectionCosts(): void {
		$stage = self::seededLadders()[0]['stages'][2];
		$mail  = $this->renderer()->render(
			stage: $stage,
			invoice: self::invoice(),
			customer: ['legalName' => 'Bakkerij De Korenaar B.V.'],
			values: self::values()
		);

		self::assertSame('Aanmaning: betaal binnen 14 dagen om incassokosten te voorkomen', $mail['subject']);
		self::assertStringContainsString('factuur 2026-0412', strtolower($mail['body']));
		self::assertStringContainsString('€ 1.210,00', $mail['body']);
		self::assertStringContainsString('€ 181,50', $mail['body']);
		self::assertStringContainsString('12-03-2026', $mail['body']);
		self::assertStringContainsString('NL91ABNA0417164300', $mail['body']);
	}//end testTheThirdStageAnnouncesCollectionCosts()

	/**
	 * The outstanding amount is what is still due, not the invoice total.
	 *
	 * @return void
	 */
	public function testTheOutstandingAmountIsWhatIsStillDue(): void {
		$stage = self::seededLadders()[0]['stages'][0];
		$mail  = $this->renderer()->render(stage: $stage, invoice: self::invoice(['paidAmount' => 210.0]), customer: [], values: self::values());
		self::assertStringContainsString('€ 1.000,00', $mail['body']);

		$mail = $this->renderer()->render(stage: $stage, invoice: self::invoice(['amountDue' => 300.0]), customer: [], values: self::values());
		self::assertStringContainsString('€ 300,00', $mail['body']);
	}//end testTheOutstandingAmountIsWhatIsStillDue()

	/**
	 * A stage without texts (an override, an older ladder) gets the default
	 * text of its stage; a stage with only the other language uses that one.
	 *
	 * @return void
	 */
	public function testAStageWithoutTextsGetsTheDefaultOfItsStage(): void {
		$bare = ['nr' => 2, 'daysAfterExpiryDate' => 14, 'name' => 'Herinnering', 'channel' => 'EMAIL'];
		$mail = $this->renderer()->render(stage: $bare, invoice: self::invoice(), customer: [], values: self::values());
		$default = (new DunningStageDefaultTexts())->for(stageNr: 2, language: 'nl');
		self::assertSame(strtr($default['subject'], ['{factuurNummer}' => '2026-0412']), $mail['subject']);

		$dutchOnly = $bare + ['subject' => ['nl' => 'Alleen Nederlands {factuurNummer}'], 'body' => ['nl' => 'Tekst']];
		$mail      = $this->renderer()->render(stage: $dutchOnly, invoice: self::invoice(['buyerCountryCode' => 'GB']), customer: [], values: self::values());
		self::assertSame('Alleen Nederlands 2026-0412', $mail['subject']);
	}//end testAStageWithoutTextsGetsTheDefaultOfItsStage()

	/**
	 * The customer's language: an explicit language first, else the buyer's
	 * country, else Dutch.
	 *
	 * @return void
	 */
	public function testTheLanguageComesFromTheCustomer(): void {
		$renderer = $this->renderer();
		self::assertSame('en', $renderer->languageFor(invoice: self::invoice(), customer: ['language' => 'en_GB']));
		self::assertSame('nl', $renderer->languageFor(invoice: self::invoice(['buyerCountryCode' => 'GB']), customer: ['language' => 'nl']));
		self::assertSame('nl', $renderer->languageFor(invoice: self::invoice(['buyerCountryCode' => 'BE']), customer: []));
		self::assertSame('en', $renderer->languageFor(invoice: self::invoice(['buyerCountryCode' => 'DE']), customer: []));
		self::assertSame('nl', $renderer->languageFor(invoice: ['invoiceNumber' => 'x'], customer: []));
	}//end testTheLanguageComesFromTheCustomer()

	/**
	 * Every placeholder in the seeded and default texts is one of the
	 * registry's merge fields, so none reaches a customer unfilled.
	 *
	 * @return void
	 */
	public function testEveryPlaceholderIsAMergeField(): void {
		$appConfig = $this->createStub(IAppConfig::class);
		$fields    = (new DunningTemplateRegistry(appConfig: $appConfig))->mergeFields();
		$texts     = [];
		foreach (self::seededLadders() as $ladder) {
			foreach ($ladder['stages'] as $stage) {
				$texts = array_merge($texts, array_values($stage['subject']), array_values($stage['body']));
			}
		}

		$defaults = new DunningStageDefaultTexts();
		foreach ([1, 2, 3, 4, 5] as $stageNr) {
			foreach (['nl', 'en'] as $language) {
				$texts = array_merge($texts, array_values($defaults->for(stageNr: $stageNr, language: $language)));
			}
		}

		foreach ($texts as $text) {
			preg_match_all('/\{([^}]*)\}/', $text, $matches);
			foreach ($matches[1] as $placeholder) {
				self::assertContains($placeholder, $fields, $text);
			}

			self::assertStringNotContainsString('—', $text, 'No em-dash in a customer text.');
		}
	}//end testEveryPlaceholderIsAMergeField()

	/**
	 * The seeded ladders are valid DunningLadder objects, stage texts included,
	 * and the standard ladder carries exactly the default texts.
	 *
	 * @return void
	 */
	public function testTheSeededLaddersAreValidAndCarryTheDefaults(): void {
		$defaults = new DunningStageDefaultTexts();
		foreach (self::seededLadders() as $ladder) {
			$object = $ladder;
			unset($object['@self']);
			self::assertSame([], RegisterSchema::errors('DunningLadder', $object), $ladder['name']);
		}

		foreach (self::seededLadders()[0]['stages'] as $stage) {
			foreach (['nl', 'en'] as $language) {
				$default = $defaults->for(stageNr: (int)$stage['nr'], language: $language);
				self::assertSame($default['subject'], $stage['subject'][$language]);
				self::assertSame($default['body'], $stage['body'][$language]);
			}
		}
	}//end testTheSeededLaddersAreValidAndCarryTheDefaults()
}//end class
