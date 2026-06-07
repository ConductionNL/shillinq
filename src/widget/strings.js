/**
 * Bundled i18n string tables for the standalone self-service widget
 * (REQ-WSW-010). The widget runs outside Nextcloud (iframe/script/npm/web
 * component) where the global `t()` helper is unavailable, so user-facing
 * strings are resolved from this table by the embed-time `lang`. Keys mirror
 * the English source strings in l10n/en.json for consistency.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

export const WIDGET_STRINGS = {
	en: {
		'Book an appointment': 'Book an appointment',
		'Service': 'Service',
		'Choose a service': 'Choose a service',
		'Date': 'Date',
		'Time': 'Time',
		'Available times': 'Available times',
		'No times available for this day': 'No times available for this day',
		'Your details': 'Your details',
		'Name': 'Name',
		'Email': 'Email',
		'Phone (optional)': 'Phone (optional)',
		'Notes (optional)': 'Notes (optional)',
		'Review your booking': 'Review your booking',
		'Confirm booking': 'Confirm booking',
		'Booking confirmed': 'Booking confirmed',
		'Back': 'Back',
		'minutes': 'minutes',
		'Please enter your name': 'Please enter your name',
		'Please enter a valid email address': 'Please enter a valid email address',
		'Please enter a valid phone number': 'Please enter a valid phone number',
		'This slot was just booked. Please select another time.': 'This slot was just booked. Please select another time.',
		'This service is no longer available. Please refresh the page.': 'This service is no longer available. Please refresh the page.',
		'Network error. Please check your connection and try again.': 'Network error. Please check your connection and try again.',
		'Something went wrong. Please try again later.': 'Something went wrong. Please try again later.',
		'Configuration error. Please contact the website owner.': 'Configuration error. Please contact the website owner.',
		'Try again': 'Try again',
	},
	nl: {
		'Book an appointment': 'Een afspraak maken',
		'Service': 'Dienst',
		'Choose a service': 'Kies een dienst',
		'Date': 'Datum',
		'Time': 'Tijd',
		'Available times': 'Beschikbare tijden',
		'No times available for this day': 'Geen tijden beschikbaar voor deze dag',
		'Your details': 'Uw gegevens',
		'Name': 'Naam',
		'Email': 'E-mailadres',
		'Phone (optional)': 'Telefoon (optioneel)',
		'Notes (optional)': 'Opmerkingen (optioneel)',
		'Review your booking': 'Controleer uw boeking',
		'Confirm booking': 'Boeking bevestigen',
		'Booking confirmed': 'Boeking bevestigd',
		'Back': 'Terug',
		'minutes': 'minuten',
		'Please enter your name': 'Voer uw naam in',
		'Please enter a valid email address': 'Voer een geldig e-mailadres in',
		'Please enter a valid phone number': 'Voer een geldig telefoonnummer in',
		'This slot was just booked. Please select another time.': 'Dit tijdslot is zojuist geboekt. Kies een andere tijd.',
		'This service is no longer available. Please refresh the page.': 'Deze dienst is niet meer beschikbaar. Vernieuw de pagina.',
		'Network error. Please check your connection and try again.': 'Netwerkfout. Controleer uw verbinding en probeer het opnieuw.',
		'Something went wrong. Please try again later.': 'Er is iets misgegaan. Probeer het later opnieuw.',
		'Configuration error. Please contact the website owner.': 'Configuratiefout. Neem contact op met de website-eigenaar.',
		'Try again': 'Opnieuw proberen',
	},
}
