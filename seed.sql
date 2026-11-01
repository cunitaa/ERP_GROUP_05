-- =============================================================================
-- PT RantauMart Riau — PostgreSQL Baseline Seed Data (seed.sql)
-- Reference: Student Scenario Handout — Bagian 4. Dataset Realistis
-- Seluruh nilai diekstrak secara harfiah dari dokumen skenario tanpa nilai acak.
-- =============================================================================

BEGIN;

-- Reset semua tabel agar idempoten saat mereset fixture T-xx
TRUNCATE TABLE
    audit_logs,
    document_sequences,
    journal_entry_lines,
    journal_entries,
    returns_refunds,
    payments,
    pos_receipt_lines,
    pos_receipts,
    pos_sessions,
    internal_transfers,
    purchase_receipts,
    delivery_orders,
    vendor_bills,
    customer_invoices,
    purchase_order_lines,
    purchase_orders,
    sales_order_lines,
    sales_orders,
    crm_leads,
    existing_documents,
    stock_by_location,
    opening_stock,
    vendor_pricelist,
    products,
    contacts_customers,
    contacts_vendors,
    warehouses_locations,
    chart_of_accounts,
    tax_rates,
    payment_terms,
    app_users
RESTART IDENTITY CASCADE;

-- =============================================================================
-- 1. payment_terms
-- =============================================================================
INSERT INTO payment_terms (code, due_days, usage) VALUES
('COD',    0,  'POS and cash on delivery'),
('NET 14', 14, 'Selected B2B/customer and vendors'),
('NET 30', 30, 'Selected B2B/customer and vendors');

-- =============================================================================
-- 2. tax_rates
-- =============================================================================
INSERT INTO tax_rates (tax_code, formal_rate, formal_rate_pct, tax_base_factor, effective_rate, effective_rate_pct, tax_rounding) VALUES
('PPN-11',  '12%', 12.00, '11/12', '11%', 11.00, 'half up per line in IDR'),
('NON-TAX', '0%',   0.00, '1',     '0%',   0.00, 'not used in golden tests');

-- =============================================================================
-- 3. warehouses_locations (3 kode lokasi tunggal)
-- =============================================================================
INSERT INTO warehouses_locations (location_code, location_name, city, function) VALUES
('WH-PKU',    'Gudang pusat Pekanbaru', 'Pekanbaru', 'B2B distribution dan vendor receipts'),
('STORE-PKU', 'RantauMart Pekanbaru',   'Pekanbaru', 'POS sales, opening and closing cash'),
('STORE-DUM', 'RantauMart Dumai',       'Dumai',     'POS inventory / store stock');

-- =============================================================================
-- 4. contacts_customers (6 pelanggan; Saldo awal AR C002 = Rp888.000)
-- =============================================================================
INSERT INTO contacts_customers (id, name, type, city, credit_limit, payment_terms, opening_ar) VALUES
('C001', 'Warung Kopi Pasar',   'B2B',           'Pekanbaru',  500000, 'NET 14',      0),
('C002', 'CV Kantin Tunas',     'B2B',           'Pekanbaru', 2000000, 'NET 30', 888000),
('C003', 'TK Harapan Keluarga', 'Institusi',     'Siak',      1000000, 'NET 14',      0),
('C004', 'Grosir Mitra Dumai',  'Distributor',   'Dumai',     5000000, 'NET 30',      0),
('C005', 'Ibu Ratna Puspita',   'Pelanggan POS', 'Pekanbaru',       0, 'COD',         0),
('C006', 'PT Sudut Ritel',      'B2B',           'Padang',    3000000, 'NET 30',      0);

-- =============================================================================
-- 5. contacts_vendors (4 vendor; Saldo awal AP V01 = Rp555.000)
-- =============================================================================
INSERT INTO contacts_vendors (id, name, city, goods, payment_terms, opening_ap) VALUES
('V01', 'PT Pangan Pilihan',  'Pekanbaru', 'Makanan kemasan',             'NET 30', 555000),
('V02', 'CV Tirta Susu',      'Medan',     'Minuman UHT dan air mineral', 'NET 14',      0),
('V03', 'UD Sembako Bersama', 'Pekanbaru', 'Beras, gula, minyak',         'NET 30',      0),
('V04', 'PT Rumah Bersih',    'Batam',     'Produk rumah tangga',         'NET 14',      0);

