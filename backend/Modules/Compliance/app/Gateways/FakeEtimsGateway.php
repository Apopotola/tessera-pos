<?php

namespace Modules\Compliance\Gateways;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Compliance\Contracts\EtimsGateway;
use Modules\Compliance\Support\EtimsResult;

/**
 * Mock KRA eTIMS for demos. Behaves like the real service in the ways that matter to the
 * POS: it signs valid invoices, refuses items without a KRA item class code, is idempotent
 * on the invoice number, and can be switched "offline" to show queuing and retries.
 * Nothing is sent to KRA; receipts carry a MOCK marker.
 */
class FakeEtimsGateway implements EtimsGateway
{
    public function __construct(
        private readonly string $scuId,
        private readonly bool $offline,
    ) {}

    public function submit(array $payload): EtimsResult
    {
        if ($this->offline) {
            return EtimsResult::retry('KRA eTIMS could not be reached (mock offline mode).');
        }

        $missing = collect($payload['itemList'] ?? [])->filter(fn ($item) => blank($item['itemClsCd'] ?? null))->pluck('itemNm');
        if ($missing->isNotEmpty()) {
            return EtimsResult::rejected('Item class code missing for: '.$missing->implode(', ').'. Set it on the product, then retry.', ['resultCd' => '910', 'resultMsg' => 'Invalid item class code']);
        }
        if (($payload['rcptTyCd'] ?? null) === 'R' && blank($payload['orgInvcNo'] ?? null)) {
            return EtimsResult::rejected('A credit note must reference a signed original invoice.', ['resultCd' => '921']);
        }

        // Idempotent on our invoice number, as KRA is.
        if ($existing = $this->find((string) $payload['invcNo'])) {
            return $existing;
        }

        $signature = Str::upper(Str::random(16));
        $result = new EtimsResult(
            EtimsResult::SIGNED,
            invoiceNumber: (string) random_int(100000, 999999),
            signature: $signature,
            internalData: Str::upper(Str::random(26)),
            qrPayload: 'https://etims-sbx.kra.go.ke/common/link/etims/receipt/indexEtimsReceiptData?Data='.($payload['tin'] ?? '').($payload['bhfId'] ?? '').$signature.'&mock=1',
            scuId: $this->scuId,
            response: ['resultCd' => '000', 'resultMsg' => 'It is succeeded (mock)', 'mock' => true],
        );
        Cache::put($this->key((string) $payload['invcNo']), (array) $result, now()->addDays(7));

        return $result;
    }

    public function find(string $invoiceNumber): ?EtimsResult
    {
        $stored = Cache::get($this->key($invoiceNumber));

        return $stored ? new EtimsResult(...$stored) : null;
    }

    private function key(string $invoiceNumber): string
    {
        return "etims.mock.{$invoiceNumber}";
    }
}
