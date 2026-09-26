<?php

namespace Modules\Settings\Support;

/**
 * Every setting in one place (docs/Tessera_POS_Admin_Settings_Spec.docx): key, section, type,
 * allowed values, default, who may change it and at which scopes. The Settings screens and the
 * API validation are generated from this file; nothing else hard-codes a setting.
 *
 * Levels: T = Tessera admin, O = owner / business admin, B = branch manager (own branch).
 * A B setting can also be changed by O and T; an O setting also by T.
 * Scopes (most specific wins): till → branch → business → default below.
 * `available: false` = stored and shown, but the feature it drives is not built yet (`note` says so).
 */
final class SettingsRegistry
{
    public const SECTIONS = [
        'business' => ['title' => 'Business', 'description' => 'Details that feed every receipt and eTIMS invoice, branches and the industry preset.'],
        'branding' => ['title' => 'Branding', 'description' => 'Make the POS look like the client\'s own system. Colours are checked so text stays readable.'],
        'receipts' => ['title' => 'Receipts', 'description' => 'What prints on receipts and invoices, and when.'],
        'sales' => ['title' => 'Sales screen', 'description' => 'How the till looks and works at the counter.'],
        'stock' => ['title' => 'Products and stock', 'description' => 'Stock rules and price lists.'],
        'payments' => ['title' => 'Payments', 'description' => 'Payment methods, M-PESA and cash rounding.'],
        'staff' => ['title' => 'Staff and roles', 'description' => 'Discount limits, approvals, sign-in rules and cash-up. Shipped strict; loosen deliberately.'],
        'notifications' => ['title' => 'Notifications', 'description' => 'Who hears about what, and which tiles each role sees.'],
        'integrations' => ['title' => 'Integrations', 'description' => 'eTIMS, accounting export and till hardware.'],
    ];

    /** Tested brand colours (white text passes WCAG AA on each). */
    public const PRIMARY_COLOURS = [
        '#5B3FA3' => 'Jacaranda', '#1F6FEB' => 'Lake Blue', '#0F7B6C' => 'Savanna Green', '#B42318' => 'Maasai Red',
        '#9A3412' => 'Terracotta', '#1C1D2E' => 'Night', '#6D28D9' => 'Violet', '#0E7490' => 'Coast Teal',
    ];

    public const ACCENT_COLOURS = [
        '#F2A93B' => 'Tile Amber', '#F59E0B' => 'Sunrise', '#10B981' => 'Mint', '#38BDF8' => 'Sky', '#F472B6' => 'Rose', '#A3E635' => 'Lime',
    ];

    public const ROLES_FOR_LIMITS = ['Cashier', 'Branch Manager', 'Storekeeper', 'Accountant', 'Admin', 'Owner'];