-- =============================================================================
-- 6. products (15 SKU varian; seluruh harga adalah sebelum pajak dalam IDR)
-- =============================================================================
INSERT INTO products (variant_sku, name, size, color_flavour, unit, sales_price, cost, default_vendor, tax_code) VALUES
('AIR-600',     'Air mineral Nusa',   '600 ml', 'Original',    'pcs',  4000,  2500, 'V02', 'PPN-11'),
('AIR-1500',    'Air mineral Nusa',   '1,5 L',  'Original',    'pcs',  7500,  5000, 'V02', 'PPN-11'),
('MIL-COK-200', 'Susu UHT Segar',     '200 ml', 'Cokelat',     'pcs',  7000,  4200, 'V02', 'PPN-11'),
('MIL-STR-200', 'Susu UHT Segar',     '200 ml', 'Stroberi',    'pcs',  7000,  4200, 'V02', 'PPN-11'),
('MIL-PLN-1L',  'Susu UHT Segar',     '1 L',    'Plain',       'pcs', 25000, 17500, 'V02', 'PPN-11'),
('BIS-COK-120', 'Biskuit Crispy',     '120 g',  'Cokelat',     'pcs',  9500,  6000, 'V01', 'PPN-11'),
('BIS-VAN-120', 'Biskuit Crispy',     '120 g',  'Vanila',      'pcs',  9500,  6000, 'V01', 'PPN-11'),
('MIE-GOR-80',  'Mi instan Hemat',    '80 g',   'Goreng',      'pcs',  4200,  2800, 'V01', 'PPN-11'),
('MIE-KUA-80',  'Mi instan Hemat',    '80 g',   'Kuah',        'pcs',  4200,  2800, 'V01', 'PPN-11'),
('KOP-SCH-20',  'Kopi instan sachet', '20 g',   'Original',    'pcs',  3500,  1900, 'V01', 'PPN-11'),
('GUL-1KG',     'Gula kemasan',       '1 kg',   'Putih',       'pcs', 18000, 13000, 'V03', 'PPN-11'),
('BER-5KG',     'Beras premium',      '5 kg',   'Premium',     'pcs', 76000, 60000, 'V03', 'PPN-11'),
('MIN-1L',      'Minyak goreng',      '1 L',    'Botol',       'pcs', 20000, 14500, 'V03', 'PPN-11'),
('SAB-450',     'Sabun cair',         '450 ml', 'Antibakteri', 'pcs', 22000, 14000, 'V04', 'PPN-11'),
('DET-800',     'Detergen bubuk',     '800 g',  'Lemon',       'pcs', 24000, 15500, 'V04', 'PPN-11');

-- =============================================================================
-- 7. vendor_pricelist (7 kontrak harga vendor)
-- =============================================================================
INSERT INTO vendor_pricelist (vendor_id, variant_sku, vendor_unit_price, minimum_qty, currency) VALUES
('V01', 'MIE-GOR-80',   3000, 1, 'IDR'),
('V01', 'BIS-COK-120',  6200, 1, 'IDR'),
('V02', 'AIR-600',      2500, 1, 'IDR'),
('V02', 'MIL-COK-200',  4300, 1, 'IDR'),
('V03', 'BER-5KG',     60500, 1, 'IDR'),
('V03', 'GUL-1KG',     13200, 1, 'IDR'),
('V04', 'SAB-450',     14400, 1, 'IDR');

