<?php

namespace Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Authorization\Support\Permissions;
use Modules\Compliance\Models\Licence;
use Modules\Organisation\Services\BranchAccessService;
use OpenApi\Attributes as OA;

/**
 * Licence & permit register. A reminder: the business stays responsible for compliance.
 * A renewal is a new entry (the old one stays as history).
 */
class LicenceController extends Controller
{
    public function __construct(
        private readonly BranchAccessService $branches,
        private readonly AuditLogger $audit,
    ) {}

    #[OA\Get(path: '/api/v1/compliance/licences', summary: 'Licences and permits at my branches, soonest expiry first', tags: ['Compliance'], responses: [new OA\Response(response: 200, description: 'Licences')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::COMPLIANCE_VIEW), 403);
        $licences = Licence::query()->with('branch')->whereIn('branch_id', $this->branches->branchesFor($request->user())->modelKeys())
            ->orderBy('expires_on')->get();
        // The newest entry per branch and type is current; older ones were renewed.
        $current = $licences->groupBy(fn (Licence $l) => "{$l->branch_id}:{$l->type}:{$l->name}")->map(fn ($g) => $g->sortByDesc('expires_on')->first()->id)->flip();

        return $this->success('Licences.', [
            'types' => collect(Licence::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'items' => $licences->map(fn (Licence $l) => $this->licenceArray($l, isset($current[$l->id])))->values(),
        ]);
    }

    #[OA\Post(path: '/api/v1/compliance/licences', summary: 'Add a licence or permit (or its renewal)', tags: ['Compliance'], responses: [new OA\Response(response: 201, description: 'Licence')])]
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $licence = DB::transaction(function () use ($data, $request) {
            $licence = Licence::query()->create([...$this->attributes($data), 'created_by' => $request->user()->id]);
            $this->audit->log('compliance.licence.added', $licence, after: $this->attributes($data), userId: $request->user()->id, branchId: $licence->branch_id);

            return $licence;
        });

        return $this->success('Licence saved.', $this->licenceArray($licence->load('branch'), true), 201);
    }

    #[OA\Put(path: '/api/v1/compliance/licences/{licence}', summary: 'Correct a licence entry', tags: ['Compliance'], responses: [new OA\Response(response: 200, description: 'Licence')])]
    public function update(Request $request, Licence $licence): JsonResponse
    {
        $data = $this->validated($request);
        abort_unless($this->branches->canAccess($request->user(), $licence->branch_id), 403);

        DB::transaction(function () use ($data, $request, $licence) {
            $before = $licence->only(['branch_id', 'type', 'name', 'number', 'issuer', 'issued_on', 'expires_on', 'print_on_receipt', 'notes']);
            $licence->fill([...$this->attributes($data), 'updated_by' => $request->user()->id])->save();
            $this->audit->log('compliance.licence.updated', $licence, $before, $this->attributes($data), userId: $request->user()->id, branchId: $licence->branch_id);
        });

        return $this->success('Licence updated.', $this->licenceArray($licence->load('branch'), true));
    }

    #[OA\Delete(path: '/api/v1/compliance/licences/{licence}', summary: 'Remove an entry made by mistake (logged)', tags: ['Compliance'], responses: [new OA\Response(response: 200, description: 'Removed')])]
    public function destroy(Request $request, Licence $licence): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::COMPLIANCE_MANAGE) && $this->branches->canAccess($request->user(), $licence->branch_id), 403);
        DB::transaction(function () use ($request, $licence) {
            $this->audit->log('compliance.licence.removed', $licence, $licence->only(['type', 'name', 'number', 'expires_on']), userId: $request->user()->id, branchId: $licence->branch_id);
            $licence->delete();
        });

        return $this->success('Licence removed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        abort_unless($request->user()->can(Permissions::COMPLIANCE_MANAGE), 403);
        $data = $request->validate([
            'branchId' => ['required', 'integer', Rule::exists('branches', 'id')],
            'type' => ['required', Rule::in(array_keys(Licence::TYPES))],
            'name' => ['required', 'string', 'max:120'],
            'number' => ['required', 'string', 'max:60'],
            'issuer' => ['nullable', 'string', 'max:120'],
            'issuedOn' => ['nullable', 'date'],
            'expiresOn' => ['required', 'date', 'after_or_equal:issuedOn'],
            'printOnReceipt' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_unless($this->branches->canAccess($request->user(), (int) $data['branchId']), 403);

        return $data;
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'branch_id' => (int) $data['branchId'],
            'type' => $data['type'],
            'name' => trim($data['name']),
            'number' => trim($data['number']),
            'issuer' => $data['issuer'] ?? null,
            'issued_on' => $data['issuedOn'] ?? null,
            'expires_on' => $data['expiresOn'],
            'print_on_receipt' => (bool) ($data['printOnReceipt'] ?? false),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function licenceArray(Licence $l, bool $current): array
    {
        return [
            'id' => $l->id,
            'branch' => ['id' => $l->branch->id, 'code' => $l->branch->code, 'name' => $l->branch->name],
            'type' => $l->type,
            'typeLabel' => Licence::TYPES[$l->type] ?? $l->type,
            'name' => $l->name,
            'number' => $l->number,
            'issuer' => $l->issuer,
            'issuedOn' => $l->issued_on?->toDateString(),
            'expiresOn' => $l->expires_on->toDateString(),
            'daysLeft' => $l->daysLeft(),
            'printOnReceipt' => $l->print_on_receipt,
            'notes' => $l->notes,
            // false = renewed by a newer entry
            'isCurrent' => $current,
        ];
    }
}
