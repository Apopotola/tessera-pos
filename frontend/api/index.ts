/**
 * Barrel for all API modules. Import APIs from "@/api", never call axios directly.
 */
export { api, ApiError, ensureCsrfCookie } from "@/api/client";
export { authApi, authorizationApi, usersApi } from "@/api/endpoints/auth";
export { salesApi } from "@/api/endpoints/sales";
export { organisationApi } from "@/api/endpoints/organisation";
export { auditTrailApi } from "@/api/endpoints/auditTrail";
export { catalogueApi } from "@/api/endpoints/catalogue";
export { dashboardApi } from "@/api/endpoints/dashboard";
export { inventoryApi } from "@/api/endpoints/inventory";
export { purchasingApi } from "@/api/endpoints/purchasing";
export { paymentsApi } from "@/api/endpoints/payments";
export { complianceApi } from "@/api/endpoints/compliance";
export { reportsApi } from "@/api/endpoints/reports";
export { customersApi } from "@/api/endpoints/customers";
