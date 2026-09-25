<?php

namespace Modules\Authorization\Support;

use Modules\Authorization\Support\Permissions as P;

/**
 * Default roles from the approved RBAC matrix (requirements analysis, "RBAC & Segregation of Duties").
 * The owner may clone and adjust roles later; segregation rules are enforced in services, not here.
 */
final class Roles
{
    public const OWNER = 'Owner';

    public const ADMIN = 'Admin';

    public const BRANCH_MANAGER = 'Branch Manager';

    public const CASHIER = 'Cashier';

    public const STOREKEEPER = 'Storekeeper';

    public const ACCOUNTANT = 'Accountant';

    /** @return array<string, list<string>> role => permissions */
    public static function defaults(): array
    {
        return [
            self::OWNER => P::all(),

            self::ADMIN => array_values(array_diff(P::all(), [P::PRICES_APPROVE])),

            self::BRANCH_MANAGER => [
                P::DASHBOARD_VIEW, P::SALES_SELL, P::SALES_VIEW, P::SALES_DISCOUNT_WITHIN_LIMIT,
                P::SALES_OVERRIDE_APPROVE, P::SALES_VOID_APPROVE, P::SALES_REFUND_APPROVE,
                P::SHIFTS_CASHUP_APPROVE, P::CATALOGUE_VIEW,
                P::INVENTORY_VIEW, P::INVENTORY_RECEIVE, P::INVENTORY_TRANSFER_APPROVE,
                P::INVENTORY_ADJUST_APPROVE, P::INVENTORY_COUNT_APPROVE,
                P::PURCHASING_VIEW, P::CUSTOMERS_VIEW, P::CUSTOMERS_MANAGE,
                P::REPORTS_VIEW, P::REPORTS_PROFIT_VIEW, P::REPORTS_FINANCIAL_VIEW,
                P::COMPLIANCE_VIEW, P::AUDIT_VIEW,
            ],

            self::CASHIER => [
                P::SALES_SELL, P::SALES_DISCOUNT_WITHIN_LIMIT, P::SALES_OVERRIDE_REQUEST,
                P::SALES_VOID_REQUEST, P::SALES_REFUND_REQUEST, P::SHIFTS_CASHUP,
                P::INVENTORY_BREAKAGE_REPORT, P::CUSTOMERS_VIEW,
            ],

            self::STOREKEEPER => [
                P::CATALOGUE_VIEW, P::INVENTORY_VIEW, P::INVENTORY_RECEIVE, P::INVENTORY_TRANSFER,
                P::INVENTORY_ADJUST, P::INVENTORY_BREAKAGE_REPORT, P::INVENTORY_COUNT,
                P::PURCHASING_VIEW,
            ],

            self::ACCOUNTANT => [
                P::DASHBOARD_VIEW, P::SALES_VIEW, P::CATALOGUE_VIEW, P::INVENTORY_VIEW,
                P::PURCHASING_VIEW, P::PURCHASING_MANAGE, P::CUSTOMERS_VIEW,
                P::REPORTS_VIEW, P::REPORTS_PROFIT_VIEW, P::REPORTS_FINANCIAL_VIEW,
                P::COMPLIANCE_VIEW, P::AUDIT_VIEW, P::ORGANISATION_ALL_BRANCHES,
            ],
        ];
    }
}