    public const DASHBOARD_TILES = [
        'sales_today' => 'Sales today', 'takings' => 'Cash and M-PESA', 'gross_profit' => 'Gross profit',
        'shifts' => 'Open shifts', 'tills' => 'Tills connected', 'catalogue' => 'Items on sale',
        'low_stock' => 'Low stock', 'stock_value' => 'Stock value', 'losses' => 'Breakage and losses', 'price_changes' => 'Price changes waiting',
        'exceptions' => 'Discounts, voids and refunds', 'cash_variance' => 'Cash variance by cashier', 'movers' => 'Best sellers and slow movers',
        'etims' => 'eTIMS status', 'branches' => 'Branch comparison', 'receivables' => 'Owed by customers', 'payables' => 'Owed to suppliers',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            // ------------------------------------------------------------------ Business
            'business.preset' => self::f('business', 'Industry preset', 'select', 'wines_spirits', 'O',
                options: array_map(fn ($p) => $p['title'], self::presets()),
                help: 'Sets many defaults at once. Change it with "Apply preset" so your own changes are kept.'),
            'business.legal_name' => self::f('business', 'Legal business name', 'text', null, 'O', model: ['business', 'name'], max: 120),
            'business.address' => self::f('business', 'Address', 'text', null, 'O', model: ['business', 'address'], max: 190),
            'business.phone' => self::f('business', 'Phone', 'text', null, 'O', model: ['business', 'phone'], max: 30),
            'business.email' => self::f('business', 'Email', 'text', null, 'O', model: ['business', 'email'], max: 150),
            'business.kra_pin' => self::f('business', 'KRA PIN', 'text', null, 'T', model: ['business', 'kra_pin'], pattern: '/^[A-Z]\d{9}[A-Z]$/',
                help: 'Printed on every eTIMS invoice. Changed only by Tessera support after verification.'),
            'business.vat_registered' => self::f('business', 'VAT registered', 'boolean', true, 'T'),
            'business.financial_year_start' => self::f('business', 'Financial year starts', 'select', 1, 'O', options: self::months()),
            'business.currency' => self::f('business', 'Currency', 'select', 'KES', 'T', options: ['KES' => 'Kenya shilling (KSh)'],
                help: 'Other currencies arrive with Uganda and Rwanda.'),
            'branch.trading_hours' => self::f('business', 'Trading hours', 'text', null, 'O', scopes: ['branch'], max: 80, help: 'e.g. Mon–Sat 10:00–23:00'),
            'branch.products_sold' => self::f('business', 'Categories sold at this branch', 'categories', [], 'O', scopes: ['business', 'branch'],
                help: 'Empty = all categories. The till only shows items from these categories.'),

            // ------------------------------------------------------------------ Branding
            'branding.display_name' => self::f('branding', 'Business display name', 'text', null, 'O', max: 40, help: 'Shown in the sidebar, login and till. Empty = legal name.'),
            'branding.app_logo' => self::f('branding', 'App logo', 'image', null, 'O', help: 'PNG or SVG, up to 1 MB. Sidebar, login and till.'),
            'branding.receipt_logo' => self::f('branding', 'Receipt logo', 'image', null, 'O', help: 'Black-and-white PNG, up to 300 px wide.'),
            'branding.favicon' => self::f('branding', 'App icon', 'image', null, 'O', help: 'Square PNG, 512 px.'),
            'branding.primary_color' => self::f('branding', 'Primary colour', 'color', '#5B3FA3', 'O', options: self::PRIMARY_COLOURS,
                help: 'Buttons, highlights and the active menu item. A custom colour must keep white text readable.'),
            'branding.accent_color' => self::f('branding', 'Accent colour', 'select', '#F2A93B', 'O', options: self::ACCENT_COLOURS),
            'branding.theme' => self::f('branding', 'Theme', 'select', 'light', 'O', scopes: ['business', 'till'], branchLevelScopes: ['till'],
                options: ['light' => 'Light', 'dark' => 'Dark', 'system' => 'Follow device'],
                note: 'The till is always dark. A dark back office arrives in a later update.'),
            'branding.login_style' => self::f('branding', 'Login page style', 'select', 'split', 'O', options: ['split' => 'Split screen', 'centered' => 'Centred card'],
                help: 'Tills always use the PIN screen.'),
            'branding.login_background' => self::f('branding', 'Login background', 'select', 'bottles', 'O',
                options: ['bottles' => 'Bottle shelf', 'mosaic' => 'Mosaic pattern', 'image' => 'Uploaded image']),
            'branding.login_background_image' => self::f('branding', 'Login background image', 'image', null, 'O'),
            'branding.welcome_text' => self::f('branding', 'Welcome text on login', 'text', null, 'O', max: 80, help: 'Empty = "Sign in to [business name]".'),
            'branding.language' => self::f('branding', 'Language', 'select', 'en', 'O', options: ['en' => 'English', 'sw' => 'Swahili'],
                available: false, note: 'Swahili screens arrive in a later update.'),
            'branding.locale_format' => self::f('branding', 'Date and number format', 'select', 'ke', 'O', options: ['ke' => 'Kenyan — DD/MM/YYYY, 1,234.00']),
            'branding.custom_domain' => self::f('branding', 'Custom web address', 'text', null, 'T', max: 120, available: false,
                note: 'Paid add-on, set up by Tessera.'),

            // ------------------------------------------------------------------ Receipts
            'receipts.header_lines' => self::f('receipts', 'Receipt header', 'lines', null, 'O', max: 3, help: 'Up to 3 lines under the business name.'),
            'receipts.footer_lines' => self::f('receipts', 'Receipt footer', 'lines', 'Thank you for shopping with us.', 'O', max: 3),
            'receipts.show' => self::f('receipts', 'Show on receipt', 'multiselect', ['logo', 'cashier'], 'O',
                options: ['logo' => 'Logo', 'cashier' => 'Cashier name', 'customer' => 'Customer name', 'return_policy' => 'Return policy', 'loyalty' => 'Loyalty points'],
                help: 'Loyalty points arrive with loyalty.'),
            'receipts.paper_size' => self::f('receipts', 'Paper size', 'select', '80mm', 'B', scopes: ['business', 'branch'],
                options: ['58mm' => '58 mm thermal', '80mm' => '80 mm thermal', 'a4' => 'A4 invoice']),
            'receipts.invoice_prefix' => self::f('receipts', 'Invoice number prefix', 'text', 'INV-', 'O', max: 8, pattern: '/^[A-Z0-9]{0,6}-?$/',
                help: 'e.g. KWS-. Locked once the first sale is made, so invoice numbers stay in sequence.'),
            'receipts.print_behaviour' => self::f('receipts', 'Print behaviour', 'select', 'ask', 'B', scopes: ['business', 'branch'],
                options: ['always' => 'Always print', 'ask' => 'Ask each time', 'digital' => 'SMS or email only'], help: 'SMS and email receipts arrive with notifications.'),
            'receipts.document_template' => self::f('receipts', 'Quotation and delivery note layout', 'select', 'standard', 'O',
                options: ['standard' => 'Standard', 'compact' => 'Compact', 'detailed' => 'Detailed'], available: false, note: 'Quotations and delivery notes arrive later.'),

            // ------------------------------------------------------------------ Sales screen
            'sales.layout' => self::f('sales', 'Layout', 'select', 'tiles', 'B', scopes: ['business', 'branch', 'till'],
                options: ['tiles' => 'Product tiles', 'list' => 'List', 'barcode' => 'Barcode first']),
            'sales.favourites_mode' => self::f('sales', 'Favourite products on the first screen', 'select', 'top', 'B', scopes: ['business', 'branch'],
                options: ['top' => 'Top 12 sellers (updated weekly)', 'pinned' => 'Pick them', 'none' => 'None']),
            'sales.favourite_items' => self::f('sales', 'Favourite products', 'items', [], 'B', scopes: ['business', 'branch'], max: 24, help: 'Used when "Pick them" is chosen.'),
            'sales.category_order' => self::f('sales', 'Category order', 'categories', [], 'O', available: false, note: 'Browsing by category on the till arrives later.'),
            'sales.quick_buttons' => self::f('sales', 'Quick buttons', 'multiselect', ['hold', 'customer', 'returns'], 'O',
                options: ['hold' => 'Hold sale', 'discount' => 'Discount', 'customer' => 'Customer', 'returns' => 'Returns', 'price_check' => 'Price check', 'open_drawer' => 'Open drawer'],
                help: 'Open drawer needs a connected cash drawer.'),
            'sales.payment_methods_order' => self::f('sales', 'Payment buttons and order', 'order', ['mpesa', 'cash', 'card'], 'O',
                options: ['mpesa' => 'M-PESA', 'cash' => 'Cash', 'card' => 'Card']),
            'sales.touch_mode' => self::f('sales', 'Touch mode', 'select', 'large', 'B', scopes: ['business', 'branch', 'till'],
                options: ['standard' => 'Standard buttons', 'large' => 'Large buttons']),
            'features.sell_by_tot' => self::f('sales', 'Sell by tot', 'boolean', true, 'O', group: 'Industry features'),
            'sales.age_check_prompt' => self::f('sales', 'Age-check prompt', 'boolean', true, 'O', group: 'Industry features',
                help: 'The cashier confirms the customer is 18 or over before payment.'),
            'features.crate_deposits' => self::f('sales', 'Crate and bottle deposits', 'boolean', false, 'O', group: 'Industry features', available: false, note: 'Arrives later.'),
            'features.licensed_hours_lock' => self::f('sales', 'Licensed-hours lock', 'boolean', false, 'O', group: 'Industry features', available: false, note: 'Arrives later.'),
            'features.expiry_alerts' => self::f('sales', 'Expiry alerts', 'boolean', false, 'O', group: 'Industry features', available: false, note: 'Arrives later.'),
            'features.prescription_notes' => self::f('sales', 'Prescription notes', 'boolean', false, 'O', group: 'Industry features', available: false, note: 'Arrives later.'),
            'features.weighing_scale' => self::f('sales', 'Weighing-scale items', 'boolean', false, 'O', group: 'Industry features', available: false, note: 'Arrives later.'),
            'features.quotations' => self::f('sales', 'Quotations', 'boolean', false, 'O', group: 'Industry features', available: false, note: 'Arrives later.'),
            'features.tables' => self::f('sales', 'Tables, kitchen tickets, tips and modifiers', 'boolean', false, 'O', group: 'Industry features', available: false, note: 'Arrives later.'),

            // ------------------------------------------------------------------ Products and stock
            'stock.units' => self::f('stock', 'Units of measure', 'multiselect', ['piece', 'pack', 'crate', 'tot'], 'O',
                options: ['piece' => 'Piece', 'pack' => 'Pack', 'crate' => 'Crate', 'carton' => 'Carton', 'kg' => 'kg', 'metre' => 'Metre', 'litre' => 'Litre', 'tot' => 'Tot'],
                available: false, note: 'Packs and tots work today; other units with conversions arrive later.'),
            'stock.low_stock_default' => self::f('stock', 'Default low-stock level', 'number', 5, 'O', scopes: ['business', 'branch'], branchLevelScopes: ['branch'], min: 0, max: 100000,
                help: 'Used for items without their own reorder level.'),
            'stock.batch_tracking_default' => self::f('stock', 'Batch and expiry tracking for new items', 'boolean', false, 'O'),
            'stock.below_zero' => self::f('stock', 'Selling below zero stock', 'select', 'approval', 'O',
                options: ['allow' => 'Allow', 'approval' => 'Manager approval', 'block' => 'Block'],
                help: 'Offline sales are always recorded; the stock count catches the gap.'),
            'stock.price_lists' => self::f('stock', 'Price lists', 'multiselect', ['retail', 'wholesale'], 'O',
                options: ['retail' => 'Retail', 'wholesale' => 'Wholesale', 'customer_group' => 'Per customer group'], help: 'Customer-group prices arrive later.'),
            'stock.branch_price_override' => self::f('stock', 'Branch price override', 'boolean', false, 'O', help: 'Allow prices that apply to one branch only.'),

            // ------------------------------------------------------------------ Payments
            'payments.accepted_methods' => self::f('payments', 'Accepted methods', 'multiselect', ['cash', 'mpesa', 'card', 'split'], 'O',
                options: ['cash' => 'Cash', 'mpesa' => 'M-PESA', 'card' => 'Card', 'split' => 'Split payment', 'bank' => 'Bank transfer'],
                help: 'Bank transfer at the till arrives later. Sales on account follow "Customer credit" below.'),
            'payments.stk_push' => self::f('payments', 'M-PESA STK Push (payment request to the phone)', 'boolean', true, 'O'),
            'payments.mpesa_type' => self::f('payments', 'M-PESA account type', 'select', 'till', 'O', group: 'M-PESA account', options: ['till' => 'Buy Goods (Till)', 'paybill' => 'Paybill']),
            'payments.mpesa_number' => self::f('payments', 'Till or Paybill number', 'text', null, 'O', group: 'M-PESA account', pattern: '/^\d{5,7}$/'),
            'payments.mpesa_shortcode' => self::f('payments', 'Daraja shortcode', 'text', null, 'O', group: 'M-PESA account', pattern: '/^\d{5,7}$/'),
            'payments.mpesa_consumer_key' => self::f('payments', 'Daraja consumer key', 'secret', null, 'O', group: 'M-PESA account'),
            'payments.mpesa_consumer_secret' => self::f('payments', 'Daraja consumer secret', 'secret', null, 'O', group: 'M-PESA account'),
            'payments.mpesa_passkey' => self::f('payments', 'Daraja passkey', 'secret', null, 'O', group: 'M-PESA account'),
            'payments.mpesa_verified' => self::f('payments', 'Verified by Tessera', 'boolean', false, 'T', group: 'M-PESA account',
                help: 'The owner enters the details; Tessera checks them with Safaricom before they are used.'),
            'payments.customer_credit' => self::f('payments', 'Customer credit', 'select', 'off', 'O', options: ['off' => 'Off', 'on' => 'On, with a limit per customer'],
                help: 'Sales "on account" for customers with a credit limit (Customers → customer → Credit account).'),
            'payments.credit_approval' => self::f('payments', 'Credit sale approval', 'select', 'manager', 'O', options: ['any' => 'Any cashier (within the limit)', 'manager' => 'Manager every time'],
                help: 'Over the customer\'s limit a manager always approves.'),
            'payments.cash_rounding' => self::f('payments', 'Cash rounding', 'select', 0, 'O',
                options: [0 => 'None', 1 => 'Nearest KSh 1', 5 => 'Nearest KSh 5', 10 => 'Nearest KSh 10'], help: 'Cash payments only; the difference is shown on the receipt.'),

            // ------------------------------------------------------------------ Staff, roles, approvals, shifts
            'staff.max_discount' => self::f('staff', 'Maximum discount per role', 'role_percent', ['Cashier' => 5, 'Branch Manager' => 20, 'Storekeeper' => 0, 'Accountant' => 0, 'Admin' => 20, 'Owner' => 100], 'O',
                help: 'Above the limit a manager approves. Roles themselves are managed under Administration → Users & roles.'),
            'staff.login_methods' => self::f('staff', 'Login method', 'multiselect', ['password', 'pin'], 'O',
                options: ['password' => 'Password', 'pin' => 'PIN at tills', 'sms' => 'SMS one-time code'], help: 'SMS codes arrive with notifications.'),
            'staff.password_min_length' => self::f('staff', 'Minimum password length', 'number', 8, 'O', min: 8, max: 64),
            'staff.password_expiry_days' => self::f('staff', 'Password expiry (days)', 'number', 0, 'O', min: 0, max: 365, help: '0 = never. After this, staff must choose a new password at sign-in.'),
            'staff.two_step_login' => self::f('staff', 'Two-step login for owners and admins', 'boolean', true, 'O',
                help: 'A code from an authenticator app after the password. Always on for Tessera support.'),
            'staff.session_timeout_minutes' => self::f('staff', 'Back office signs out after (minutes idle)', 'number', 30, 'O', min: 5, max: 480,
                help: 'Not on computers where "keep me signed in" was ticked.'),
            'staff.till_auto_lock_minutes' => self::f('staff', 'Till locks after (minutes idle)', 'number', 2, 'B', scopes: ['business', 'branch', 'till'], min: 0, max: 60,
                help: 'The sale in progress is kept; the cashier or a manager unlocks with their PIN. 0 = never.'),
            'approvals.refund' => self::f('staff', 'Refund', 'select', 'approval', 'O', group: 'Actions needing manager approval', options: self::approvalOptions()),
            'approvals.remove_item' => self::f('staff', 'Remove an item from an open sale', 'select', 'allowed', 'O', group: 'Actions needing manager approval',
                options: ['allowed' => 'Allowed; logged', 'over_amount' => 'Manager approval above an amount', 'approval' => 'Always manager approval']),
            'approvals.remove_item_threshold' => self::f('staff', 'Amount that needs approval (remove item)', 'money', 500000, 'O', group: 'Actions needing manager approval', min: 0),
            'approvals.price_change' => self::f('staff', 'Price change at the till', 'select', 'approval', 'O', group: 'Actions needing manager approval', options: self::approvalOptions()),
            'approvals.discount_above_limit' => self::f('staff', 'Discount above role limit', 'select', 'approval', 'O', group: 'Actions needing manager approval',
                options: ['approval' => 'Manager approval', 'blocked' => 'Not allowed']),
            'approvals.open_drawer' => self::f('staff', 'Open cash drawer without a sale', 'select', 'approval', 'O', group: 'Actions needing manager approval',
                options: self::approvalOptions(), available: false, note: 'Needs a connected cash drawer.'),
            'shifts.opening_float' => self::f('staff', 'Opening float', 'money', 0, 'B', group: 'Shifts and cash-up', scopes: ['till'], model: ['till', 'default_float_cents'], min: 0),
            'shifts.blind_cashup' => self::f('staff', 'Blind cash-up', 'boolean', true, 'O', group: 'Shifts and cash-up', help: 'The cashier counts without seeing the expected total.'),
            'shifts.allowed_variance' => self::f('staff', 'Allowed cash variance', 'money', 10000, 'O', group: 'Shifts and cash-up', min: 0,
                help: 'Differences above this raise an alert on the dashboard.'),
            'shifts.auto_close' => self::f('staff', 'Auto-close shifts at', 'time', null, 'B', group: 'Shifts and cash-up', scopes: ['business', 'branch'],
                available: false, note: 'Arrives later.'),

            // ------------------------------------------------------------------ Notifications
            'notifications.recipients' => self::f('notifications', 'Alert recipients', 'multiselect', ['low_stock', 'large_refund', 'cash_variance', 'etims_failure', 'daily_summary'], 'O',
                options: ['low_stock' => 'Low stock', 'large_refund' => 'Large refund', 'cash_variance' => 'Cash variance', 'etims_failure' => 'eTIMS failure', 'daily_summary' => 'End-of-day summary'],
                help: 'Which alerts are raised. Owners get every one; managers and storekeepers get those they can act on at their branch.'),
            'notifications.large_refund_cents' => self::f('notifications', 'A refund is "large" from', 'money', 500000, 'O', scopes: ['business', 'branch'], min: 0),
            'notifications.channels' => self::f('notifications', 'Channels', 'multiselect', ['in_app', 'sms'], 'O',
                options: ['in_app' => 'In-app', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'Email'],
                help: 'In-app goes to everyone who can act on the alert; SMS, WhatsApp and email go to owners (their phone and email on Users & roles). SMS and WhatsApp are logged, not sent, until a provider is set up.'),
            'notifications.sms_sender' => self::f('notifications', 'SMS sender name', 'text', 'TESSERA', 'T', max: 11, help: 'Registered sender ID; used once an SMS provider is set up.'),
            'notifications.customer_sms_templates' => self::f('notifications', 'Customer SMS wording', 'lines', null, 'O', max: 3, available: false, note: 'SMS to customers (receipts, credit reminders) arrives with an SMS provider.'),
            'notifications.daily_report_time' => self::f('notifications', 'Daily report time', 'time', '21:00', 'O', help: 'The owner\'s end-of-day summary: sales, takings, discounts and refunds, cash-ups and eTIMS.'),
            'dashboard.tiles_by_role' => self::f('notifications', 'Dashboard tiles per role', 'role_tiles', [], 'O',
                help: 'Empty for a role = the standard tiles for that role.'),

            // ------------------------------------------------------------------ Integrations
            'integrations.etims_enabled' => self::f('integrations', 'eTIMS invoicing', 'boolean', true, 'T', scopes: ['business', 'branch'],
                help: 'On for VAT-registered branches. Set up by Tessera; the owner sees the status on the eTIMS monitor.'),
            'integrations.accounting_export' => self::f('integrations', 'Accounting export', 'select', 'csv', 'O',
                options: ['csv' => 'CSV (from Reports)', 'quickbooks' => 'QuickBooks', 'xero' => 'Xero'], help: 'QuickBooks and Xero arrive later.'),
            'integrations.online_store_sync' => self::f('integrations', 'Online store stock sync', 'boolean', false, 'T', available: false, note: 'Needs Tessera Commerce.'),
            'hardware.receipt_printer' => self::f('integrations', 'Receipt printer', 'select', 'browser', 'B', group: 'Hardware', scopes: ['business', 'branch', 'till'],
                options: ['browser' => 'Print through the browser', 'none' => 'No printer']),
            'hardware.scanner' => self::f('integrations', 'Barcode scanner', 'select', 'keyboard', 'B', group: 'Hardware', scopes: ['business', 'branch', 'till'],
                options: ['keyboard' => 'USB / Bluetooth scanner (types like a keyboard)', 'none' => 'No scanner']),
            'hardware.cash_drawer' => self::f('integrations', 'Cash drawer', 'select', 'none', 'B', group: 'Hardware', scopes: ['business', 'branch', 'till'],
                options: ['none' => 'None', 'printer' => 'Opens with the receipt printer'], available: false, note: 'Direct printer and drawer control arrive later.'),
        ];
    }

    /** Industry presets: bundles of defaults written at business level. */
    public static function presets(): array
    {
        return [
            'general_retail' => ['title' => 'General retail', 'description' => 'Stock alerts, barcode, M-PESA. Tiles, light theme.', 'values' => [
                'sales.layout' => 'tiles', 'branding.theme' => 'light', 'features.sell_by_tot' => false, 'sales.age_check_prompt' => false,
                'stock.batch_tracking_default' => false, 'features.tables' => false,
            ]],
            'wines_spirits' => ['title' => 'Wines & spirits', 'description' => 'Sell by tot, crate deposits, age-check prompt, licensed-hours lock. Tiles, dark theme.', 'values' => [
                'sales.layout' => 'tiles', 'branding.theme' => 'dark', 'features.sell_by_tot' => true, 'sales.age_check_prompt' => true,
                'features.crate_deposits' => true, 'features.licensed_hours_lock' => true, 'stock.batch_tracking_default' => false, 'features.tables' => false,
            ]],
            'pharmacy' => ['title' => 'Pharmacy', 'description' => 'Batch and expiry, expiry alerts, prescription notes. Search-first, light.', 'values' => [
                'sales.layout' => 'list', 'branding.theme' => 'light', 'features.sell_by_tot' => false, 'sales.age_check_prompt' => false,
                'stock.batch_tracking_default' => true, 'features.expiry_alerts' => true, 'features.prescription_notes' => true,
            ]],
            'supermarket' => ['title' => 'Supermarket / minimart', 'description' => 'Weighing-scale items, fast barcode checkout. Barcode-first, light.', 'values' => [
                'sales.layout' => 'barcode', 'branding.theme' => 'light', 'features.sell_by_tot' => false, 'sales.age_check_prompt' => false,
                'features.weighing_scale' => true, 'stock.batch_tracking_default' => true,
            ]],
            'hardware' => ['title' => 'Hardware', 'description' => 'Sell by metre and kg, quotations, customer credit. List, light.', 'values' => [
                'sales.layout' => 'list', 'branding.theme' => 'light', 'features.sell_by_tot' => false, 'sales.age_check_prompt' => false,
                'features.quotations' => true, 'payments.customer_credit' => 'on',
            ]],
            'restaurant_bar' => ['title' => 'Restaurant / bar', 'description' => 'Tables, kitchen tickets, tips, modifiers. Tiles, dark.', 'values' => [
                'sales.layout' => 'tiles', 'branding.theme' => 'dark', 'features.sell_by_tot' => true, 'sales.age_check_prompt' => true, 'features.tables' => true,
            ]],
        ];
    }

    /** Fixed for every client (shown read-only in Settings). */
    public static function locked(): array
    {
        return [
            ['item' => 'Invoice numbers after a sale is issued', 'why' => 'eTIMS invoices must stay in sequence and match KRA records', 'correction' => 'Issue a credit note (return) from the same system'],
            ['item' => 'Deleting completed sales', 'why' => 'Removes the audit trail and breaks eTIMS matching', 'correction' => 'Return or refund with approval; both are logged'],
            ['item' => 'Activity log', 'why' => 'Owners and auditors rely on it to trace refunds, voids and price changes', 'correction' => 'Read-only for everyone; exportable'],
            ['item' => 'Tax rates outside KRA categories', 'why' => 'Wrong rates create wrong eTIMS invoices', 'correction' => 'Tessera updates rates centrally when the law changes'],
            ['item' => 'KRA PIN and eTIMS credentials', 'why' => 'Errors stop invoicing', 'correction' => 'Changed by Tessera support after verification'],
            ['item' => 'Colours below readable contrast', 'why' => 'Unreadable screens cause errors at the till', 'correction' => 'Custom colours are checked before saving'],
            ['item' => 'Tessera support contact and "Powered by Tessera"', 'why' => 'Staff must always be able to find help', 'correction' => 'Shown small in the footer and on login'],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, mixed> */
    private static function f(
        string $section, string $label, string $type, mixed $default, string $level,
        array $options = [], array $scopes = ['business'], array $branchLevelScopes = [], ?array $model = null,
        ?int $max = null, ?int $min = null, ?string $pattern = null, ?string $help = null, ?string $group = null,
        bool $available = true, ?string $note = null,
    ): array {
        return compact('section', 'label', 'type', 'default', 'level', 'options', 'scopes', 'branchLevelScopes', 'model', 'max', 'min', 'pattern', 'help', 'group', 'available', 'note');
    }

    /** @return array<int, string> */
    private static function months(): array
    {
        return array_combine(range(1, 12), ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']);
    }

    /** @return array<string, string> */
    private static function approvalOptions(): array
    {
        return ['approval' => 'Manager approval', 'allowed' => 'Allowed; logged'];
    }
}
