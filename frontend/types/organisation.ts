/** Mirrors Modules\Organisation\Http\Resources\BranchResource. */
export interface Branch {
  id: number;
  code: string;
  name: string;
  phone: string | null;
  address: string | null;
  isWarehouse: boolean;
  isActive: boolean;
}
