import { expect, test } from "@playwright/test";
import { cartVat, itemsInCategory, newLine } from "@/modules/till/cart";
import type { TillItem } from "@/types/sales";

const item = (variantId: number, displayName: string, categoryIds: number[], taxRatePercent = 16): TillItem => ({
  variantId,
  displayName,
  sku: `SKU-${variantId}`,
  priceCents: 116000,
  minPriceCents: null,
  wholesalePriceCents: null,
  taxRatePercent,
  onFloor: 4,
  categoryIds,
  brandId: null,
  imageUrl: null,
  alcoholic: true,
  totMl: null,
  totPriceCents: null,
  openBottleMl: null,
});

test("the product grid shows the chosen category (or its sub-categories) by name", () => {
  const items = [item(1, "Tusker", [12, 11]), item(2, "Jameson", [5, 4]), item(3, "Black & White", [6, 4])];

  expect(itemsInCategory(items, null).map((i) => i.displayName)).toEqual(["Black & White", "Jameson", "Tusker"]);
  expect(itemsInCategory(items, 4).map((i) => i.displayName)).toEqual(["Black & White", "Jameson"]);
  expect(itemsInCategory(items, 99)).toEqual([]);
});

test("VAT included in the cart: 16% of a VAT-inclusive price, after discounts", () => {
  const whisky = { ...newLine(item(1, "Jameson", [4]), 2), discountCents: 32000 };
  const water = newLine(item(2, "Water", [9], 0), 1);
  // A recalled parked line has no rate on it: the saved catalogue supplies it.
  const recalled = { ...newLine(item(3, "Tusker", [11]), 1), taxRatePercent: null };

  // (2 × 1,160 − 320) × 16/116 = 275.86; water is zero-rated; 1,160 × 16/116 = 160.
  expect(cartVat([whisky, water], () => undefined)).toBe(27586);
  expect(cartVat([recalled], (id) => (id === 3 ? 16 : undefined))).toBe(16000);
  expect(cartVat([recalled], () => undefined)).toBe(0);
});
