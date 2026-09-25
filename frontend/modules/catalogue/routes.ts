import type { OpenTabConfig } from "@/store/slices/tabsSlice";
import type { Product } from "@/types/catalogue";

export const productDetailPath = (id: number) => `/catalogue/products/${id}`;

/** Tab config for a product screen, opened as a child of `parentTabId` when given. */
export function productDetailTab(product: Pick<Product, "id" | "name">, parentTabId?: string): OpenTabConfig {
  return {
    title: product.name,
    path: productDetailPath(product.id),
    view: "productDetail",
    recordId: product.id,
    parentTabId: parentTabId ?? null,
    props: { productId: product.id },
  };
}
