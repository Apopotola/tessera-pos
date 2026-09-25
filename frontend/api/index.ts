/**
 * Barrel for all API modules. Import APIs from "@/api", never call axios directly.
 */
export { api, ApiError, ensureCsrfCookie } from "@/api/client";
export { authApi, authorizationApi } from "@/api/endpoints/auth";
export { organisationApi } from "@/api/endpoints/organisation";
export { auditTrailApi } from "@/api/endpoints/auditTrail";
