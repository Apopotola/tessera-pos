/**
 * Shared API contract with the Laravel `App\Traits\ApiResponse` envelope.
 */
export interface ApiEnvelope<T> {
  success: true;
  message: string;
  statusCode: number;
  data: T;
}

export interface ApiErrorEnvelope {
  success: false;
  message: string;
  statusCode: number;
  /** Field → messages for 422 responses; otherwise a string or empty. */
  errors: Record<string, string[]> | string | [];
}

export interface PaginationMeta {
  currentPage: number;
  perPage: number;
  total: number;
  lastPage: number;
}

export interface Paginated<T> {
  items: T[];
  meta: PaginationMeta;
}
