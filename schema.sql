-- =============================================================================
-- PT RantauMart Riau — PostgreSQL Database Schema (schema.sql)
-- Reference: Student Scenario Handout — Bagian 4. Dataset Realistis & Modul ERP
-- Integritas Data Tingkat Tinggi: Strict Foreign Keys dengan ON DELETE RESTRICT
-- =============================================================================

BEGIN;

-- Drop existing tables if re-initializing
DROP TABLE IF EXISTS audit_logs CASCADE;
DROP TABLE IF EXISTS document_sequences CASCADE;
DROP TABLE IF EXISTS journal_entry_lines CASCADE;
DROP TABLE IF EXISTS journal_entries CASCADE;
DROP TABLE IF EXISTS returns_refunds CASCADE;
DROP TABLE IF EXISTS payments CASCADE;
DROP TABLE IF EXISTS pos_receipt_lines CASCADE;
DROP TABLE IF EXISTS pos_receipts CASCADE;
DROP TABLE IF EXISTS internal_transfers CASCADE;
DROP TABLE IF EXISTS purchase_receipts CASCADE;
DROP TABLE IF EXISTS delivery_orders CASCADE;
DROP TABLE IF EXISTS pos_sessions CASCADE;
DROP TABLE IF EXISTS vendor_bills CASCADE;
DROP TABLE IF EXISTS customer_invoices CASCADE;
DROP TABLE IF EXISTS purchase_order_lines CASCADE;
DROP TABLE IF EXISTS purchase_orders CASCADE;
DROP TABLE IF EXISTS sales_order_lines CASCADE;
DROP TABLE IF EXISTS sales_orders CASCADE;
DROP TABLE IF EXISTS crm_leads CASCADE;
DROP TABLE IF EXISTS existing_documents CASCADE;
DROP TABLE IF EXISTS stock_by_location CASCADE;
DROP TABLE IF EXISTS opening_stock CASCADE;
DROP TABLE IF EXISTS vendor_pricelist CASCADE;
DROP TABLE IF EXISTS products CASCADE;
DROP TABLE IF EXISTS contacts_customers CASCADE;
DROP TABLE IF EXISTS contacts_vendors CASCADE;
DROP TABLE IF EXISTS app_users CASCADE;
DROP TABLE IF EXISTS warehouses_locations CASCADE;
DROP TABLE IF EXISTS chart_of_accounts CASCADE;
DROP TABLE IF EXISTS tax_rates CASCADE;
DROP TABLE IF EXISTS payment_terms CASCADE;

-- =============================================================================
-- 1. MASTER REFERENSI PAJAK & TERMIN PEMBAYARAN
-- =============================================================================

CREATE TABLE payment_terms (
    code VARCHAR(20) PRIMARY KEY,
    due_days INTEGER NOT NULL CHECK (due_days >= 0),
    usage TEXT NOT NULL
);

CREATE TABLE tax_rates (
    tax_code VARCHAR(20) PRIMARY KEY,
    formal_rate VARCHAR(10) NOT NULL,
    formal_rate_pct NUMERIC(5, 2) NOT NULL,
    tax_base_factor VARCHAR(20) NOT NULL,
    effective_rate VARCHAR(10) NOT NULL,
    effective_rate_pct NUMERIC(5, 2) NOT NULL,
    tax_rounding TEXT NOT NULL
);

-- =============================================================================
-- 2. MASTER LOKASI / GUDANG & TOKO (warehouses_locations) & PENGGUNA (app_users)
-- =============================================================================

CREATE TABLE warehouses_locations (
    location_code VARCHAR(20) PRIMARY KEY,
    location_name VARCHAR(100) NOT NULL,
    city VARCHAR(50) NOT NULL,
    function TEXT NOT NULL
);

CREATE TABLE app_users (
    user_id VARCHAR(20) PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_plain VARCHAR(50) NOT NULL DEFAULT 'demo123',
    full_name VARCHAR(100) NOT NULL,
    role VARCHAR(30) NOT NULL CHECK (role IN ('Sales', 'Warehouse', 'Procurement', 'Finance', 'Cashier', 'Owner')),
    assigned_location VARCHAR(20) REFERENCES warehouses_locations(location_code) ON DELETE RESTRICT
);

