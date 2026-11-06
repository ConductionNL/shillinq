# Portal pages in Dutch, under a resident-facing group

## Why

Woo round 3 (hydra woo-citizen-journey, Ruben 2026-10-02): the site menu showed shillinq's pages under the app name
"Shillinq" and in English ("My invoices", "Purchase orders", "My orders"), next to Dutch pages from other apps.

## What changes

- Every shillinq portal page is declared with a `group` (portaliq's group contract): "Bestellingen en facturen" for a
  customer, "Schoolbijdragen" for a parent, "Opdrachten en facturen" for a supplier (the group dossiq's supplier
  pages use, so they share one heading), "Administratie" for an accountant. The blocks are the ones portaliq made
  when no page was declared: the list and the selected row.
- Every collection, column and action label is Dutch. The two customer invoice lists get two names ("Mijn
  facturen", "Mijn rekeningen"). The parent's decline action is "Ik betaal niet", the name the Dutch voluntary
  reminder already tells parents to choose.

## Impact

- `lib/Portal/PortalContributionProvider.php` and its test. No register change, no new l10n key (portal labels are
  Dutch literals, like the other contributing apps).
