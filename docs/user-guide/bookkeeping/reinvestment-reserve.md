---
sidebar_position: 7
title: Depreciation changes and the reinvestment reserve
description: Revise an asset's depreciation, book extra depreciation, and keep the gain on a sold asset for its replacement.
---

# Depreciation changes and the reinvestment reserve

Shillinq posts depreciation every month. On the first days of a month it posts the depreciation of the month before, one journal entry per administration: the depreciation expense account is debited and accumulated depreciation is credited, per asset. Each schedule line then shows posted.

## Missed months

An asset that was added late, or a month the daily run did not post, shows up under **Post missed depreciation** on the asset's page. You see each month and its amount first, then post them. Each month becomes its own journal entry.

## Revise depreciation

Open an active asset and choose **Revise depreciation**. Enter the date the change applies from, a new method or a new useful life (the total in months from acquisition), and the reason.

Shillinq plans the months from that date again, from the book value on that date over the months that are left. Months that are already posted stay as they are. You cannot revise from a date before a posted month.

Example: an oven bought on 1 January 2024 for 60,000 euros, depreciated straight line over 120 months, is worth 45,000 euros on 1 July 2026. Its useful life is shortened to 90 months in total, so 60 months are left. From July 2026 the depreciation is 750 euros a month.

## Extra depreciation

Choose **Extra depreciation** on an active asset when it loses value at once, for example through water damage. Enter the amount, the date and the reason. The extra depreciation is posted straight away, and the remaining months are planned again from the lower book value.

## Reinvestment reserve

When you sell an asset at a gain and plan to replace it, you can keep the gain in a reinvestment reserve (*herinvesteringsreserve*, article 3.54 Wet IB 2001) instead of booking it as profit.

1. On the asset's page, choose **Dispose**, enter the disposal date and the proceeds, and switch on **Add the gain to a reinvestment reserve**.
2. The gain is credited to the reserve account instead of profit. The reserve is listed under **Reinvestment reserves**, with an expiry date at the end of the third year after the year of the sale.
3. When you buy the replacement, open the new asset and choose **Apply reinvestment reserve**. Pick the reserve and, if you want, the amount. The new asset's fiscal cost basis is lowered by that amount.
4. A remainder that is still open after the expiry date is released to profit by the daily run.

Example: the old delivery van, with a book value of 8,000 euros, is sold for 14,000 euros on 31 March 2026. The gain of 6,000 euros goes to a reserve that expires on 31 December 2029. The new van, bought on 15 September 2026 for 42,000 euros, gets a fiscal cost basis of 36,000 euros.

The accounts are set in the app settings: `fixed_asset_reinvestment_reserve_account` (default 0640) and `fixed_asset_reinvestment_release_account` (default: the disposal gain account).
