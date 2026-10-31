import { notifications } from "@mantine/notifications";
import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError } from "@/api";

export interface MutationOptions<TResult> {
  /** Notification shown on success; omit for silent success. */
  successMessage?: string | ((result: TResult) => string);
  /** Receives Laravel 422 errors keyed by field path (first message each). */
  onValidationError?: (errors: Record<string, string>) => void;
  onSuccess?: (result: TResult) => void;
}

/**
 * Save/submit helper: blocks duplicate submits while a request is in flight,
 * maps 422s to form errors and shows the standard success/error notifications.
 * Resolves to the result, or undefined when the request failed.
 */
export function useApiMutation<TArgs extends unknown[], TResult>(
  fn: (...args: TArgs) => Promise<TResult>,
  options: MutationOptions<TResult> = {},
) {
  const [pending, setPending] = useState(false);
  const inFlight = useRef(false);
  const latest = useRef({ fn, options });

  useEffect(() => {
    latest.current = { fn, options };
  });

  const mutate = useCallback(async (...args: TArgs): Promise<TResult | undefined> => {
    if (inFlight.current) return undefined;
    inFlight.current = true;
    setPending(true);
    const { fn: run, options: opts } = latest.current;

    try {
      const result = await run(...args);
      const message = typeof opts.successMessage === "function" ? opts.successMessage(result) : opts.successMessage;
      if (message) notifications.show({ color: "green", message });
      opts.onSuccess?.(result);
      return result;
    } catch (error) {
      const apiError = error instanceof ApiError ? error : new ApiError("Unexpected error.", 0);
      if (apiError.status === 422 && opts.onValidationError) {
        opts.onValidationError(apiError.formErrors);
        notifications.show({ color: "red", title: "Check the form", message: "Some fields need attention." });
      } else {
        // With no form to show 422s on (e.g. an Approve button), say what the API refused.
        const reason = apiError.status === 422 ? Object.values(apiError.formErrors).find(Boolean) : undefined;
        notifications.show({ color: "red", title: "Could not save", message: reason ?? apiError.message });
      }
      return undefined;
    } finally {
      inFlight.current = false;
      setPending(false);
    }
  }, []);

  return { mutate, pending };
}