-- =============================================================================
-- 3. MASTER PELANGGAN & VENDOR (contacts_customers, contacts_vendors)
-- =============================================================================

CREATE TABLE contacts_customers (
    id VARCHAR(20) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(50) NOT NULL,
    city VARCHAR(50) NOT NULL,
    credit_limit NUMERIC(15, 2) NOT NULL DEFAULT 0 CHECK (credit_limit >= 0),
    payment_terms VARCHAR(20) NOT NULL REFERENCES payment_terms(code) ON DELETE RESTRICT,
    opening_ar NUMERIC(15, 2) NOT NULL DEFAULT 0 CHECK (opening_ar >= 0)
);

CREATE TABLE contacts_vendors (
    id VARCHAR(20) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    city VARCHAR(50) NOT NULL,
    goods TEXT NOT NULL,
    payment_terms VARCHAR(20) NOT NULL REFERENCES payment_terms(code) ON DELETE RESTRICT,
    opening_ap NUMERIC(15, 2) NOT NULL DEFAULT 0 CHECK (opening_ap >= 0)
);

-- =============================================================================
-- 4. MASTER PRODUK & PRICELIST VENDOR (products, vendor_pricelist)
-- =============================================================================

CREATE TABLE products (
    variant_sku VARCHAR(30) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    size VARCHAR(30) NOT NULL,
    color_flavour VARCHAR(50) NOT NULL,
    unit VARCHAR(10) NOT NULL DEFAULT 'pcs',
    sales_price NUMERIC(15, 2) NOT NULL CHECK (sales_price >= 0),
    cost NUMERIC(15, 2) NOT NULL CHECK (cost >= 0),
    default_vendor VARCHAR(20) NOT NULL REFERENCES contacts_vendors(id) ON DELETE RESTRICT,
    tax_code VARCHAR(20) NOT NULL DEFAULT 'PPN-11' REFERENCES tax_rates(tax_code) ON DELETE RESTRICT
);

CREATE TABLE vendor_pricelist (
    id SERIAL PRIMARY KEY,
    vendor_id VARCHAR(20) NOT NULL REFERENCES contacts_vendors(id) ON DELETE RESTRICT,
    variant_sku VARCHAR(30) NOT NULL REFERENCES products(variant_sku) ON DELETE RESTRICT,
    vendor_unit_price NUMERIC(15, 2) NOT NULL CHECK (vendor_unit_price >= 0),
    minimum_qty INTEGER NOT NULL DEFAULT 1 CHECK (minimum_qty >= 1),
    currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
    UNIQUE (vendor_id, variant_sku)
);

-- =============================================================================
-- 5. OPENING STOCK & STOK PER LOKASI (opening_stock, stock_by_location)
-- =============================================================================

CREATE TABLE opening_stock (
    variant_sku VARCHAR(30) PRIMARY KEY REFERENCES products(variant_sku) ON DELETE RESTRICT,
    wh_pku INTEGER NOT NULL CHECK (wh_pku >= 0),
    store_pku INTEGER NOT NULL CHECK (store_pku >= 0),
    store_dum INTEGER NOT NULL CHECK (store_dum >= 0),
    company_total_pcs INTEGER NOT NULL CHECK (company_total_pcs = wh_pku + store_pku + store_dum),
    standard_cost NUMERIC(15, 2) NOT NULL CHECK (standard_cost >= 0),
    standard_value NUMERIC(15, 2) NOT NULL CHECK (standard_value = company_total_pcs * standard_cost)
);

CREATE TABLE stock_by_location (
    location_code VARCHAR(20) NOT NULL REFERENCES warehouses_locations(location_code) ON DELETE RESTRICT,
    variant_sku VARCHAR(30) NOT NULL REFERENCES products(variant_sku) ON DELETE RESTRICT,
    on_hand_qty INTEGER NOT NULL DEFAULT 0 CHECK (on_hand_qty >= 0),
    reserved_qty INTEGER NOT NULL DEFAULT 0 CHECK (reserved_qty >= 0),
    available_qty INTEGER GENERATED ALWAYS AS (on_hand_qty - reserved_qty) STORED,
    standard_cost NUMERIC(15, 2) NOT NULL CHECK (standard_cost >= 0),
    valuation NUMERIC(15, 2) GENERATED ALWAYS AS (on_hand_qty * standard_cost) STORED,
    PRIMARY KEY (location_code, variant_sku),
    CONSTRAINT chk_available_non_negative CHECK (on_hand_qty >= reserved_qty)
);

