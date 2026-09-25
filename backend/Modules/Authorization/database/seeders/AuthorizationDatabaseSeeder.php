<?php

namespace Modules\Authorization\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authorization\Models\Menu;
use Modules\Authorization\Support\Permissions as P;
use Modules\Authorization\Support\Roles;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AuthorizationDatabaseSeeder extends Seeder
{
    /**
     * Idempotent: syncs permissions, default roles and the navigation menu.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (P::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (Roles::defaults() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
        }

        $this->seedMenus();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * viewType values must exist in frontend/components/workspace/ViewRegistry.tsx.
     */
    private function seedMenus(): void
    {
        $tree = [
            ['key' => 'dashboard', 'title' => 'Dashboard', 'icon' => 'IconLayoutDashboard', 'view_type' => 'dashboard', 'path' => '/dashboard', 'permission' => P::DASHBOARD_VIEW],
            ['key' => 'pos', 'title' => 'POS Till', 'icon' => 'IconCashRegister', 'view_type' => 'posTill', 'path' => '/pos', 'permission' => P::SALES_SELL],
            ['key' => 'sales', 'title' => 'Sales & Shifts', 'icon' => 'IconReceipt', 'children' => [
                ['key' => 'sales.list', 'title' => 'Sales', 'view_type' => 'salesList', 'path' => '/sales', 'permission' => P::SALES_VIEW],
                ['key' => 'sales.shifts', 'title' => 'Shifts & cash-ups', 'view_type' => 'shiftsList', 'path' => '/sales/shifts', 'permission' => P::SHIFTS_CASHUP_APPROVE],
            ]],
            ['key' => 'catalogue', 'title' => 'Catalogue', 'icon' => 'IconBottle', 'children' => [
                ['key' => 'catalogue.products', 'title' => 'Products', 'view_type' => 'productsList', 'path' => '/catalogue/products', 'permission' => P::CATALOGUE_VIEW],
                ['key' => 'catalogue.prices', 'title' => 'Price changes', 'view_type' => 'priceChanges', 'path' => '/catalogue/prices', 'permission' => P::PRICES_MANAGE],
                ['key' => 'catalogue.setup', 'title' => 'Brands & categories', 'view_type' => 'catalogueSetup', 'path' => '/catalogue/setup', 'permission' => P::CATALOGUE_MANAGE],
            ]],
            ['key' => 'inventory', 'title' => 'Inventory', 'icon' => 'IconBuildingWarehouse', 'children' => [
                ['key' => 'inventory.stock', 'title' => 'Stock on hand', 'view_type' => 'stockOnHand', 'path' => '/inventory/stock', 'permission' => P::INVENTORY_VIEW],
                ['key' => 'inventory.adjustments', 'title' => 'Breakages & adjustments', 'view_type' => 'stockAdjustments', 'path' => '/inventory/adjustments', 'permission' => P::INVENTORY_VIEW],
                ['key' => 'inventory.transfers', 'title' => 'Transfers', 'view_type' => 'stockTransfers', 'path' => '/inventory/transfers', 'permission' => P::INVENTORY_VIEW],
                ['key' => 'inventory.counts', 'title' => 'Stock counts', 'view_type' => 'stockCounts', 'path' => '/inventory/counts', 'permission' => P::INVENTORY_VIEW],
                ['key' => 'inventory.open-bottles', 'title' => 'Open bottles (tots)', 'view_type' => 'openBottles', 'path' => '/inventory/open-bottles', 'permission' => P::INVENTORY_VIEW],
                ['key' => 'inventory.movements', 'title' => 'Stock ledger', 'view_type' => 'stockLedger', 'path' => '/inventory/ledger', 'permission' => P::INVENTORY_VIEW],
            ]],
            ['key' => 'purchasing', 'title' => 'Purchasing', 'icon' => 'IconTruckDelivery', 'children' => [
                ['key' => 'purchasing.orders', 'title' => 'Purchase orders', 'view_type' => 'purchaseOrders', 'path' => '/purchasing/orders', 'permission' => P::PURCHASING_VIEW],
                ['key' => 'purchasing.invoices', 'title' => 'Supplier invoices', 'view_type' => 'supplierInvoices', 'path' => '/purchasing/invoices', 'permission' => P::PURCHASING_VIEW],
                ['key' => 'purchasing.returns', 'title' => 'Returns to supplier', 'view_type' => 'supplierReturns', 'path' => '/purchasing/returns', 'permission' => P::PURCHASING_VIEW],
                ['key' => 'purchasing.suppliers', 'title' => 'Suppliers', 'view_type' => 'suppliersList', 'path' => '/purchasing/suppliers', 'permission' => P::PURCHASING_VIEW],
            ]],
            ['key' => 'payments', 'title' => 'Payments', 'icon' => 'IconDeviceMobile', 'children' => [
                ['key' => 'payments.mpesa', 'title' => 'M-PESA reconciliation', 'view_type' => 'mpesaReconciliation', 'path' => '/payments/mpesa', 'permission' => P::PAYMENTS_VIEW],
            ]],
            ['key' => 'customers', 'title' => 'Customers', 'icon' => 'IconUsers', 'view_type' => 'customersList', 'path' => '/customers', 'permission' => P::CUSTOMERS_VIEW],
            ['key' => 'compliance', 'title' => 'Compliance', 'icon' => 'IconShieldCheck', 'children' => [
                ['key' => 'compliance.etims', 'title' => 'eTIMS monitor', 'view_type' => 'etimsMonitor', 'path' => '/compliance/etims', 'permission' => P::COMPLIANCE_VIEW],
            ]],
            ['key' => 'reports', 'title' => 'Reports', 'icon' => 'IconReportAnalytics', 'view_type' => 'reportsHome', 'path' => '/reports', 'permission' => P::REPORTS_VIEW],
            ['key' => 'admin', 'title' => 'Administration', 'icon' => 'IconSettings', 'children' => [
                ['key' => 'admin.branches', 'title' => 'Branches', 'view_type' => 'branchesList', 'path' => '/admin/branches', 'permission' => P::ORGANISATION_MANAGE],
                ['key' => 'admin.users', 'title' => 'Users & roles', 'view_type' => 'usersList', 'path' => '/admin/users', 'permission' => P::USERS_MANAGE],
                ['key' => 'admin.audit', 'title' => 'Audit log', 'view_type' => 'auditLog', 'path' => '/admin/audit-log', 'permission' => P::AUDIT_VIEW],
            ]],
        ];

        foreach ($tree as $order => $item) {
            $this->upsertMenu($item, null, $order);
        }
    }

    /** @param array<string, mixed> $item */
    private function upsertMenu(array $item, ?int $parentId, int $order): void
    {
        $menu = Menu::query()->updateOrCreate(
            ['key' => $item['key']],
            [
                'parent_id' => $parentId,
                'title' => $item['title'],
                'icon' => $item['icon'] ?? null,
                'view_type' => $item['view_type'] ?? null,
                'path' => $item['path'] ?? null,
                'permission' => $item['permission'] ?? null,
                'sort_order' => $order,
            ],
        );

        foreach ($item['children'] ?? [] as $childOrder => $child) {
            $this->upsertMenu($child, $menu->id, $childOrder);
        }
    }
}
