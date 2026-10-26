import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import TomSelect from 'tom-select';
import flatpickr from 'flatpickr';
import { Indonesian } from 'flatpickr/dist/l10n/id.js';
import monthSelectPlugin from 'flatpickr/dist/plugins/monthSelect/index.js';
import IMask from 'imask';
import BaseSwal from 'sweetalert2';

window.Alpine = Alpine;
Alpine.plugin(collapse);

flatpickr.localize(Indonesian);

const svg = (path, stroke = 2) =>
    `<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="${stroke}"><path stroke-linecap="round" stroke-linejoin="round" d="${path}"/></svg>`;

const ICONS = {
    warning: svg('M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z', 1.8),
    error: svg('M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 1.8),
    question: svg('M8.23 9a4 4 0 017.54 1.33c0 2.67-4 3.67-4 3.67M12 17h.01', 1.8),
    info: svg('M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 1.8),
    success: svg('M5 13l4 4L19 7', 2.2),
};

/** Dialog bertema ERP: tombol memakai kelas .btn-*, ikon garis, tanpa warna inline. */
const Swal = BaseSwal.mixin({
    buttonsStyling: false,
    reverseButtons: true,
    showClass: { popup: 'swal2-show' },
    customClass: {
        popup: 'erp-swal',
        confirmButton: 'btn-primary erp-swal-confirm',
        cancelButton: 'btn-secondary',
        denyButton: 'btn-danger',
        input: 'form-control',
    },
});
const baseFire = Swal.fire.bind(Swal);
Swal.fire = (options, ...rest) =>
    baseFire(options && typeof options === 'object' && options.icon && !options.iconHtml ? { ...options, iconHtml: ICONS[options.icon] } : options, ...rest);
window.Swal = Swal;

const TOAST_TONES = {
    success: ['bg-emerald-50 text-emerald-600', ICONS.success],
    error: ['bg-red-50 text-red-600', svg('M6 18L18 6M6 6l12 12', 2.2)],
    warning: ['bg-amber-50 text-amber-600', svg('M12 9v4m0 4h.01', 2.4)],
    info: ['bg-indigo-50 text-indigo-600', svg('M12 16v-4m0-4h.01', 2.4)],
};

/** Notifikasi kecil di pojok kanan atas: ERP.toast.fire({ icon: 'success', title: '...' }) */
const toast = {
    fire({ icon = 'info', title = '', text = '', timer = 4000 } = {}) {
        let host = document.querySelector('.erp-toaster');

        if (!host) {
            host = document.createElement('div');
            host.className = 'erp-toaster';
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }

        const [tone, iconSvg] = TOAST_TONES[icon] || TOAST_TONES.info;
        const el = document.createElement('div');
        el.className = 'erp-toast';
        el.dataset.state = 'enter';
        el.setAttribute('role', icon === 'error' ? 'alert' : 'status');
        el.innerHTML = `<span class="erp-toast-icon ${tone}">${iconSvg}</span>
            <div class="min-w-0 flex-1 pt-0.5"><p class="font-medium text-gray-900"></p><p class="mt-0.5 text-gray-500 empty:hidden"></p></div>
            <button type="button" class="-m-1 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="Tutup">${svg('M6 18L18 6M6 6l12 12', 2)}</button>`;
        el.querySelector('p').textContent = title;
        el.querySelectorAll('p')[1].textContent = text;
        el.querySelector('button svg').classList.add('h-4', 'w-4');

        const close = () => {
            el.dataset.state = 'leave';
            setTimeout(() => el.remove(), 300);
        };
        el.querySelector('button').addEventListener('click', close);
        host.appendChild(el);
        requestAnimationFrame(() => requestAnimationFrame(() => (el.dataset.state = 'shown')));
        if (timer) setTimeout(close, timer);
    },
};

/** Format angka gaya Indonesia: 1.234.567,5 */
export function formatNumber(value, decimals = 0) {
    const n = Number(value) || 0;

    return n.toLocaleString('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

const pluginInitializers = {
    // Dropdown dengan pencarian: <select data-select>
    select(el) {
        const options = {
            // Wajib diisi → opsi kosong hanya jadi placeholder; opsional → bisa dikosongkan lagi
            allowEmptyOption: !el.required,
            maxOptions: 500,
            placeholder: el.dataset.placeholder || el.querySelector('option[value=""]')?.textContent.trim() || 'Pilih...',
            plugins: el.multiple ? ['remove_button'] : [],
            // Dropdown ditempel ke body agar tidak terpotong tabel yang bisa digeser (overflow)
            dropdownParent: 'body',
            render: {
                no_results: () => '<div class="no-results p-2 text-gray-500">Data tidak ditemukan</div>',
            },
        };

        if (el.dataset.create !== undefined) {
            options.create = true;
        }

        el._tom = new TomSelect(el, options);
    },

    // Datepicker: <input data-date> (dikirim sebagai Y-m-d, ditampilkan d M Y)
    date(el) {
        el._flatpickr = flatpickr(el, {
            altInput: true,
            altFormat: 'd M Y',
            dateFormat: 'Y-m-d',
            allowInput: true,
            altInputClass: `${el.className} erp-date`,
            disableMobile: true,
            minDate: el.min || undefined,
            maxDate: el.max || undefined,
        });
    },

    // Pilih bulan: <input type="month" data-month> (dikirim sebagai Y-m, ditampilkan "Oktober 2026")
    month(el) {
        const { min, max } = el;
        el.type = 'text';
        el._flatpickr = flatpickr(el, {
            altInput: true,
            altFormat: 'F Y',
            dateFormat: 'Y-m',
            altInputClass: `${el.className} erp-date`,
            disableMobile: true,
            minDate: min || undefined,
            maxDate: max || undefined,
            plugins: [monthSelectPlugin({ shorthand: true, dateFormat: 'Y-m', altFormat: 'F Y' })],
        });
    },

    // Input angka/rupiah: <input data-money> atau <input data-money="2"> (jumlah desimal)
    money(el) {
        const scale = Number(el.dataset.money || 0);
        const raw = el.value;
        const mask = IMask(el, {
            mask: Number,
            scale,
            thousandsSeparator: '.',
            radix: ',',
            mapToRadix: ['.'],
            normalizeZeros: true,
            padFractionalZeros: false,
            min: el.min !== '' ? Number(el.min) : undefined,
        });

        if (raw !== '' && !Number.isNaN(Number(raw))) {
            mask.typedValue = Number(raw);
        }

        mask.on('accept', () => {
            el.dispatchEvent(new CustomEvent('money-change', { detail: mask.unmaskedValue === '' ? '' : Number(mask.unmaskedValue), bubbles: true }));
        });

        el._mask = mask;
    },

    // Nomor telepon: <input data-phone>
    phone(el) {
        el._phoneMask = IMask(el, {
            mask: [{ mask: '0000-0000-00000' }, { mask: '+00 000-0000-00000' }],
        });
    },
};

/** Inisialisasi semua plugin form di dalam elemen root (aman dipanggil berulang). */
export function initPlugins(root = document) {
    const scope = root instanceof Element || root instanceof Document ? root : document;

    // Input bulan bawaan browser selalu memakai pemilih bulan flatpickr (tampilan sama di semua browser)
    scope.querySelectorAll('input[type="month"]:not([data-month])').forEach((el) => (el.dataset.month = ''));

    Object.keys(pluginInitializers).forEach((name) => {
        const selector = `[data-${name}]`;
        const nodes = [...scope.querySelectorAll(selector)];

        if (scope instanceof Element && scope.matches(selector)) {
            nodes.unshift(scope);
        }

        nodes.forEach((el) => {
            if (el.dataset[`${name}Ready`]) return;
            el.dataset[`${name}Ready`] = '1';
            pluginInitializers[name](el);
        });
    });
}

/** Set nilai input ber-mask dari JavaScript. */
export function setMoney(el, value) {
    if (el?._mask) {
        el._mask.typedValue = Number(value) || 0;
        el.dispatchEvent(new CustomEvent('money-change', { detail: Number(value) || 0, bubbles: true }));
    } else if (el) {
        el.value = value;
    }
}

/**
 * Tooltip penjelasan untuk <x-tip> (tombol .tip-trigger[data-tip]).
 * Satu elemen tooltip dipakai bersama; posisi otomatis di atas/bawah dan tidak keluar layar.
 * Mouse: muncul saat hover. Keyboard: saat fokus, Esc menutup. Sentuh: ketuk untuk buka/tutup.
 */
const tips = (() => {
    let el;
    let current = null;
    let pinned = false;
    let hideTimer;
    let showTimer;

    const ensure = () => {
        if (el) return el;
        el = document.createElement('div');
        el.className = 'erp-tip';
        el.id = 'erp-tip';
        el.setAttribute('role', 'tooltip');
        el.dataset.state = 'hidden';
        el.innerHTML = '<span class="erp-tip-title"></span><span class="erp-tip-body"></span><span class="erp-tip-arrow" aria-hidden="true"></span>';
        el.addEventListener('pointerenter', () => clearTimeout(hideTimer));
        el.addEventListener('pointerleave', (e) => e.pointerType === 'mouse' && !pinned && hide(120));
        document.body.appendChild(el);
        return el;
    };

    const place = () => {
        if (!current || !el) return;
        const gap = 10;
        const margin = 12;
        const r = current.getBoundingClientRect();
        const vw = document.documentElement.clientWidth;
        const vh = window.innerHeight;
        const w = el.offsetWidth;
        const h = el.offsetHeight;
        const side = r.top - h - gap >= margin || r.bottom + h + gap > vh - margin ? 'top' : 'bottom';
        const center = r.left + r.width / 2;
        const left = Math.min(Math.max(center - w / 2, margin), vw - w - margin);
        const top = side === 'top' ? Math.max(r.top - h - gap, margin) : r.bottom + gap;

        el.dataset.side = side;
        el.style.left = `${Math.round(left)}px`;
        el.style.top = `${Math.round(top)}px`;
        el.querySelector('.erp-tip-arrow').style.left = `${Math.round(Math.min(Math.max(center - left - 5, 10), w - 20))}px`;
    };

    const show = (trigger) => {
        clearTimeout(hideTimer);
        clearTimeout(showTimer);
        ensure();

        if (current && current !== trigger) current.setAttribute('aria-expanded', 'false');
        current = trigger;

        const title = trigger.dataset.tipTitle || '';
        el.querySelector('.erp-tip-title').textContent = title;
        el.querySelector('.erp-tip-title').hidden = !title;
        el.querySelector('.erp-tip-body').textContent = trigger.dataset.tip;
        trigger.setAttribute('aria-expanded', 'true');
        trigger.setAttribute('aria-describedby', 'erp-tip');
        place();
        el.dataset.state = 'shown';
    };

    const hide = (delay = 0) => {
        clearTimeout(showTimer);
        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => {
            if (!el) return;
            el.dataset.state = 'hidden';
            current?.setAttribute('aria-expanded', 'false');
            current?.removeAttribute('aria-describedby');
            current = null;
            pinned = false;
        }, delay);
    };

    const triggerOf = (target) => (target instanceof Element ? target.closest('.tip-trigger[data-tip]') : null);

    document.addEventListener('pointerover', (e) => {
        const t = triggerOf(e.target);
        if (!t || e.pointerType !== 'mouse' || pinned) return;
        clearTimeout(hideTimer);
        clearTimeout(showTimer);
        showTimer = setTimeout(() => show(t), current ? 0 : 120);
    });

    document.addEventListener('pointerout', (e) => {
        const t = triggerOf(e.target);
        if (!t || e.pointerType !== 'mouse' || pinned) return;
        if (el && e.relatedTarget instanceof Node && el.contains(e.relatedTarget)) return;
        hide(150);
    });

    document.addEventListener('click', (e) => {
        const t = triggerOf(e.target);

        if (t) {
            // Tombol ini sering ada di dalam <label>/<form>: jangan memicu aksi lain
            e.preventDefault();
            e.stopPropagation();
            if (current === t && pinned) {
                hide();
            } else {
                show(t);
                pinned = true;
            }
            return;
        }

        if (current && !(el && el.contains(e.target))) hide();
    }, true);

    document.addEventListener('focusin', (e) => {
        const t = triggerOf(e.target);
        if (t && t.matches(':focus-visible')) show(t);
    });

    document.addEventListener('focusout', (e) => {
        if (triggerOf(e.target) && !pinned) hide(100);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && current) {
            const t = current;
            hide();
            t.focus({ preventScroll: true });
        }
    });

    window.addEventListener('resize', () => current && place());
    window.addEventListener('scroll', () => current && place(), { capture: true, passive: true });

    return { show, hide };
})();

window.ERP = { initPlugins, setMoney, formatNumber, toast, tips };

document.addEventListener('submit', async (event) => {
    const form = event.target;

    // Konfirmasi aksi: <form data-confirm="Yakin?"> atau <button type="submit" data-confirm="...">
    const ask = event.submitter?.dataset.confirm ? event.submitter.dataset : form.dataset;

    if (ask.confirm && !form.dataset.confirmed) {
        event.preventDefault();

        const result = await Swal.fire({
            title: ask.confirmTitle || 'Konfirmasi',
            text: ask.confirm,
            icon: ask.confirmIcon || 'warning',
            showCancelButton: true,
            confirmButtonText: ask.confirmButton || 'Ya, lanjutkan',
            cancelButtonText: 'Batal',
        });

        if (result.isConfirmed) {
            form.dataset.confirmed = '1';
            form.requestSubmit(event.submitter);
        }

        return;
    }

    // Kirim nilai asli (tanpa titik ribuan) untuk input ber-mask
    form.querySelectorAll('[data-money]').forEach((el) => {
        if (el._mask) {
            const value = el._mask.unmaskedValue;
            el._mask.destroy();
            el.value = value;
        }
    });

    // Cegah submit ganda (kecuali form yang membuka tab baru, mis. cetak/ekspor)
    if (form.target === '_blank' || event.defaultPrevented) return;

    form.querySelectorAll('button[type="submit"]').forEach((btn) => {
        btn.disabled = true;
    });

    if (event.submitter && form.method.toLowerCase() === 'post') {
        event.submitter.insertAdjacentHTML('afterbegin', '<svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>');
    }
});

document.addEventListener('DOMContentLoaded', () => {
    initPlugins(document);

    const flash = document.getElementById('flash-messages');

    if (flash) {
        if (flash.dataset.success) toast.fire({ icon: 'success', title: flash.dataset.success });
        if (flash.dataset.error) toast.fire({ icon: 'error', title: flash.dataset.error });
        if (flash.dataset.validation) {
            Swal.fire({
                icon: 'error',
                title: 'Periksa kembali isian',
                html: flash.dataset.validation,
                confirmButtonText: 'Mengerti',
            });
        }
    }
});

/** Pencarian cepat menu (Ctrl/⌘ + K). items: [{ label, group, url, icon }] */
Alpine.data('commandPalette', (items) => ({
    open: false,
    query: '',
    cursor: 0,
    items,
    get results() {
        const normalize = (v) => v.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        const terms = normalize(this.query.trim()).split(/\s+/).filter(Boolean);

        if (!terms.length) return this.items;

        return this.items.filter((item) => {
            const hay = normalize(`${item.label} ${item.group}`);
            return terms.every((t) => hay.includes(t));
        });
    },
    show() {
        this.open = true;
        this.query = '';
        this.cursor = 0;
        this.$nextTick(() => this.$refs.input.focus());
    },
    hotkey(e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            this.open ? (this.open = false) : this.show();
        } else if (e.key === 'Escape' && this.open) {
            this.open = false;
        }
    },
    move(step) {
        const total = this.results.length;
        if (!total) return;
        this.cursor = (this.cursor + step + total) % total;
        this.$nextTick(() => this.$refs.list.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' }));
    },
    go() {
        const item = this.results[this.cursor];
        if (item) window.location.href = item.url;
    },
}));

Alpine.start();
