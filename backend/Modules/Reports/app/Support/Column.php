<?php

namespace Modules\Reports\Support;

/** One report column. `sensitive` columns (cost, profit) need reports.profit.view. */
final readonly class Column
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const MONEY = 'money';

    public const PERCENT = 'percent';

    public const DATE = 'date';

    public const DATETIME = 'datetime';

    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::TEXT,
        public bool $sensitive = false,
        /** Summed in the totals row. */
        public bool $total = false,
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label);
    }

    public static function number(string $key, string $label, bool $total = true): self
    {
        return new self($key, $label, self::NUMBER, total: $total);
    }

    public static function money(string $key, string $label, bool $sensitive = false, bool $total = true): self
    {
        return new self($key, $label, self::MONEY, $sensitive, $total);
    }

    public static function percent(string $key, string $label, bool $sensitive = false): self
    {
        return new self($key, $label, self::PERCENT, $sensitive);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, self::DATE);
    }

    public static function datetime(string $key, string $label): self
    {
        return new self($key, $label, self::DATETIME);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type, 'total' => $this->total];
    }
}