-- =============================================================================
-- 8. opening_stock (15 SKU varian; Total = 1.500 pcs, Valuasi = Rp9.628.900)
--    WH-PKU = Rp4.997.000 | STORE-PKU = Rp2.979.100 | STORE-DUM = Rp1.652.800
-- =============================================================================
INSERT INTO opening_stock (variant_sku, wh_pku, store_pku, store_dum, company_total_pcs, standard_cost, standard_value) VALUES
('AIR-600',      80, 40, 20, 140,  2500,  350000),
('AIR-1500',     50, 30, 20, 100,  5000,  500000),
('MIL-COK-200',  50, 24, 12,  86,  4200,  361200),
('MIL-STR-200',  50, 24, 12,  86,  4200,  361200),
('MIL-PLN-1L',   20, 10,  8,  38, 17500,  665000),
('BIS-COK-120',  50, 25, 10,  85,  6000,  510000),
('BIS-VAN-120',  50, 25, 10,  85,  6000,  510000),
('MIE-GOR-80',  120, 60, 40, 220,  2800,  616000),
('MIE-KUA-80',  120, 50, 30, 200,  2800,  560000),
('KOP-SCH-20',  100, 80, 40, 220,  1900,  418000),
('GUL-1KG',      30, 20, 10,  60, 13000,  780000),
('BER-5KG',      15, 10,  5,  30, 60000, 1800000),
('MIN-1L',       30, 20, 10,  60, 14500,  870000),
('SAB-450',      20, 15, 10,  45, 14000,  630000),
('DET-800',      20, 15, 10,  45, 15500,  697500);

-- Turunkan stok per lokasi ke stock_by_location.
-- Catatan baseline: SO S00007 mereservasi 8 pcs AIR-600 di WH-PKU (On Hand 80, Reserved 8, Available 72).
INSERT INTO stock_by_location (location_code, variant_sku, on_hand_qty, reserved_qty, standard_cost)
SELECT
    'WH-PKU',
    variant_sku,
    wh_pku,
    CASE WHEN variant_sku = 'AIR-600' THEN 8 ELSE 0 END,
    standard_cost
FROM opening_stock
UNION ALL
SELECT
    'STORE-PKU',
    variant_sku,
    store_pku,
    0,
    standard_cost
FROM opening_stock
UNION ALL
SELECT
    'STORE-DUM',
    variant_sku,
    store_dum,
    0,
    standard_cost
FROM opening_stock;

-- =============================================================================
-- 9. chart_of_accounts & Jurnal Pembukaan (Total Debit = Credit = Rp25.716.900)
--    Debit : Inventory (1130) Rp9.628.900
--            AR (1100)        Rp888.000
--            Bank (1010)      Rp15.000.000
--            POS Cash (1020)  Rp200.000
--    Kredit: AP (2010)        Rp555.000
--            Equity (3010)    Rp25.161.900
-- =============================================================================
INSERT INTO chart_of_accounts (account_code, account_name, type, opening_debit, opening_credit) VALUES
('1010', 'Bank',                     'Asset',                15000000,        0),
('1020', 'POS Cash Drawer',          'Asset',                  200000,        0),
('1100', 'Trade Receivables',        'Asset',                  888000,        0),
('1130', 'Stock Valuation',          'Asset',                 9628900,        0),
('1140', 'Input VAT',                'Asset',                       0,        0),
('1150', 'Stock Interim Received',   'Liability / clearing',        0,        0),
('1190', 'POS Settlement Clearing',  'Asset / clearing',            0,        0),
('2010', 'Accounts Payable',         'Liability',                   0,   555000),
('2030', 'Output VAT',               'Liability',                   0,        0),
('3010', 'Opening Equity',           'Equity',                      0, 25161900),
('4010', 'B2B Sales Revenue',        'Revenue',                     0,        0),
('4020', 'POS Sales Revenue',        'Revenue',                     0,        0),
('4030', 'Sales Returns',            'Contra Revenue',              0,        0),
('5010', 'COGS',                     'Expense',                     0,        0),
('5110', 'Purchase Price Variance',  'Expense',                     0,        0),
('5130', 'Cash Over / Short',        'Expense or income',           0,        0),
('5140', 'Stock Write-off',          'Expense',                     0,        0);