-- =============================================================================
-- 6. BAGAN AKUN & JURNAL AKUNTANSI (chart_of_accounts, journal_entries)
-- =============================================================================

CREATE TABLE chart_of_accounts (
    account_code VARCHAR(20) PRIMARY KEY,
    account_name VARCHAR(100) NOT NULL,
    type VARCHAR(50) NOT NULL,
    opening_debit NUMERIC(15, 2) NOT NULL DEFAULT 0 CHECK (opening_debit >= 0),
    opening_credit NUMERIC(15, 2) NOT NULL DEFAULT 0 CHECK (opening_credit >= 0),
    CONSTRAINT chk_single_sided_opening CHECK (NOT (opening_debit > 0 AND opening_credit > 0))
);

CREATE TABLE journal_entries (
    entry_number VARCHAR(30) PRIMARY KEY,
    entry_date DATE NOT NULL DEFAULT CURRENT_DATE,
    source_document VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Posted' CHECK (status IN ('Draft', 'Posted')),
    is_opening_entry BOOLEAN NOT NULL DEFAULT FALSE,
    total_debit NUMERIC(15, 2) NOT NULL CHECK (total_debit >= 0),
    total_credit NUMERIC(15, 2) NOT NULL CHECK (total_credit >= 0),
    CONSTRAINT chk_balanced_journal CHECK (total_debit = total_credit)
);

CREATE TABLE journal_entry_lines (
    id SERIAL PRIMARY KEY,
    entry_number VARCHAR(30) NOT NULL REFERENCES journal_entries(entry_number) ON DELETE RESTRICT,
    account_code VARCHAR(20) NOT NULL REFERENCES chart_of_accounts(account_code) ON DELETE RESTRICT,
    partner_id VARCHAR(20),
    debit NUMERIC(15, 2) NOT NULL DEFAULT 0 CHECK (debit >= 0),
    credit NUMERIC(15, 2) NOT NULL DEFAULT 0 CHECK (credit >= 0),
    memo TEXT,
    CONSTRAINT chk_line_debit_or_credit CHECK (
        (debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)
    )
);

-- =============================================================================
-- 7. DOKUMEN EKSISTING BASELINE & TRANSAKSI (Strict Foreign Keys: ON DELETE RESTRICT)
-- =============================================================================

CREATE TABLE existing_documents (
    document_number VARCHAR(30) PRIMARY KEY,
    document_type VARCHAR(30) NOT NULL,
    partner_lines TEXT NOT NULL,
    status VARCHAR(50) NOT NULL,
    stock_and_financial_state TEXT NOT NULL
);

CREATE TABLE crm_leads (
    lead_number VARCHAR(30) PRIMARY KEY,
    customer_id VARCHAR(20) NOT NULL REFERENCES contacts_customers(id) ON DELETE RESTRICT,
    expected_sku VARCHAR(30) REFERENCES products(variant_sku) ON DELETE RESTRICT,
    expected_qty INTEGER DEFAULT 0,
    stage VARCHAR(30) NOT NULL DEFAULT 'New' CHECK (stage IN ('New', 'Qualified', 'Won')),
    converted_opportunity_id VARCHAR(30) UNIQUE,
    status VARCHAR(50) NOT NULL DEFAULT 'New / not converted'
);

