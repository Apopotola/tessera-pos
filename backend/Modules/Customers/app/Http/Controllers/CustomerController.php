<?php

namespace Modules\Customers\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Support\Permissions;
use Modules\Customers\Http\Requests\CustomerRequest;
use Modules\Customers\Http\Resources\CustomerResource;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerAccountService;
use Modules\Customers\Services\CustomerService;
use Modules\Inventory\Services\StockQueryService;
use Modules\Sales\Http\Controllers\TillSaleController;
use Modules\Sales\Http\Resources\SaleResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Back-office customer records with purchase history and data-protection actions. */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly StockQueryService $stock,
        private readonly CustomerAccountService $accounts,
    ) {}

    #[OA\Get(path: '/api/v1/customers', summary: 'Registered customers (search by name or KRA PIN)', tags: ['Customers'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::CUSTOMERS_VIEW), 403);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'in:all,wholesale,business'],
            'active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->withStats(Customer::query())
            ->when($filters['search'] ?? null, fn ($q, $s) => $this->search($q, $s))
            ->when(($filters['type'] ?? 'all') === 'wholesale', fn ($q) => $q->where('is_wholesale', true))
            ->when(($filters['type'] ?? 'all') === 'business', fn ($q) => $q->where('is_wholesale', false))
            ->when(isset($filters['active']), fn ($q) => $q->where('is_active', (bool) $filters['active']))
            ->orderBy('name')
            ->paginate(25);

        return $this->success('Customers.', $this->paginated($page, CustomerResource::class));
    }

    #[OA\Get(path: '/api/v1/customers/{customer}', summary: 'One customer (viewing contact details is logged)', tags: ['Customers'], responses: [new OA\Response(response: 200, description: 'Customer')])]
    public function show(Request $request, int $customer): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::CUSTOMERS_VIEW), 403);
        $record = $this->withStats(Customer::query())->findOrFail($customer);

        if ($request->user()->can(Permissions::CUSTOMERS_MANAGE)) {
            $this->customers->recordView($record, $request->user());
        }

        return $this->success('Customer.', new CustomerResource($record));
    }

    #[OA\Post(path: '/api/v1/customers', summary: 'Register a wholesale / B2B customer', tags: ['Customers'], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function store(CustomerRequest $request): JsonResponse
    {
        return $this->success('Customer registered.', new CustomerResource($this->customers->create($request->validated(), $request->user())), 201);
    }

    #[OA\Put(path: '/api/v1/customers/{customer}', summary: 'Update a customer', tags: ['Customers'], responses: [new OA\Response(response: 200, description: 'Updated')])]
    public function update(CustomerRequest $request, Customer $customer): JsonResponse
    {
        return $this->success('Customer updated.', new CustomerResource($this->customers->update($customer, $request->validated(), $request->user())));
    }

    #[OA\Get(path: '/api/v1/customers/{customer}/sales', summary: 'Purchase history (branches the user can see)', tags: ['Customers'], responses: [new OA\Response(response: 200, description: 'Paginated sales')])]
    public function sales(Request $request, Customer $customer): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::CUSTOMERS_VIEW) && $request->user()->can(Permissions::SALES_VIEW), 403);

        $page = $customer->sales()
            ->with(TillSaleController::RELATIONS)
            ->whereIn('branch_id', $this->stock->branchIds($request->user(), null))
            ->orderByDesc('completed_at')
            ->paginate(20);

        return $this->success('Purchase history.', $this->paginated($page, SaleResource::class));
    }

    #[OA\Get(path: '/api/v1/customers/{customer}/export', summary: 'Everything held about this customer (Owner/Admin; logged)', tags: ['Customers'], responses: [new OA\Response(response: 200, description: 'JSON file')])]
    public function export(Request $request, Customer $customer): StreamedResponse
    {
        $data = $this->customers->export($customer, $request->user());

        return response()->streamDownload(
            fn () => print (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            "customer-{$customer->id}-data.json",
            ['Content-Type' => 'application/json'],
        );
    }

    #[OA\Post(path: '/api/v1/customers/{customer}/anonymise', summary: 'Remove personal data; sales are kept (Owner/Admin; logged)', tags: ['Customers'], responses: [new OA\Response(response: 200, description: 'Anonymised')])]
    public function anonymise(Request $request, Customer $customer): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->success('Personal data removed. Sales records are kept.', new CustomerResource($this->customers->anonymise($customer, $request->user(), $reason)));
    }

    #[OA\Get(path: '/api/v1/customers/till/search', summary: 'Till: find a customer by name or KRA PIN (no contact details)', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'Customers')])]
    public function tillSearch(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SALES_SELL), 403);
        $term = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']])['search'];

        $customers = $this->search(Customer::query()->where('is_active', true), $term)
            ->orderBy('name')
            ->limit(15)
            ->get();
        $balances = $this->accounts->balances($customers->modelKeys());
        $customers = $customers->map(fn (Customer $c) => [
            'id' => $c->id, 'name' => $c->name, 'kraPin' => $c->kra_pin, 'isWholesale' => $c->is_wholesale,
            // Credit account: how much more can go on account before a manager must approve.
            'creditAvailableCents' => $c->credit_limit_cents === null ? null : max(0, $c->credit_limit_cents - ($balances[$c->id] ?? 0)),
        ]);

        return $this->success('Customers.', $customers);
    }

    /** @param Builder<Customer> $query @return Builder<Customer> */
    private function search(Builder $query, string $term): Builder
    {
        $like = '%'.mb_strtolower(trim($term)).'%';

        return $query->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(kra_pin) like ?', [$like]));
    }

    /** @param Builder<Customer> $query @return Builder<Customer> */
    private function withStats(Builder $query): Builder
    {
        return $query
            ->withCount('sales')
            ->withSum('sales as sales_total_cents', 'total_cents')
            ->withMax('sales as last_purchase_at', 'completed_at');
    }
}
