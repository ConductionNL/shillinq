<?php

/**
 * The default subject and body of each dunning stage, in Dutch and English.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
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

namespace OCA\Shillinq\Service\Dunning;

/**
 * The text a stage gets when its ladder carries none (an override, a ladder
 * made before stages had texts). One text per stage, in the tone
 * DunningTemplateRegistry gives that stage. The seeded standard ladder carries
 * exactly these texts, so an administration can edit them on the ladder.
 *
 * Placeholders are DunningTemplateRegistry's merge fields, written `{name}`.
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.1
 */
final class DunningStageDefaultTexts {

	/**
	 * Stage number => language => subject and body.
	 *
	 * @var array<int, array<string, array{subject: string, body: string}>>
	 */
	private const TEXTS = [
		1 => [
			'nl' => [
				'subject' => 'Vriendelijke herinnering: factuur {factuurNummer}',
				'body' => "Beste {klantNaam},\n\nMisschien is het u ontgaan. Factuur {factuurNummer} van {invoiceDate} is nog niet betaald. Het openstaande bedrag is {outstandingAmount}. De vervaldatum was {expiryDate}.\n\nWilt u het bedrag overmaken naar {iban}? Vermeld daarbij het factuurnummer.\n\nHeeft u al betaald? Dan kunt u deze herinnering negeren.\n\nMet vriendelijke groet",
			],
			'en' => [
				'subject' => 'Friendly reminder: invoice {factuurNummer}',
				'body' => "Dear {klantNaam},\n\nPerhaps this slipped your mind. Invoice {factuurNummer} of {invoiceDate} is still unpaid. The amount due is {outstandingAmount}. It was due on {expiryDate}.\n\nPlease transfer the amount to {iban} and quote the invoice number.\n\nAlready paid? Then you can ignore this reminder.\n\nKind regards",
			],
		],
		2 => [
			'nl' => [
				'subject' => 'Tweede herinnering: factuur {factuurNummer}',
				'body' => "Beste {klantNaam},\n\nFactuur {factuurNummer} van {invoiceDate} staat nog open. Het openstaande bedrag is {outstandingAmount}. De vervaldatum was {expiryDate}.\n\nWij vragen u het bedrag binnen {betalingstermijn} dagen over te maken naar {iban}. Vermeld daarbij het factuurnummer.\n\nKlopt er iets niet aan de factuur? Laat het ons dan weten.\n\nMet vriendelijke groet",
			],
			'en' => [
				'subject' => 'Second reminder: invoice {factuurNummer}',
				'body' => "Dear {klantNaam},\n\nInvoice {factuurNummer} of {invoiceDate} is still open. The amount due is {outstandingAmount}. It was due on {expiryDate}.\n\nPlease transfer the amount to {iban} within {betalingstermijn} days and quote the invoice number.\n\nIs something wrong with the invoice? Then please let us know.\n\nKind regards",
			],
		],
		3 => [
			'nl' => [
				'subject' => 'Aanmaning: betaal binnen 14 dagen om incassokosten te voorkomen',
				'body' => "Geachte {klantNaam},\n\nFactuur {factuurNummer} van {invoiceDate} is nog steeds niet betaald. Het openstaande bedrag is {outstandingAmount}. De vervaldatum was {expiryDate}.\n\nU krijgt nog 14 dagen om te betalen, vanaf de dag na ontvangst van deze aanmaning. Binnen die termijn betaalt u geen extra kosten.\n\nBetaalt u niet binnen 14 dagen? Dan brengen wij {incassokosten} incassokosten in rekening. Daarnaast bent u dan wettelijke rente verschuldigd, nu {rente}.\n\nMaak het bedrag over naar {iban} en vermeld het factuurnummer.\n\nMet vriendelijke groet",
			],
			'en' => [
				'subject' => 'Final notice: pay within 14 days to avoid collection costs',
				'body' => "Dear {klantNaam},\n\nInvoice {factuurNummer} of {invoiceDate} is still unpaid. The amount due is {outstandingAmount}. It was due on {expiryDate}.\n\nYou have 14 more days to pay, counted from the day after you receive this notice. Within that period you pay no extra costs.\n\nIf you do not pay within 14 days, we will charge {incassokosten} in collection costs. Statutory interest is then also due, currently {rente}.\n\nPlease transfer the amount to {iban} and quote the invoice number.\n\nKind regards",
			],
		],
		4 => [
			'nl' => [
				'subject' => 'Ingebrekestelling: factuur {factuurNummer}',
				'body' => "Geachte {klantNaam},\n\nOndanks onze herinneringen is factuur {factuurNummer} van {invoiceDate} niet betaald. Het openstaande bedrag is {outstandingAmount}. De vervaldatum was {expiryDate}.\n\nMet deze brief stellen wij u in gebreke. Betaal het bedrag binnen {betalingstermijn} dagen. Daarbij komen {incassokosten} incassokosten en {rente} wettelijke rente.\n\nMaak het totaal over naar {iban} en vermeld het factuurnummer. Betaalt u niet op tijd? Dan dragen wij de vordering over aan een incassobureau.\n\nHoogachtend",
			],
			'en' => [
				'subject' => 'Notice of default: invoice {factuurNummer}',
				'body' => "Dear {klantNaam},\n\nDespite our reminders, invoice {factuurNummer} of {invoiceDate} is unpaid. The amount due is {outstandingAmount}. It was due on {expiryDate}.\n\nWith this letter we give you notice of default. Pay the amount within {betalingstermijn} days. Collection costs of {incassokosten} and statutory interest of {rente} are added.\n\nPlease transfer the total to {iban} and quote the invoice number. If you do not pay in time, we will hand the claim to a collection agency.\n\nYours faithfully",
			],
		],
		5 => [
			'nl' => [
				'subject' => 'Overdracht aan incassobureau: factuur {factuurNummer}',
				'body' => "Geachte {klantNaam},\n\nFactuur {factuurNummer} van {invoiceDate} is ondanks onze ingebrekestelling niet betaald. Het openstaande bedrag is {outstandingAmount}.\n\nWij hebben de vordering overgedragen aan een incassobureau. Het incassobureau neemt contact met u op. Betaal vanaf nu via het incassobureau.\n\nHoogachtend",
			],
			'en' => [
				'subject' => 'Claim handed to a collection agency: invoice {factuurNummer}',
				'body' => "Dear {klantNaam},\n\nDespite our notice of default, invoice {factuurNummer} of {invoiceDate} is unpaid. The amount due is {outstandingAmount}.\n\nWe have handed the claim to a collection agency. The agency will contact you. From now on, please pay through the agency.\n\nYours faithfully",
			],
		],
	];

	/**
	 * The default subject and body of a stage in a language. A stage past the
	 * last one gets the last stage's text; a language other than `en` gets Dutch.
	 *
	 * @param int    $stageNr  The stage number.
	 * @param string $language `nl` or `en`.
	 *
	 * @return array{subject: string, body: string} The text, placeholders unfilled.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.1
	 */
	public function for(int $stageNr, string $language): array {
		$stageNr = max(1, min($stageNr, count(self::TEXTS)));
		if ($language !== 'en') {
			$language = 'nl';
		}

		return self::TEXTS[$stageNr][$language];
	}//end for()
}//end class