CREATE TABLE sales_orders (
    so_number VARCHAR(30) PRIMARY KEY,
    customer_id VARCHAR(20) NOT NULL REFERENCES contacts_customers(id) ON DELETE RESTRICT,
    source_location VARCHAR(20) NOT NULL DEFAULT 'WH-PKU' REFERENCES warehouses_locations(location_code) ON DELETE RESTRICT,
    source_lead VARCHAR(30) REFERENCES crm_leads(lead_number) ON DELETE RESTRICT,
    status VARCHAR(50) NOT NULL DEFAULT 'Draft' CHECK (status IN ('Draft', 'Confirmed', 'Done', 'Cancelled')),
    subtotal_net NUMERIC(15, 2) NOT NULL DEFAULT 0,
    tax_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    total_gross NUMERIC(15, 2) NOT NULL DEFAULT 0,
    price_locked BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE sales_order_lines (
    id SERIAL PRIMARY KEY,
    so_number VARCHAR(30) NOT NULL REFERENCES sales_orders(so_number) ON DELETE RESTRICT,
    variant_sku VARCHAR(30) NOT NULL REFERENCES products(variant_sku) ON DELETE RESTRICT,
    ordered_qty INTEGER NOT NULL CHECK (ordered_qty > 0),
    delivered_qty INTEGER NOT NULL DEFAULT 0 CHECK (delivered_qty >= 0),
    invoiced_qty INTEGER NOT NULL DEFAULT 0 CHECK (invoiced_qty >= 0),
    unit_price NUMERIC(15, 2) NOT NULL CHECK (unit_price >= 0),
    discount_pct NUMERIC(5, 2) NOT NULL DEFAULT 0 CHECK (discount_pct >= 0 AND discount_pct <= 100),
    tax_code VARCHAR(20) NOT NULL DEFAULT 'PPN-11' REFERENCES tax_rates(tax_code) ON DELETE RESTRICT,
    line_subtotal NUMERIC(15, 2) NOT NULL,
    line_tax NUMERIC(15, 2) NOT NULL,
    line_gross NUMERIC(15, 2) NOT NULL
);

CREATE TABLE purchase_orders (
    po_number VARCHAR(30) PRIMARY KEY,
    vendor_id VARCHAR(20) NOT NULL REFERENCES contacts_vendors(id) ON DELETE RESTRICT,
    destination_location VARCHAR(20) NOT NULL DEFAULT 'WH-PKU' REFERENCES warehouses_locations(location_code) ON DELETE RESTRICT,
    status VARCHAR(50) NOT NULL DEFAULT 'RFQ' CHECK (status IN ('RFQ', 'Confirmed', 'Purchase Order', 'Received', 'Done', 'Cancelled')),
    subtotal_net NUMERIC(15, 2) NOT NULL DEFAULT 0,
    tax_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    total_gross NUMERIC(15, 2) NOT NULL DEFAULT 0,
    price_locked BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE purchase_order_lines (
    id SERIAL PRIMARY KEY,
    po_number VARCHAR(30) NOT NULL REFERENCES purchase_orders(po_number) ON DELETE RESTRICT,
    variant_sku VARCHAR(30) NOT NULL REFERENCES products(variant_sku) ON DELETE RESTRICT,
    ordered_qty INTEGER NOT NULL CHECK (ordered_qty > 0),
    received_qty INTEGER NOT NULL DEFAULT 0 CHECK (received_qty >= 0),
    accepted_qty INTEGER NOT NULL DEFAULT 0 CHECK (accepted_qty >= 0),
    rejected_qty INTEGER NOT NULL DEFAULT 0 CHECK (rejected_qty >= 0),
    billed_qty INTEGER NOT NULL DEFAULT 0 CHECK (billed_qty >= 0),
    unit_price NUMERIC(15, 2) NOT NULL CHECK (unit_price >= 0),
    tax_code VARCHAR(20) NOT NULL DEFAULT 'PPN-11' REFERENCES tax_rates(tax_code) ON DELETE RESTRICT,
    line_subtotal NUMERIC(15, 2) NOT NULL,
    line_tax NUMERIC(15, 2) NOT NULL,
    line_gross NUMERIC(15, 2) NOT NULL
);

CREATE TABLE delivery_orders (
    id SERIAL PRIMARY KEY,
    do_number VARCHAR(30) NOT NULL UNIQUE,
    so_number VARCHAR(30) NOT NULL REFERENCES sales_orders(so_number) ON DELETE RESTRICT,
    source_location VARCHAR(20) NOT NULL DEFAULT 'WH-PKU' REFERENCES warehouses_locations(location_code) ON DELETE RESTRICT,
    variant_sku VARCHAR(30) NOT NULL REFERENCES products(variant_sku) ON DELETE RESTRICT,
    demand_qty INTEGER NOT NULL CHECK (demand_qty > 0),
    reserved_qty INTEGER NOT NULL DEFAULT 0 CHECK (reserved_qty >= 0),
    done_qty INTEGER NOT NULL DEFAULT 0 CHECK (done_qty >= 0),
    backorder_qty INTEGER NOT NULL DEFAULT 0 CHECK (backorder_qty >= 0),
    standard_cost NUMERIC(15, 2) NOT NULL DEFAULT 0,
    cogs_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL DEFAULT 'Done' CHECK (status IN ('Waiting', 'Ready', 'Partial / Backorder', 'Done')),
    journal_entry_number VARCHAR(30) REFERENCES journal_entries(entry_number) ON DELETE RESTRICT,
    validated_by VARCHAR(100),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE purchase_receipts (
    id SERIAL PRIMARY KEY,
    receipt_number VARCHAR(30) NOT NULL UNIQUE,
    po_number VARCHAR(30) NOT NULL REFERENCES purchase_orders(po_number) ON DELETE RESTRICT,
    destination_location VARCHAR(20) NOT NULL DEFAULT 'WH-PKU' REFERENCES warehouses_locations(location_code) ON DELETE RESTRICT,
    variant_sku VARCHAR(30) NOT NULL REFERENCES products(variant_sku) ON DELETE RESTRICT,
    ordered_qty INTEGER NOT NULL CHECK (ordered_qty > 0),
    received_qty INTEGER NOT NULL CHECK (received_qty >= 0),
    accepted_qty INTEGER NOT NULL CHECK (accepted_qty >= 0),
    rejected_qty INTEGER NOT NULL CHECK (rejected_qty >= 0),
    standard_cost NUMERIC(15, 2) NOT NULL DEFAULT 0,
    valuation_added NUMERIC(15, 2) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'Done' CHECK (status IN ('Waiting', 'Ready', 'Done')),
    journal_entry_number VARCHAR(30) REFERENCES journal_entries(entry_number) ON DELETE RESTRICT,
    validated_by VARCHAR(100),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE internal_transfers (
    id SERIAL PRIMARY KEY,
    transfer_number VARCHAR(30) NOT NULL UNIQUE,
    from_location VARCHAR(20) NOT NULL REFERENCES warehouses_locations(location_code) ON DELETE RESTRICT,
    to_location VARCHAR(20) NOT NULL REFERENCES warehouses_locations(location_code) ON DELETE RESTRICT,
    variant_sku VARCHAR(30) NOT NULL REFERENCES products(variant_sku) ON DELETE RESTRICT,
    qty_move INTEGER NOT NULL CHECK (qty_move > 0),
    standard_cost NUMERIC(15, 2) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'Done' CHECK (status IN ('Draft', 'Ready', 'Done')),
    transferred_by VARCHAR(100),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE customer_invoices (
    invoice_number VARCHAR(30) PRIMARY KEY,
    so_number VARCHAR(30) NOT NULL REFERENCES sales_orders(so_number) ON DELETE RESTRICT,
    customer_id VARCHAR(20) NOT NULL REFERENCES contacts_customers(id) ON DELETE RESTRICT,
    variant_sku VARCHAR(30) NOT NULL REFERENCES products(variant_sku) ON DELETE RESTRICT,
    delivered_qty INTEGER NOT NULL DEFAULT 0 CHECK (delivered_qty >= 0),
    invoiced_qty INTEGER NOT NULL DEFAULT 0 CHECK (invoiced_qty > 0),
    returned_qty INTEGER NOT NULL DEFAULT 0 CHECK (returned_qty >= 0),
    unit_price NUMERIC(15, 2) NOT NULL DEFAULT 0,
    subtotal_net NUMERIC(15, 2) NOT NULL DEFAULT 0,
    tax_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    total_gross NUMERIC(15, 2) NOT NULL DEFAULT 0,
    paid_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    outstanding_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'Draft' CHECK (status IN ('Draft', 'Posted', 'Partially Paid', 'Paid', 'Credit Note Issued')),
    journal_entry_number VARCHAR(30) REFERENCES journal_entries(entry_number) ON DELETE RESTRICT,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE vendor_bills (
    bill_number VARCHAR(30) PRIMARY KEY,
    po_number VARCHAR(30) REFERENCES purchase_orders(po_number) ON DELETE RESTRICT,
    vendor_id VARCHAR(20) NOT NULL REFERENCES contacts_vendors(id) ON DELETE RESTRICT,
    variant_sku VARCHAR(30) DEFAULT 'MIE-GOR-80' REFERENCES products(variant_sku) ON DELETE RESTRICT,
    accepted_qty INTEGER NOT NULL DEFAULT 0 CHECK (accepted_qty >= 0),
    billed_qty INTEGER NOT NULL DEFAULT 0 CHECK (billed_qty >= 0),
    buy_price NUMERIC(15, 2) NOT NULL DEFAULT 0,
    standard_cost NUMERIC(15, 2) NOT NULL DEFAULT 0,
    ppv_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'Draft' CHECK (status IN ('Draft', 'Posted', 'Partially Paid', 'Paid')),
    subtotal_net NUMERIC(15, 2) NOT NULL DEFAULT 0,
    tax_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    total_gross NUMERIC(15, 2) NOT NULL DEFAULT 0,
    paid_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    outstanding_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    journal_entry_number VARCHAR(30) REFERENCES journal_entries(entry_number) ON DELETE RESTRICT,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE payments (
    id SERIAL PRIMARY KEY,
    payment_number VARCHAR(30) NOT NULL UNIQUE,
    payment_type VARCHAR(20) NOT NULL CHECK (payment_type IN ('Customer', 'Vendor')),
    partner_id VARCHAR(20) NOT NULL,
    document_number VARCHAR(30) NOT NULL,
    amount NUMERIC(15, 2) NOT NULL CHECK (amount > 0),
    remaining_after NUMERIC(15, 2) NOT NULL DEFAULT 0 CHECK (remaining_after >= 0),
    status VARCHAR(30) NOT NULL DEFAULT 'Reconciled' CHECK (status IN ('Draft', 'Posted', 'Reconciled')),
    journal_entry_number VARCHAR(30) REFERENCES journal_entries(entry_number) ON DELETE RESTRICT,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE pos_sessions (
    session_number VARCHAR(30) PRIMARY KEY,
    location_code VARCHAR(20) NOT NULL REFERENCES warehouses_locations(location_code) ON DELETE RESTRICT,
    register_code VARCHAR(20) NOT NULL DEFAULT 'POS01',
    cashier_name VARCHAR(100) NOT NULL,
    user_id VARCHAR(20) REFERENCES app_users(user_id) ON DELETE RESTRICT,
    status VARCHAR(30) NOT NULL DEFAULT 'Closed' CHECK (status IN ('Closed', 'Open', 'In Progress', 'Pending Approval')),
    opening_cash NUMERIC(15, 2) NOT NULL DEFAULT 200000,
    cash_tenders NUMERIC(15, 2) NOT NULL DEFAULT 0,
    cash_refunds NUMERIC(15, 2) NOT NULL DEFAULT 0,
    expected_cash NUMERIC(15, 2) NOT NULL DEFAULT 200000,
    counted_cash NUMERIC(15, 2),
    variance NUMERIC(15, 2) NOT NULL DEFAULT 0,
    net_sales_revenue NUMERIC(15, 2) NOT NULL DEFAULT 0,
    output_vat NUMERIC(15, 2) NOT NULL DEFAULT 0,
    total_cogs NUMERIC(15, 2) NOT NULL DEFAULT 0,
    approved_by_finance BOOLEAN NOT NULL DEFAULT FALSE,
    settlement_je_number VARCHAR(30) REFERENCES journal_entries(entry_number) ON DELETE RESTRICT,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE pos_receipts (
    receipt_number VARCHAR(30) PRIMARY KEY,
    session_number VARCHAR(30) NOT NULL REFERENCES pos_sessions(session_number) ON DELETE RESTRICT,
    location_code VARCHAR(20) NOT NULL REFERENCES warehouses_locations(location_code) ON DELETE RESTRICT,
    register_code VARCHAR(20) NOT NULL DEFAULT 'POS01',
    cashier_name VARCHAR(100) NOT NULL,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'Cash' CHECK (payment_method IN ('Cash', 'Bank')),
    subtotal_net NUMERIC(15, 2) NOT NULL DEFAULT 0,
    tax_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    total_gross NUMERIC(15, 2) NOT NULL DEFAULT 0,
    total_cogs NUMERIC(15, 2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'Paid' CHECK (status IN ('New', 'Payment', 'Paid', 'Refunded')),
    cogs_je_number VARCHAR(30) REFERENCES journal_entries(entry_number) ON DELETE RESTRICT,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE pos_receipt_lines (
    id SERIAL PRIMARY KEY,
    receipt_number VARCHAR(30) NOT NULL REFERENCES pos_receipts(receipt_number) ON DELETE RESTRICT,
    variant_sku VARCHAR(30) NOT NULL REFERENCES products(variant_sku) ON DELETE RESTRICT,
    qty INTEGER NOT NULL CHECK (qty > 0),
    returned_qty INTEGER NOT NULL DEFAULT 0 CHECK (returned_qty >= 0),
    unit_price NUMERIC(15, 2) NOT NULL CHECK (unit_price >= 0),
    standard_cost NUMERIC(15, 2) NOT NULL CHECK (standard_cost >= 0),
    line_subtotal NUMERIC(15, 2) NOT NULL,
    line_tax NUMERIC(15, 2) NOT NULL,
    line_gross NUMERIC(15, 2) NOT NULL,
    line_cogs NUMERIC(15, 2) NOT NULL
);

CREATE TABLE returns_refunds (
    return_number VARCHAR(30) PRIMARY KEY,
    return_type VARCHAR(20) NOT NULL CHECK (return_type IN ('POS_REFUND', 'B2B_CREDIT_NOTE')),
    source_document VARCHAR(30) NOT NULL,
    location_code VARCHAR(20) NOT NULL REFERENCES warehouses_locations(location_code) ON DELETE RESTRICT,
    variant_sku VARCHAR(30) NOT NULL REFERENCES products(variant_sku) ON DELETE RESTRICT,
    return_qty INTEGER NOT NULL CHECK (return_qty > 0),
    condition VARCHAR(30) NOT NULL DEFAULT 'Resalable' CHECK (condition IN ('Resalable', 'Damaged')),
    restocked BOOLEAN NOT NULL DEFAULT TRUE,
    refund_net NUMERIC(15, 2) NOT NULL DEFAULT 0,
    refund_tax NUMERIC(15, 2) NOT NULL DEFAULT 0,
    refund_gross NUMERIC(15, 2) NOT NULL DEFAULT 0,
    reversed_cogs NUMERIC(15, 2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'Done' CHECK (status IN ('Draft', 'Confirmed', 'Done')),
    journal_entry_number VARCHAR(30) REFERENCES journal_entries(entry_number) ON DELETE RESTRICT,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

-- =============================================================================
-- 8. PENOMORAN UNIK & LOG AUDIT (F-01, F-23, SR-18)
-- =============================================================================

CREATE TABLE document_sequences (
    prefix VARCHAR(20) PRIMARY KEY,
    last_number INTEGER NOT NULL DEFAULT 0,
    padding INTEGER NOT NULL DEFAULT 4
);

CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    user_id VARCHAR(20) REFERENCES app_users(user_id) ON DELETE RESTRICT,
    actor VARCHAR(50) NOT NULL,
    role VARCHAR(30) NOT NULL,
    module VARCHAR(50) NOT NULL DEFAULT 'Core',
    action VARCHAR(100) NOT NULL,
    document_number VARCHAR(50),
    old_status VARCHAR(50),
    new_status VARCHAR(50),
    outcome VARCHAR(20) NOT NULL DEFAULT 'ALLOWED' CHECK (outcome IN ('ALLOWED', 'DENIED', 'REPLAY_BLOCKED')),
    reason TEXT NOT NULL
);

COMMIT;
