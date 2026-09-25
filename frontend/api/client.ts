import axios, { AxiosError, type AxiosRequestConfig } from "axios";
import { API_BASE_URL, CSRF_COOKIE_URL } from "@/api/urls";
import type { ApiEnvelope, ApiErrorEnvelope } from "@/types/api";
import { emitSessionExpired } from "@/utils/sessionEvents";
import { getTillToken } from "@/utils/tillDevice";

/**
 * Error thrown by every API call. `fieldErrors` carries Laravel 422 messages
 * so forms can map them with `form.setErrors`.
 */
export class ApiError extends Error {
  readonly status: number;
  readonly fieldErrors: Record<string, string[]>;

  constructor(message: string, status: number, fieldErrors: Record<string, string[]> = {}) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.fieldErrors = fieldErrors;
  }

  /** First message per field, in the shape Mantine's form.setErrors expects. */
  get formErrors(): Record<string, string> {
    return Object.fromEntries(Object.entries(this.fieldErrors).map(([field, messages]) => [field, messages[0] ?? ""]));
  }
}

const http = axios.create({
  baseURL: API_BASE_URL,
  timeout: 30_000,
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: "XSRF-TOKEN",
  xsrfHeaderName: "X-XSRF-TOKEN",
  headers: {
    Accept: "application/json",
    "Content-Type": "application/json",
    "X-Requested-With": "XMLHttpRequest",
  },
});

// A paired till identifies itself on every request; the API ignores the header elsewhere.
http.interceptors.request.use((config) => {
  const tillToken = getTillToken();
  if (tillToken) config.headers.set("X-Till-Token", tillToken);
  return config;
});

/** Auth bootstrap endpoints must not trigger the global "session expired" flow. */
const SESSION_NEUTRAL_PATHS = ["/auth/login", "/auth/pin-login", "/auth/me"];

function isErrorEnvelope(value: unknown): value is ApiErrorEnvelope {
  return typeof value === "object" && value !== null && "success" in value && (value as { success: unknown }).success === false;
}

function toApiError(error: unknown): ApiError {
  if (!(error instanceof AxiosError)) {
    return new ApiError(error instanceof Error ? error.message : "Unexpected error.", 0);
  }

  if (!error.response) {
    const message =
      error.code === "ECONNABORTED" ? "The server took too long to respond." : "Unable to reach the server. Check your connection.";
    return new ApiError(message, 0);
  }

  const { status, data } = error.response;
  if (isErrorEnvelope(data)) {
    const fieldErrors = typeof data.errors === "object" && !Array.isArray(data.errors) ? data.errors : {};
    return new ApiError(data.message, status, fieldErrors);
  }

  return new ApiError(error.message || "Request failed.", status);
}

/**
 * Performs a request and returns the envelope's `data`, typed by the caller.
 */
export async function apiRequest<T>(config: AxiosRequestConfig): Promise<T> {
  try {
    const response = await http.request<ApiEnvelope<T>>(config);
    return response.data.data;
  } catch (error) {
    const apiError = toApiError(error);
    const path = config.url ?? "";

    if ((apiError.status === 401 || apiError.status === 419) && !SESSION_NEUTRAL_PATHS.includes(path)) {
      emitSessionExpired(apiError.status);
    }

    throw apiError;
  }
}

/** Ensures the XSRF-TOKEN cookie exists before a state-changing Sanctum request. */
export async function ensureCsrfCookie(): Promise<void> {
  try {
    await http.get(CSRF_COOKIE_URL, { baseURL: "" });
  } catch (error) {
    throw toApiError(error);
  }
}

/**
 * Downloads a file (e.g. a CSV export) with the session cookie and saves it in the browser.
 * Errors come back as JSON envelopes, so a failed download still raises an ApiError.
 */
export async function downloadFile(url: string, params: object, fallbackName: string): Promise<void> {
  try {
    const response = await http.get<Blob>(url, { params, responseType: "blob", headers: { Accept: "text/csv, application/json" } });
    const disposition = String(response.headers["content-disposition"] ?? "");
    const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? fallbackName;
    const link = document.createElement("a");
    link.href = URL.createObjectURL(response.data);
    link.download = name;
    link.click();
    URL.revokeObjectURL(link.href);
  } catch (error) {
    if (error instanceof AxiosError && error.response?.data instanceof Blob) {
      try {
        error.response.data = JSON.parse(await error.response.data.text());
      } catch {
        // not JSON; fall through with the generic message
      }
    }
    throw toApiError(error);
  }
}

export const api = {
  get: <T>(url: string, params?: object) => apiRequest<T>({ method: "GET", url, params }),
  post: <T>(url: string, data?: unknown) => apiRequest<T>({ method: "POST", url, data }),
  put: <T>(url: string, data?: unknown) => apiRequest<T>({ method: "PUT", url, data }),
  patch: <T>(url: string, data?: unknown) => apiRequest<T>({ method: "PATCH", url, data }),
  delete: <T>(url: string) => apiRequest<T>({ method: "DELETE", url }),
};
