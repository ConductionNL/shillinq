# Migration Plan: <change-name>

<!-- Use when the design introduces new tables, columns, schema changes, or data transformations.
     Follow Nextcloud's migration framework — each migration is a versioned class in lib/Migration/. -->

## Current State

<!-- What the schema/data looks like before this change. -->

## Target State

<!-- What the schema/data should look like after this change. -->

## Migration Class

<!-- Outline the Nextcloud migration class: version number, changeSchema() method, key operations. -->

## Migration Steps

<!-- Ordered steps the migration executes. Each step MUST be atomic and verifiable. -->

1. <step>

## Data Impact

<!-- How many records are affected? Is there data loss or transformation? Can it run on live data? -->

## Rollback Procedure

<!-- How to revert if the migration fails — reverse migration class or SQL. -->

## Validation

<!-- How to verify the migration succeeded — queries, checks, expected counts. -->