-- Header & Baris Jurnal Pembukaan (bukan transaksi uji)
INSERT INTO journal_entries (entry_number, entry_date, source_document, description, status, is_opening_entry, total_debit, total_credit) VALUES
('JE-OPEN-0001', '2026-01-01', 'OPENING-BALANCE', 'Jurnal pembukaan saldo awal PT RantauMart Riau', 'Posted', TRUE, 25716900, 25716900);

INSERT INTO journal_entry_lines (entry_number, account_code, partner_id, debit, credit, memo) VALUES
('JE-OPEN-0001', '1130', NULL,   9628900,        0, 'Saldo awal Stock Valuation (1.500 pcs)'),
('JE-OPEN-0001', '1100', 'C002',  888000,        0, 'Saldo awal Trade Receivables (AR C002)'),
('JE-OPEN-0001', '1010', NULL,  15000000,        0, 'Saldo awal Bank'),
('JE-OPEN-0001', '1020', NULL,    200000,        0, 'Saldo awal POS Cash Drawer'),
('JE-OPEN-0001', '2010', 'V01',        0,   555000, 'Saldo awal Accounts Payable (AP V01)'),
('JE-OPEN-0001', '3010', NULL,         0, 25161900, 'Saldo awal Opening Equity');

-- =============================================================================
-- 10. existing_documents & Dokumen Operasional Awal
-- =============================================================================
INSERT INTO existing_documents (document_number, document_type, partner_lines, status, stock_and_financial_state) VALUES
('LEAD-0001', 'Lead',           'C001',                     'New / not converted',                      'No AR or stock changes'),
('S00007',    'Sales Order',    'C001; 8 AIR-600 @4.000',   'Confirmed / not delivered / not invoiced', 'Gross Rp35.520; WH-PKU Reserved 8 pcs; no AR yet'),
('PO00007',   'Purchase Order', 'V01; 30 MIE-GOR-80 @3.000','Confirmed / not received',                 'Gross Rp99.900; no AP or stock yet');

INSERT INTO crm_leads (lead_number, customer_id, expected_sku, expected_qty, stage, converted_opportunity_id, status) VALUES
('LEAD-0001', 'C001', 'AIR-600', 20, 'New', NULL, 'New / not converted');

INSERT INTO sales_orders (so_number, customer_id, source_location, source_lead, status, subtotal_net, tax_amount, total_gross, price_locked) VALUES
('S00007', 'C001', 'WH-PKU', NULL, 'Confirmed', 32000, 3520, 35520, TRUE);

INSERT INTO sales_order_lines (so_number, variant_sku, ordered_qty, delivered_qty, invoiced_qty, unit_price, discount_pct, tax_code, line_subtotal, line_tax, line_gross) VALUES
('S00007', 'AIR-600', 8, 0, 0, 4000, 0, 'PPN-11', 32000, 3520, 35520);

INSERT INTO purchase_orders (po_number, vendor_id, destination_location, status, subtotal_net, tax_amount, total_gross, price_locked) VALUES
('PO00007', 'V01', 'WH-PKU', 'Confirmed', 90000, 9900, 99900, TRUE);

INSERT INTO purchase_order_lines (po_number, variant_sku, ordered_qty, received_qty, accepted_qty, rejected_qty, billed_qty, unit_price, tax_code, line_subtotal, line_tax, line_gross) VALUES
('PO00007', 'MIE-GOR-80', 30, 0, 0, 0, 0, 3000, 'PPN-11', 90000, 9900, 99900);

INSERT INTO document_sequences (prefix, last_number, padding) VALUES
('LEAD-',        1, 4),
('OPP-',         0, 4),
('S',            7, 5),
('PO',           7, 5),
('DO-',          0, 4),
('WH-IN-',       0, 4),
('INT-',         0, 4),
('BILL-',        0, 4),
('INV-',         0, 4),
('PAY-',         0, 4),
('POS-SESSION-', 0, 4),
('POS-RCPT-',    0, 4),
('RET-',         0, 4),
('CN-',          0, 4),
('JE-',          1, 4);

