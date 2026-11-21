# Storekeeper's guide: entering opening stock

For the **storekeeper** at Epic Lounge Social House. How to count the beers and whiskies already in the shop and put the numbers into Tessera, so the till knows what is on the shelf.

- **Back office:** https://pos.tesseratech.co.ke (sign in with **your own** email and password)
- **Approves your work:** Brian (the owner) or a manager
- **Help:** Tessera support, `support@tesseratech.co.ke`

> **Important:** Whisky opening stock was already entered and approved on 21 November 2026. Do **not** enter it again, or the stock will be counted twice. Count the whiskies only if Brian asks you to check them, and then follow *Corrections* at the end of this guide.

---

## Who does what

| Step | Who |
| --- | --- |
| Count the bottles and cans, and enter them in Tessera | **Storekeeper** (you) |
| Give the **buying price** of each bottle or can | **Brian** (he knows the supplier prices) |
| Check your entry against the count sheet and **Approve** it | **Brian** or a manager. You cannot approve your own. |

## Step 1. Count on paper first

Count each place separately and write it on a count sheet like this:

| Item | Size | Shop floor (behind the bar) | Back store | Buying price (KES) |
| --- | --- | --- | --- | --- |
| Tusker Lager | 500 ml bottle | 18 | 72 | |
| Guinness Foreign Extra Stout | 500 ml can | 12 | 48 | |
| … | | | | |

- Count **full, unopened** bottles and cans only.
- A crate of 24 is **24 bottles**. Always write single bottles or cans, never crates.
- Count the **shop floor** and the **back store** separately.
- Count **before the bar opens**, when nothing is being sold.
- The bottle and the can of the same beer are **different items**. Count them on separate lines.
- Ask Brian for the **buying price** of each item and write it in the last column.

## Step 2. Enter the shop floor count

1. Sign in to the back office with your own account.
2. In the menu, open **Inventory → Breakages & adjustments**.
3. Click **New adjustment**.
4. **What happened:** choose **Opening stock**.
5. **Location:** choose **Shop floor**.
6. **Item:** type part of the name, for example *tusk*, and pick the right one. Check the **size** (bottle or can, 500 ml or 330 ml).
7. **Qty:** the number of bottles or cans on the shop floor.
8. **Unit cost (KES):** the buying price of **one** bottle or can.
9. Add the next item and repeat steps 6 to 8 for every line on your sheet. Leave out items with 0 on the shop floor.
10. **Reason:** for example *Opening stock: beers, shop floor, 21 Nov 2026*.
11. Click **Send for approval**.

## Step 3. Enter the back store count

Do Step 2 again with **Location: Back store** and the back store numbers. Use the reason *Opening stock: beers, back store, 21 Nov 2026*.

> **Tip:** One adjustment per location. Put all the beers for that location in the same adjustment.

## Step 4. Brian approves

Your adjustments show as **Pending** in *Inventory → Breakages & adjustments*. Give Brian your count sheet. He checks each line against it and clicks **Approve**, or **Reject** with a reason.

If he rejects one, read the reason, click **New adjustment**, and enter it again correctly.

## Step 5. Check the result

After approval:

- **Inventory → Stock levels** shows each item, with the shop floor and the back store separately.
- On the till, each beer shows how many are left on the shop floor.
- On the dashboard, **Stock value (at cost)** goes up.

---

## Corrections after approval

An approved adjustment can never be changed. The record of every stock movement protects you and Brian. If a number was wrong, make a new adjustment for the **difference**:

| What went wrong | What happened | Qty to enter |
| --- | --- | --- |
| You entered **too few** | **Found** | The extra bottles, with their unit cost |
| You entered **too many** | **Missing** | The bottles to take off |

Write the reason clearly, for example *Correction: counted 72 Tusker in the back store, entered 70*.

## After today: your daily jobs

You only use **Opening stock** once. From now on:

| When | What to do in Tessera |
| --- | --- |
| A delivery arrives | **Inventory → Receive stock**, against the purchase order. Count before you post. |
| You move crates from the back store to the bar | **Inventory → Transfers**, from Back store to Shop floor. |
| A bottle breaks | **New adjustment → Breakage**, with the quantity and what happened. |
| Brian asks for a stock count | **Inventory → Stock counts**. Count what is really there; the system works out the difference. |

> **Common mistake:** Carrying crates to the bar without a **transfer**. The till then thinks the bar has run out while the back store looks full.

## Rules

1. Use **your own account**. Never sign in for someone else.
2. Count **what is really there**, not what you expect.
3. Never enter a number you have not counted.
4. When in doubt, ask Brian **before** you send it for approval.
