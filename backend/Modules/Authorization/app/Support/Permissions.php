<?php

namespace Modules\Authorization\Support;

/**
 * Single source of truth for permission names.
 *
 * Maker–checker pairs use `.request` / `.approve` suffixes so the same user
 * never needs both. Keep names aligned with frontend/types/auth.ts (Permission).
 */
final class Permissions
{
    // Dashboard
    public const DASHBOARD_VIEW = 'dashboard.view';

    // POS / sales
    public const SALES_SELL = 'sales.sell';

    public const SALES_DISCOUNT_WITHIN_LIMIT = 'sales.discount.within-limit';

    public const SALES_OVERRIDE_REQUEST = 'sales.override.request';

    public const SALES_OVERRIDE_APPROVE = 'sales.override.approve';

    public const SALES_VOID_REQUEST = 'sales.void.request';

    public const SALES_VOID_APPROVE = 'sales.void.approve';

    public const SALES_REFUND_REQUEST = 'sales.refund.request';

    public const SALES_REFUND_APPROVE = 'sales.refund.approve';

    public const SALES_VIEW = 'sales.view';

    // Shifts / cash-up
    public const SHIFTS_CASHUP = 'shifts.cashup';

    public const SHIFTS_CASHUP_APPROVE = 'shifts.cashup.approve';

    // Catalogue
    public const CATALOGUE_VIEW = 'catalogue.view';

    public const CATALOGUE_MANAGE = 'catalogue.manage';

    public const PRICES_MANAGE = 'catalogue.prices.manage';

    public const PRICES_APPROVE = 'catalogue.prices.approve';

    // Inventory
    public const INVENTORY_VIEW = 'inventory.view';

    public const INVENTORY_RECEIVE = 'inventory.receive';

    public const INVENTORY_TRANSFER = 'inventory.transfer';

    public const INVENTORY_TRANSFER_APPROVE = 'inventory.transfer.approve';

    public const INVENTORY_ADJUST = 'inventory.adjust';

    public const INVENTORY_BREAKAGE_REPORT = 'inventory.breakage.report';

    public const INVENTORY_ADJUST_APPROVE = 'inventory.adjust.approve';

    public const INVENTORY_COUNT = 'inventory.count';

    public const INVENTORY_COUNT_APPROVE = 'inventory.count.approve';

    // Purchasing
    public const PURCHASING_VIEW = 'purchasing.view';

    public const PURCHASING_MANAGE = 'purchasing.manage';

    public const PURCHASING_APPROVE = 'purchasing.approve';

    // Customers
    /** M-PESA reconciliation screen. */
    public const PAYMENTS_VIEW = 'payments.view';

    /** Match a customer-initiated M-PESA payment to a sale after the fact. */
    public const PAYMENTS_RECONCILE = 'payments.reconcile';

    public const CUSTOMERS_VIEW = 'customers.view';

    public const CUSTOMERS_MANAGE = 'customers.manage';

    /** Export or anonymise one customer's personal data (data subject requests). */
    public const CUSTOMERS_PRIVACY = 'customers.privacy';

    // Reports
    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_PROFIT_VIEW = 'reports.profit.view';

    public const REPORTS_FINANCIAL_VIEW = 'reports.financial.view';

    /** Download reports as CSV (every export is logged). */
    public const REPORTS_EXPORT = 'reports.export';

    // Compliance (eTIMS)
    public const COMPLIANCE_VIEW = 'compliance.view';

    public const COMPLIANCE_MANAGE = 'compliance.manage';

    // Administration
    public const ORGANISATION_MANAGE = 'organisation.manage';

    public const ORGANISATION_ALL_BRANCHES = 'organisation.all-branches';

    public const USERS_MANAGE = 'users.manage';

    public const AUDIT_VIEW = 'audit.view';

    /** Settings level T: plan, modules, KRA PIN, eTIMS, locked items (Tessera support only). */
    public const SETTINGS_PLATFORM = 'settings.platform';

    /** Settings level O: branding, business details, receipts, payments, staff rules. */
    public const SETTINGS_BUSINESS = 'settings.business';

    /** Settings level B: own branch only (tills, printers, float, layout). */
    public const SETTINGS_BRANCH = 'settings.branch';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
