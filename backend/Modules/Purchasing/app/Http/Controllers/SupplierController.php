<?php

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Support\Permissions;
use Modules\Purchasing\Http\Resources\PurchasingResources;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Services\SupplierService;
use OpenApi\Attributes as OA;

class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $suppliers) {}

    #[OA\Get(path: '/api/v1/purchasing/suppliers', summary: 'Suppliers A–Z', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Suppliers')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PURCHASING_VIEW), 403);

        return $this->success('Suppliers.', Supplier::query()->orderBy('name')->get()->map(PurchasingResources::supplier(...)));
    }

    #[OA\Post(path: '/api/v1/purchasing/suppliers', summary: 'Register a supplier', tags: ['Purchasing'], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PURCHASING_MANAGE), 403);
        $supplier = $this->suppliers->create($request->validate($this->rules()), $request->user());

        return $this->success('Supplier added.', PurchasingResources::supplier($supplier), 201);
    }

    #[OA\Put(path: '/api/v1/purchasing/suppliers/{supplier}', summary: 'Update a supplier', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Updated')])]
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PURCHASING_MANAGE), 403);
        $supplier = $this->suppliers->update($supplier, $request->validate($this->rules($supplier->id) + ['isActive' => ['sometimes', 'boolean']]), $request->user());

        return $this->success('Supplier updated.', PurchasingResources::supplier($supplier));
    }

    #[OA\Get(path: '/api/v1/purchasing/suppliers/{supplier}/items', summary: 'Items bought from this supplier with last cost', tags: ['Purchasing'], responses: [new OA\Response(response: 200, description: 'Items')])]
    public function items(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PURCHASING_VIEW), 403);

        $items = DB::table('supplier_items')->where('supplier_id', $supplier->id)
            ->get()
            ->map(fn ($row) => ['variantId' => $row->variant_id, 'supplierCode' => $row->supplier_code, 'lastCostCents' => $row->last_cost_cents, 'lastPurchasedAt' => $row->last_purchased_at]);

        return $this->success('Supplier items.', $items);
    }

    /** @return array<string, mixed> */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150', function (string $attr, mixed $value, Closure $fail) use ($ignoreId) {
                $taken = Supplier::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
                if ($taken) {
                    $fail('A supplier with this name already exists.');
                }
            }],
            'kraPin' => ['nullable', 'string', 'regex:/^[A-Za-z]\d{9}[A-Za-z]$/'],
            'contactPerson' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'paymentTermsDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'paymentDetails' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
