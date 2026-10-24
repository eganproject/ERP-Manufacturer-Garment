@once
<script>
    /**
     * Komponen Alpine untuk tabel baris dinamis (BOM, PO, SO).
     * rows: data awal, blank: template baris baru, prices: map id => harga default.
     */
    function lineItems(rows, blank, prices = {}) {
        let key = 0;
        return {
            rows: rows.length ? rows.map(r => ({ ...r, key: key++ })) : [{ ...blank, key: key++ }],
            prices,
            discount: 0,
            add() {
                this.rows.push({ ...blank, key: key++ });
            },
            remove(index) {
                this.rows.splice(index, 1);
            },
            pick(row, id, priceInput) {
                row.id = id;
                if (this.prices[id] !== undefined && (!row.price || Number(row.price) === 0)) {
                    row.price = this.prices[id];
                    ERP.setMoney(priceInput, row.price);
                }
            },
            subtotal(row) {
                return (Number(row.quantity) || 0) * (Number(row.price) || 0);
            },
            get total() {
                return this.rows.reduce((sum, row) => sum + this.subtotal(row), 0);
            },
            get grandTotal() {
                return Math.max(0, this.total - (Number(this.discount) || 0));
            },
            money(value) {
                return 'Rp ' + ERP.formatNumber(value);
            },
        };
    }
</script>
@endonce
