# Namespace the mandate schema slug

## Why

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `Mandate` was answered for by dossiq's mandaat as
readily as by this app's. A fleet audit on 2026-09-05 found eighteen slugs in
that state; this is one, and this app created it.

`RenameCommitmentSchemas` renamed `Mandaat` to `Mandate` as part of the
Dutch-to-English vocabulary pass. That target was already taken.

The two are not one record. This app's is a spending ceiling in the
verplichtingenadministratie: who may sign commitments up to what amount, for
which soort_verplichting, with second-signature thresholds. dossiq's is an
administrative-law mandaat: a competence type, a legal basis, a mandated role.
They share **zero** declared fields.

## What changes

This app's slug becomes `SpendingMandate`, which is what it has always been.
dossiq keeps the bare `mandate`, which is the canonical Dutch government
meaning of the word.

The existing `SLUG_MAP` gains a second source for the same target, so an
install still on `Mandaat` goes straight to the namespaced slug and one already
on `Mandate` follows a step behind. If both somehow exist, `renameOne()`'s
existing guard refuses rather than merging.
