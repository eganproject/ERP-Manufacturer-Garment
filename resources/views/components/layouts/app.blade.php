@props(['title' => null, 'subtitle' => null])

@php
    // Menu dikelompokkan mengikuti alur kerja: jual → marketplace → beli → produksi → stok → keuangan → aset tetap → akuntansi
    $menu = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ['heading' => 'Penjualan', 'items' => [
            ['label' => 'Penawaran Harga', 'can' => 'sales.view', 'route' => 'quotations.index', 'active' => 'quotations.*', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['label' => 'Sales Order (SO)', 'can' => 'sales.view', 'route' => 'sales-orders.index', 'active' => 'sales-orders.*', 'icon' => 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z'],
            ['label' => 'Retur Penjualan', 'can' => 'sales.view', 'route' => 'sales-returns.index', 'active' => 'sales-returns.*', 'icon' => 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6'],
        ]],
        ['heading' => 'Marketplace', 'items' => [
            ['label' => 'Pesanan Marketplace', 'can' => 'marketplace.view', 'route' => 'marketplace-orders.index', 'active' => 'marketplace-orders.*', 'icon' => 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z'],
            ['label' => 'Pencairan Dana', 'can' => 'marketplace.view', 'route' => 'marketplace-settlements.index', 'active' => 'marketplace-settlements.*', 'icon' => 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
            ['label' => 'Stok Marketplace', 'can' => 'marketplace.view', 'route' => 'marketplace-stock.index', 'active' => 'marketplace-stock.*', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
            ['label' => 'Mapping SKU', 'can' => 'marketplace.view', 'route' => 'marketplace-mappings.index', 'active' => 'marketplace-mappings.*', 'icon' => 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1'],
            ['label' => 'Toko Marketplace', 'can' => 'marketplace.view', 'route' => 'marketplace-stores.index', 'active' => 'marketplace-stores.*', 'icon' => 'M3 3h18v4H3V3zm1 4v13h16V7M9 11h6'],
        ]],
        ['heading' => 'Pembelian', 'items' => [
            ['label' => 'MRP / Kebutuhan Bahan', 'can' => 'mrp.view', 'route' => 'mrp.index', 'active' => 'mrp.*', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['label' => 'Purchase Order (PO)', 'can' => 'purchase.view', 'route' => 'purchase-orders.index', 'active' => 'purchase-orders.*', 'icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
            ['label' => 'Retur Pembelian', 'can' => 'purchase.view', 'route' => 'purchase-returns.index', 'active' => 'purchase-returns.*', 'icon' => 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6'],
        ]],
        ['heading' => 'Produksi', 'items' => [
            ['label' => 'Rencana Produksi', 'can' => 'production.view', 'route' => 'production-plan.index', 'active' => 'production-plan.*', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['label' => 'SPK Produksi', 'can' => 'production.view', 'route' => 'production-orders.index', 'active' => 'production-orders.*', 'icon' => 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z'],
            ['label' => 'Maklon (Vendor Luar)', 'can' => 'production.view', 'route' => 'subcontract-orders.index', 'active' => 'subcontract-orders.*', 'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
            ['label' => 'Bill of Materials', 'can' => 'boms.manage', 'route' => 'boms.index', 'active' => 'boms.*', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'],
        ]],
        ['heading' => 'Upah Borongan', 'items' => [
            ['label' => 'Hasil Kerja Harian', 'can' => 'payroll.view', 'route' => 'piecework.index', 'active' => 'piecework.*', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'],
            ['label' => 'Penggajian Borongan', 'can' => 'payroll.view', 'route' => 'payrolls.index', 'active' => 'payrolls.*', 'icon' => 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
            ['label' => 'Karyawan Produksi', 'can' => 'payroll.view', 'route' => 'employees.index', 'active' => 'employees.*', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
            ['label' => 'Tarif Borongan', 'can' => 'employees.manage', 'route' => 'piece-rates.index', 'active' => 'piece-rates.*', 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
        ]],
        ['heading' => 'Persediaan', 'items' => [
            ['label' => 'Stok Bahan Baku', 'can' => 'stock.view', 'route' => 'stock.materials', 'active' => 'stock.materials*', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
            ['label' => 'Stok Barang Jadi', 'can' => 'stock.view', 'route' => 'stock.products', 'active' => 'stock.products*', 'icon' => 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4'],
            ['label' => 'Barang Cacat', 'can' => 'stock.view', 'route' => 'defects.index', 'active' => 'defects.*', 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'],
            ['label' => 'Transfer Antar Gudang', 'can' => 'stock.view', 'route' => 'stock-transfers.index', 'active' => 'stock-transfers.*', 'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
            ['label' => 'Stock Opname', 'can' => 'stock.view', 'route' => 'stock-opnames.index', 'active' => 'stock-opnames.*', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'],
            ['label' => 'Gudang', 'can' => 'stock.view', 'route' => 'warehouses.index', 'active' => 'warehouses.*', 'icon' => 'M3 21h18M5 21V8l7-5 7 5v13M9 21v-6h6v6'],
            ['label' => 'Penyesuaian Stok', 'can' => 'stock.adjust', 'route' => 'stock.adjustment', 'active' => 'stock.adjustment*', 'icon' => 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4'],
        ]],
        ['heading' => 'Keuangan', 'items' => [
            ['label' => 'Invoice Penjualan', 'can' => 'receivables.view', 'route' => 'invoices.index', 'active' => 'invoices.*', 'icon' => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z'],
            ['label' => 'Penerimaan Pelanggan', 'can' => 'receivables.view', 'route' => 'receipts.index', 'active' => 'receipts.*', 'icon' => 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
            ['label' => 'Tagihan Supplier', 'can' => 'payables.view', 'route' => 'bills.index', 'active' => 'bills.*', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['label' => 'Pembayaran Supplier', 'can' => 'payables.view', 'route' => 'disbursements.index', 'active' => 'disbursements.*', 'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
            ['label' => 'Kas & Bank', 'can' => 'cashbank.view', 'route' => 'cash-bank.index', 'active' => ['cash-bank.*', 'cash-transactions.*'], 'icon' => 'M3 6l9-3 9 3M4 10h16M6 10v8m4-8v8m4-8v8m4-8v8M3 21h18'],
        ]],
        ['heading' => 'Aset Tetap', 'items' => [
            ['label' => 'Daftar Aset Tetap', 'can' => 'assets.view', 'route' => 'fixed-assets.index', 'active' => 'fixed-assets.*', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
            ['label' => 'Penyusutan Bulanan', 'can' => 'assets.view', 'route' => 'depreciation-runs.index', 'active' => 'depreciation-runs.*', 'icon' => 'M13 17h8m0 0V9m0 8l-8-8-4 4-6-6'],
            ['label' => 'Kategori Aset', 'can' => 'assets.view', 'route' => 'asset-categories.index', 'active' => 'asset-categories.*', 'icon' => 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z'],
        ]],
        ['heading' => 'Akuntansi', 'items' => [
            ['label' => 'Jurnal Umum', 'can' => 'accounting.view', 'route' => 'journals.index', 'active' => 'journals.*', 'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253'],
            ['label' => 'Buku Besar', 'can' => 'accounting.view', 'route' => 'reports.ledger', 'active' => 'reports.ledger', 'icon' => 'M4 6h16M4 10h16M4 14h16M4 18h16'],
            ['label' => 'Neraca Saldo', 'can' => 'accounting.view', 'route' => 'reports.trial-balance', 'active' => 'reports.trial-balance', 'icon' => 'M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3'],
            ['label' => 'Laba Rugi', 'can' => 'accounting.view', 'route' => 'reports.profit-loss', 'active' => 'reports.profit-loss', 'icon' => 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'],
            ['label' => 'Neraca', 'can' => 'accounting.view', 'route' => 'reports.balance-sheet', 'active' => 'reports.balance-sheet', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['label' => 'Arus Kas', 'can' => 'accounting.view', 'route' => 'reports.cash-flow', 'active' => 'reports.cash-flow', 'icon' => 'M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4'],
            ['label' => 'Anggaran', 'can' => 'budget.view', 'route' => 'budgets.index', 'active' => 'budgets.*', 'icon' => 'M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z'],
            ['label' => 'Bagan Akun', 'can' => 'accounting.view', 'route' => 'accounts.index', 'active' => 'accounts.*', 'icon' => 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z'],
            ['label' => 'Tutup Buku', 'can' => 'accounting.close', 'route' => 'periods.index', 'active' => 'periods.*', 'icon' => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'],
        ]],
        ['heading' => 'Laporan', 'items' => [
            ['label' => 'Laporan Penjualan', 'can' => 'receivables.view', 'route' => 'reports.sales', 'active' => 'reports.sales', 'icon' => 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'],
            ['label' => 'Laporan Marketplace', 'can' => 'marketplace.view', 'route' => 'reports.marketplace', 'active' => 'reports.marketplace', 'icon' => 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'],
            ['label' => 'Laporan Pembelian', 'can' => 'payables.view', 'route' => 'reports.purchases', 'active' => 'reports.purchases', 'icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
            ['label' => 'Laporan Produksi', 'can' => 'production.view', 'route' => 'reports.production', 'active' => 'reports.production', 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
            ['label' => 'Laporan Maklon', 'can' => 'production.view', 'route' => 'reports.subcontract', 'active' => 'reports.subcontract', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'],
            ['label' => 'Laporan Upah Borongan', 'can' => 'payroll.view', 'route' => 'reports.labor', 'active' => 'reports.labor', 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
            ['label' => 'Nilai Persediaan', 'can' => 'costing.view', 'route' => 'reports.stock-valuation', 'active' => 'reports.stock-valuation', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
            ['label' => 'HPP & Margin Produk', 'can' => 'costing.view', 'route' => 'reports.costing', 'active' => 'reports.costing', 'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['label' => 'Laporan PPN', 'can' => 'tax.view', 'route' => 'reports.vat', 'active' => 'reports.vat', 'icon' => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z'],
            ['label' => 'Umur Piutang', 'can' => 'receivables.view', 'route' => 'reports.receivables-aging', 'active' => 'reports.receivables-aging', 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
            ['label' => 'Umur Hutang', 'can' => 'payables.view', 'route' => 'reports.payables-aging', 'active' => 'reports.payables-aging', 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
        ]],
        ['heading' => 'Master Data', 'items' => [
            ['label' => 'Produk', 'can' => 'products.manage', 'route' => 'products.index', 'active' => 'products.*', 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
            ['label' => 'Bahan Baku', 'can' => 'materials.manage', 'route' => 'materials.index', 'active' => 'materials.*', 'icon' => 'M4 6h16M4 10h16M4 14h16M4 18h16'],
            ['label' => 'Pelanggan', 'can' => 'customers.manage', 'route' => 'customers.index', 'active' => 'customers.*', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
            ['label' => 'Supplier', 'can' => 'suppliers.manage', 'route' => 'suppliers.index', 'active' => 'suppliers.*', 'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
            ['label' => 'Kategori', 'can' => 'master.basic', 'route' => 'categories.index', 'active' => 'categories.*', 'icon' => 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z'],
            ['label' => 'Satuan', 'can' => 'master.basic', 'route' => 'units.index', 'active' => 'units.*', 'icon' => 'M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3'],
            ['label' => 'Impor Data (Excel)', 'can' => 'imports.use', 'route' => 'imports.index', 'active' => 'imports.*', 'icon' => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12'],
        ]],
        ['heading' => 'Administrasi', 'items' => [
            ['label' => 'Pengguna & Hak Akses', 'can' => 'users.manage', 'route' => 'users.index', 'active' => 'users.*', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
            ['label' => 'Pengaturan Perusahaan', 'can' => 'settings.manage', 'route' => 'settings.edit', 'active' => 'settings.*', 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
            ['label' => 'Log Aktivitas', 'can' => 'activity.view', 'route' => 'activity-logs.index', 'active' => 'activity-logs.*', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
        ]],
    ];

    // Sembunyikan menu yang tidak boleh diakses, lalu buang grup yang kosong
    $user = auth()->user();
    $allowed = fn ($item) => ! isset($item['can']) || $user->can($item['can']);
    $menu = collect($menu)
        ->map(fn ($entry) => isset($entry['heading']) ? array_merge($entry, ['items' => array_values(array_filter($entry['items'], $allowed))]) : $entry)
        ->filter(fn ($entry) => isset($entry['heading']) ? count($entry['items']) > 0 : $allowed($entry))
        ->map(function ($entry) {
            if (isset($entry['heading'])) {
                $entry['active'] = collect($entry['items'])->contains(fn ($item) => request()->routeIs($item['active']));
            }

            return $entry;
        })
        ->values()
        ->all();

    $validationHtml = $errors->any()
        ? '<ul style="text-align:left">'.collect($errors->all())->unique()->take(8)->map(fn ($e) => '<li>• '.e($e).'</li>')->implode('').'</ul>'
        : null;

    // Grup menu yang sedang dibuka (untuk breadcrumb)
    $currentGroup = collect($menu)->first(fn ($entry) => isset($entry['heading']) && $entry['active'])['heading'] ?? null;

    // Daftar untuk pencarian cepat (Ctrl+K): semua menu + aksi "buat baru" yang diizinkan
    $quickActions = collect([
        ['Buat Penawaran Harga', 'quotations.create', 'sales.manage'],
        ['Buat Sales Order', 'sales-orders.create', 'sales.manage'],
        ['Buat Purchase Order', 'purchase-orders.create', 'purchase.manage'],
        ['Buat SPK Produksi', 'production-orders.create', 'production.manage'],
        ['Buat Order Maklon', 'subcontract-orders.create', 'subcontract.manage'],
        ['Catat Hasil Kerja Borongan', 'piecework.create', 'piecework.record'],
        ['Catat Penerimaan Pembayaran', 'receipts.create', 'receivables.manage'],
        ['Catat Pembayaran Supplier', 'disbursements.create', 'payables.manage'],
        ['Buat Bukti Kas / Bank', 'cash-transactions.create', 'cashbank.manage'],
        ['Buat Jurnal Umum', 'journals.create', 'accounting.manage'],
        ['Transfer Stok Antar Gudang', 'stock-transfers.create', 'stock.transfer'],
        ['Impor Data dari Excel', 'imports.index', 'imports.use'],
    ])->filter(fn ($a) => \Illuminate\Support\Facades\Route::has($a[1]) && $user->can($a[2]))
        ->map(fn ($a) => ['label' => $a[0], 'group' => 'Aksi cepat', 'url' => route($a[1]), 'icon' => 'M12 4v16m8-8H4']);
    $paletteItems = collect($menu)
        ->flatMap(fn ($entry) => isset($entry['heading'])
            ? collect($entry['items'])->map(fn ($item) => ['label' => $item['label'], 'group' => $entry['heading'], 'url' => route($item['route']), 'icon' => $item['icon']])
            : [['label' => $entry['label'], 'group' => 'Menu', 'url' => route($entry['route']), 'icon' => $entry['icon']]])
        ->concat($quickActions)
        ->values();

    $company = \App\Models\Setting::company();
    $initials = collect(preg_split('/\s+/', trim(auth()->user()->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased" x-data="{ sidebarOpen: false }" @keydown.window.escape="sidebarOpen = false">
    <div id="flash-messages" class="hidden"
         data-success="{{ session('success') }}"
         data-error="{{ session('error') }}"
         data-validation="{{ $validationHtml }}"></div>

    {{-- Overlay mobile --}}
    <div x-cloak x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-30 bg-gray-950/40 backdrop-blur-[2px] lg:hidden" @click="sidebarOpen = false"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-[17rem] -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform duration-200 lg:translate-x-0"
           :class="{ 'translate-x-0 shadow-pop': sidebarOpen, '-translate-x-full': !sidebarOpen }">
        <div class="flex h-16 shrink-0 items-center gap-3 px-5">
            @if ($company['logo'])
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($company['logo']) }}" alt="" class="h-9 w-9 rounded-lg border border-gray-200 bg-white object-contain p-0.5">
            @else
                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 text-white shadow-xs">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3L4 6v4l3 1v10h10V11l3-1V6l-5-3a3 3 0 01-6 0z"/></svg>
                </div>
            @endif
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-gray-900">{{ $company['name'] }}</p>
                <p class="truncate text-xs text-gray-500">ERP Manufaktur Pakaian</p>
            </div>
        </div>

        <div class="px-3 pb-2">
            <button type="button" @click="$dispatch('open-palette')" class="flex h-9 w-full items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 text-sm text-gray-400 transition hover:border-gray-300 hover:bg-white">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                <span class="flex-1 text-left">Cari menu…</span>
                <span class="kbd">Ctrl K</span>
            </button>
        </div>

        <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 pt-1 pb-4" x-data="{ collapsed: (() => { try { return JSON.parse(localStorage.getItem('erp.menu.collapsed') || '{}'); } catch (e) { return {}; } })(), toggle(key) { this.collapsed[key] = ! this.collapsed[key]; try { localStorage.setItem('erp.menu.collapsed', JSON.stringify(this.collapsed)); } catch (e) {} } }"
             x-init="$nextTick(() => $el.querySelector('[aria-current=page]')?.scrollIntoView({ block: 'center' }))">
            @foreach ($menu as $entry)
                @if (isset($entry['heading']))
                    @php $key = \Illuminate\Support\Str::slug($entry['heading']); @endphp
                    <div class="pt-4">
                        <button type="button" class="group flex w-full items-center justify-between rounded-md px-2.5 pb-1.5 text-[0.6875rem] font-semibold tracking-wider text-gray-400 uppercase transition hover:text-gray-700"
                                @click="toggle('{{ $key }}')" :aria-expanded="{{ $entry['active'] ? 'true' : "! collapsed['{$key}']" }}">
                            {{ $entry['heading'] }}
                            <svg class="h-3.5 w-3.5 opacity-0 transition group-hover:opacity-100" :class="{{ $entry['active'] ? 'false' : "collapsed['{$key}']" }} ? '-rotate-90 opacity-100' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div class="space-y-0.5" @unless ($entry['active']) x-show="! collapsed['{{ $key }}']" x-collapse.duration.150ms @endunless>
                            @foreach ($entry['items'] as $item)
                                @include('partials.menu-link', ['item' => $item])
                            @endforeach
                        </div>
                    </div>
                @else
                    @include('partials.menu-link', ['item' => $entry])
                @endif
            @endforeach
        </nav>

        {{-- Pengguna --}}
        <div class="relative shrink-0 border-t border-gray-200 p-3" x-data="{ open: false }" @click.outside="open = false">
            <button type="button" class="flex w-full items-center gap-3 rounded-lg p-2 text-left transition hover:bg-gray-100" @click="open = !open">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-400 to-indigo-700 text-xs font-semibold text-white">{{ $initials }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-gray-900">{{ auth()->user()->name }}</span>
                    <span class="block truncate text-xs text-gray-500">{{ auth()->user()->role->label() }}</span>
                </span>
                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/></svg>
            </button>
            <div x-cloak x-show="open" x-transition.origin.bottom class="absolute right-3 bottom-full left-3 mb-1 overflow-hidden rounded-xl border border-gray-200 bg-white p-1 shadow-pop">
                <p class="truncate px-3 pt-2 pb-1 text-xs text-gray-500">{{ auth()->user()->email }}</p>
                <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-100">
                    <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    Profil & Password
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100">
                        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        Keluar
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <div class="flex min-h-full flex-col lg:pl-[17rem]">
        {{-- Topbar --}}
        <header class="sticky top-0 z-20 border-b border-gray-200/70 bg-white/75 backdrop-blur-md supports-[backdrop-filter]:bg-white/60">
            <div class="mx-auto flex h-14 max-w-[1600px] items-center gap-3 px-4 sm:px-6 lg:px-8">
                <button type="button" class="-ml-1 rounded-lg p-2 text-gray-500 hover:bg-gray-100 lg:hidden" @click="sidebarOpen = true" aria-label="Buka menu">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <nav aria-label="Breadcrumb" class="flex min-w-0 flex-1 items-center gap-1.5 text-sm">
                    <a href="{{ route('dashboard') }}" class="text-gray-400 hover:text-gray-700" aria-label="Dashboard">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10.5L12 3l9 7.5V20a1 1 0 01-1 1h-5v-6h-6v6H4a1 1 0 01-1-1v-9.5z"/></svg>
                    </a>
                    @if ($currentGroup)
                        <svg class="h-3.5 w-3.5 shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        <span class="hidden shrink-0 text-gray-500 sm:inline">{{ $currentGroup }}</span>
                    @endif
                    @if ($title && $title !== 'Dashboard')
                        <svg class="hidden h-3.5 w-3.5 shrink-0 text-gray-300 sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        <span class="truncate font-medium text-gray-900">{{ $title }}</span>
                    @endif
                </nav>
                <button type="button" @click="$dispatch('open-palette')" class="btn-ghost btn-icon sm:hidden" aria-label="Cari menu">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                </button>
                <span class="hidden text-sm text-gray-500 md:block">{{ now()->translatedFormat('l, j F Y') }}</span>
            </div>
        </header>

        <main class="page-enter mx-auto w-full max-w-[1600px] flex-1 px-4 py-5 sm:px-6 lg:px-8 lg:py-6">
            {{-- Judul halaman + aksi utama --}}
            @if ($title || isset($actions))
                <div class="mb-5 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                    <div class="min-w-0">
                        @if ($title)<h1 class="text-xl font-semibold tracking-tight text-gray-900">{{ $title }}</h1>@endif
                        @if ($subtitle)<p class="mt-1 text-sm text-gray-500">{{ $subtitle }}</p>@endif
                    </div>
                    @isset($actions)
                        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
                    @endisset
                </div>
            @endif

            {{ $slot }}
        </main>

        <footer class="mx-auto w-full max-w-[1600px] px-4 pb-6 text-xs text-gray-400 sm:px-6 lg:px-8">
            {{ $company['name'] }} · {{ config('app.name') }}
        </footer>
    </div>

    {{-- Pencarian cepat menu & aksi (Ctrl/⌘ + K) --}}
    <div x-cloak x-data="commandPalette(@js($paletteItems))" x-show="open"
         @open-palette.window="show()" @keydown.window="hotkey($event)"
         class="fixed inset-0 z-[60] flex items-start justify-center p-4 pt-[12vh]" role="dialog" aria-modal="true" aria-label="Cari menu">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-gray-950/40 backdrop-blur-[2px]" @click="open = false"></div>
        <div x-show="open" x-transition.scale.98.origin.top class="relative w-full max-w-xl overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-pop">
            <div class="flex items-center gap-3 border-b border-gray-100 px-4">
                <svg class="h-5 w-5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                <input x-ref="input" x-model="query" @input="cursor = 0" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="go()"
                       type="text" placeholder="Ketik nama menu atau aksi, mis. &quot;invoice&quot;…" class="h-14 w-full border-0 bg-transparent p-0 text-[0.9375rem] text-gray-900 placeholder:text-gray-400 focus:ring-0">
                <span class="kbd">Esc</span>
            </div>
            <ul class="max-h-[55vh] overflow-y-auto p-2" x-ref="list">
                <template x-for="(item, i) in results" :key="item.url + item.label">
                    <li>
                        <template x-if="i === 0 || results[i - 1].group !== item.group">
                            <p class="px-3 pt-2 pb-1 text-[0.6875rem] font-semibold tracking-wider text-gray-400 uppercase" x-text="item.group"></p>
                        </template>
                        <a :href="item.url" @mouseenter="cursor = i" :data-active="cursor === i"
                           class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-gray-700 data-[active=true]:bg-gray-100 data-[active=true]:text-gray-900">
                            <svg class="h-4.5 w-4.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" :d="item.icon"/></svg>
                            <span class="flex-1 truncate" x-text="item.label"></span>
                            <svg x-show="cursor === i" class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 10l-5 5 5 5M20 4v7a4 4 0 01-4 4H4"/></svg>
                        </a>
                    </li>
                </template>
                <li x-show="results.length === 0" class="px-3 py-10 text-center text-sm text-gray-500">Tidak ada menu yang cocok.</li>
            </ul>
            <div class="flex items-center gap-4 border-t border-gray-100 bg-gray-50/80 px-4 py-2.5 text-xs text-gray-500">
                <span class="flex items-center gap-1"><span class="kbd">↑</span><span class="kbd">↓</span> pilih</span>
                <span class="flex items-center gap-1"><span class="kbd">Enter</span> buka</span>
                <span class="ml-auto hidden sm:inline">Tekan <span class="kbd">Ctrl K</span> kapan saja</span>
            </div>
        </div>
    </div>
</body>
</html>