-- =============================================================================
-- 11. app_users (6 Pemangku Kepentingan / Stakeholders Sesuai F-01 & SR-18)
-- =============================================================================
INSERT INTO app_users (user_id, username, password_plain, full_name, role, assigned_location) VALUES
('U01', 'sales',       'sales123',       'Putri Aulia',     'Sales',       'WH-PKU'),
('U02', 'warehouse',   'warehouse123',   'Deni Pratama',    'Warehouse',   'WH-PKU'),
('U03', 'procurement', 'procurement123', 'Mira Salsabila',  'Procurement', 'WH-PKU'),
('U04', 'finance',     'finance123',     'Arif Firmansyah', 'Finance',     'WH-PKU'),
('U05', 'cashier',     'cashier123',     'Rani Oktaviani',  'Cashier',     'STORE-PKU'),
('U06', 'owner',       'owner123',       'Budi Haryanto',   'Owner',       'WH-PKU');

-- =============================================================================
-- 12. Verifikasi Integritas Baseline Seed (Assertions)
-- =============================================================================
DO $$
DECLARE
    v_sku_count INTEGER;
    v_cust_count INTEGER;
    v_vend_count INTEGER;
    v_loc_count INTEGER;
    v_total_pcs INTEGER;
    v_total_val NUMERIC(15, 2);
    v_wh_pku_val NUMERIC(15, 2);
    v_store_pku_val NUMERIC(15, 2);
    v_store_dum_val NUMERIC(15, 2);
    v_coa_debit NUMERIC(15, 2);
    v_coa_credit NUMERIC(15, 2);
BEGIN
    SELECT COUNT(*) INTO v_sku_count FROM products;
    IF v_sku_count <> 15 THEN
        RAISE EXCEPTION 'Seed error: products count = %, expected 15', v_sku_count;
    END IF;

    SELECT COUNT(*) INTO v_cust_count FROM contacts_customers;
    IF v_cust_count <> 6 THEN
        RAISE EXCEPTION 'Seed error: contacts_customers count = %, expected 6', v_cust_count;
    END IF;

    SELECT COUNT(*) INTO v_vend_count FROM contacts_vendors;
    IF v_vend_count <> 4 THEN
        RAISE EXCEPTION 'Seed error: contacts_vendors count = %, expected 4', v_vend_count;
    END IF;

    SELECT COUNT(*) INTO v_loc_count FROM warehouses_locations;
    IF v_loc_count <> 3 THEN
        RAISE EXCEPTION 'Seed error: warehouses_locations count = %, expected 3', v_loc_count;
    END IF;

    SELECT SUM(company_total_pcs), SUM(standard_value)
    INTO v_total_pcs, v_total_val
    FROM opening_stock;

    IF v_total_pcs <> 1500 OR v_total_val <> 9628900 THEN
        RAISE EXCEPTION 'Seed error: opening_stock total pcs = % (expected 1500), value = % (expected 9628900)', v_total_pcs, v_total_val;
    END IF;

    SELECT SUM(valuation) INTO v_wh_pku_val FROM stock_by_location WHERE location_code = 'WH-PKU';
    SELECT SUM(valuation) INTO v_store_pku_val FROM stock_by_location WHERE location_code = 'STORE-PKU';
    SELECT SUM(valuation) INTO v_store_dum_val FROM stock_by_location WHERE location_code = 'STORE-DUM';

    IF v_wh_pku_val <> 4997000 OR v_store_pku_val <> 2979100 OR v_store_dum_val <> 1652800 THEN
        RAISE EXCEPTION 'Seed error: per-location valuation mismatch (WH-PKU=%, STORE-PKU=%, STORE-DUM=%)',
            v_wh_pku_val, v_store_pku_val, v_store_dum_val;
    END IF;

    SELECT SUM(opening_debit), SUM(opening_credit)
    INTO v_coa_debit, v_coa_credit
    FROM chart_of_accounts;

    IF v_coa_debit <> 25716900 OR v_coa_credit <> 25716900 THEN
        RAISE EXCEPTION 'Seed error: chart_of_accounts opening balance mismatch (Debit=%, Credit=%, expected 25716900)',
            v_coa_debit, v_coa_credit;
    END IF;
END $$;

COMMIT;
