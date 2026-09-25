import { api } from "@/api/client";
import { CATALOGUE_URLS as U } from "@/api/urls";
import type { Paginated } from "@/types/api";
import type {
  Brand,
  BrandPayload,
  BarcodeLookup,
  Category,
  CategoryPayload,
  CreateProductPayload,
  NewVariantPayload,
  PackPayload,
  PricePayload,
  PriceRecord,
  PriceStatus,
  Product,
  ProductFilters,
  ProductPayload,
  TaxRate,
  Variant,
  VariantPayload,
  WithWarnings,
} from "@/types/catalogue";

export const catalogueApi = {
  taxRates: () => api.get<TaxRate[]>(U.taxRates),

  brands: () => api.get<Brand[]>(U.brands),
  createBrand: (payload: BrandPayload) => api.post<Brand>(U.brands, payload),
  updateBrand: (id: number, payload: BrandPayload) => api.put<Brand>(U.brand(id), payload),

  categories: () => api.get<Category[]>(U.categories),
  createCategory: (payload: CategoryPayload) => api.post<Category>(U.categories, payload),
  updateCategory: (id: number, payload: CategoryPayload) => api.put<Category>(U.category(id), payload),

  products: (filters: ProductFilters = {}) => api.get<Paginated<Product>>(U.products, filters),
  product: (id: number, branchId?: number) => api.get<Product>(U.product(id), branchId ? { branchId } : undefined),
  createProduct: (payload: CreateProductPayload) => api.post<{ product: Product } & WithWarnings>(U.products, payload),
  updateProduct: (id: number, payload: ProductPayload) => api.put<Product>(U.product(id), payload),

  createVariant: (productId: number, payload: NewVariantPayload) =>
    api.post<{ variant: Variant } & WithWarnings>(U.productVariants(productId), payload),
  updateVariant: (id: number, payload: VariantPayload) => api.put<Variant>(U.variant(id), payload),
  addBarcode: (variantId: number, code: string, packId: number | null = null) =>
    api.post<Variant>(U.variantBarcodes(variantId), { code, packId }),
  removeBarcode: (variantId: number, barcodeId: number) => api.delete<Variant>(U.variantBarcode(variantId, barcodeId)),
  createPack: (variantId: number, payload: PackPayload) => api.post<Variant>(U.variantPacks(variantId), payload),
  updatePack: (id: number, payload: PackPayload) => api.put<Variant>(U.pack(id), payload),
  lookup: (code: string, branchId?: number) => api.get<BarcodeLookup>(U.lookup(code), branchId ? { branchId } : undefined),

  prices: (status: PriceStatus = "pending", page = 1) => api.get<Paginated<PriceRecord>>(U.prices, { status, page }),
  priceHistory: (variantId: number) => api.get<PriceRecord[]>(U.variantPrices(variantId)),
  requestPrice: (variantId: number, payload: PricePayload) =>
    api.post<{ price: PriceRecord } & WithWarnings>(U.variantPrices(variantId), payload),
  approvePrice: (id: number, note: string | null = null) => api.post<PriceRecord>(U.approvePrice(id), { note }),
  rejectPrice: (id: number, note: string) => api.post<PriceRecord>(U.rejectPrice(id), { note }),
};
