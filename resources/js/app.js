import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import Choices from 'choices.js';
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

// Ikon dialog: emoji dalam lingkaran lembut yang "melompat" kecil saat muncul
const emoji = (char) => `<span class="erp-swal-emoji" aria-hidden="true">${char}</span>`;
const ICONS = {
    warning: emoji('🤔'),
    error: emoji('😬'),
    question: emoji('🤔'),
    info: emoji('💡'),
    success: emoji('🎉'),
};

/** Dialog bertema ERP: tombol memakai kelas .btn-*, ikon emoji, tanpa warna inline. */
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

// Nada notifikasi: emoji + warna lembut + sapaan kecil bila judul kosong
const TOAST_TONES = {
    success: { emoji: ['🎉', '✨', '👍', '🙌'], hello: 'Berhasil!' },
    error: { emoji: ['😵', '🙈', '😬'], hello: 'Ups, ada masalah' },
    warning: { emoji: ['🤔', '👀', '✋'], hello: 'Tunggu sebentar' },
    info: { emoji: ['💡', '👋', '📌'], hello: 'Info' },
};
const pick = (list) => list[Math.floor(Math.random() * list.length)];

/** Notifikasi kecil di pojok kanan atas: ERP.toast.fire({ icon: 'success', title: '...', text: '...' }) */
const toast = {
    fire({ icon = 'info', title = '', text = '', timer = 4500 } = {}) {
        let host = document.querySelector('.erp-toaster');

        if (!host) {
            host = document.createElement('div');
            host.className = 'erp-toaster';
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }

        const tone = TOAST_TONES[icon] ? icon : 'info';
        const el = document.createElement('div');
        el.className = `erp-toast erp-toast-${tone}`;
        el.dataset.state = 'enter';
        el.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        el.innerHTML = `<span class="erp-toast-icon" aria-hidden="true"><span class="erp-toast-emoji"></span></span>
            <div class="min-w-0 flex-1"><p class="erp-toast-title"></p><p class="erp-toast-text empty:hidden"></p></div>
            <button type="button" class="erp-toast-close" aria-label="Tutup">${svg('M6 18L18 6M6 6l12 12', 2)}</button>
            <span class="erp-toast-bar" aria-hidden="true"></span>`;
        el.querySelector('.erp-toast-emoji').textContent = pick(TOAST_TONES[tone].emoji);
        el.querySelector('.erp-toast-title').textContent = title || TOAST_TONES[tone].hello;
        el.querySelector('.erp-toast-text').textContent = text;

        if (tone === 'success') {
            // Taburan konfeti kecil di sekitar ikon
            const burst = document.createElement('span');
            burst.className = 'erp-confetti';
            burst.setAttribute('aria-hidden', 'true');
            burst.innerHTML = Array.from({ length: 8 }, (_, i) => `<i style="--a:${i * 45}deg"></i>`).join('');
            el.querySelector('.erp-toast-icon').appendChild(burst);
        }

        let remaining = timer;
        let started = Date.now();
        let closeTimer;
        const bar = el.querySelector('.erp-toast-bar');

        const close = () => {
            clearTimeout(closeTimer);
            el.dataset.state = 'leave';
            setTimeout(() => el.remove(), 350);
        };
        const run = () => {
            if (!timer) return;
            started = Date.now();
            closeTimer = setTimeout(close, remaining);
            bar.style.transition = `transform ${remaining}ms linear`;
            bar.style.transform = 'scaleX(0)';
        };

        // Berhenti sejenak saat disorot, agar sempat dibaca
        el.addEventListener('pointerenter', () => {
            if (!timer || el.dataset.state === 'leave') return;
            clearTimeout(closeTimer);
            remaining = Math.max(remaining - (Date.now() - started), 800);
            const scale = remaining / timer;
            bar.style.transition = 'none';
            bar.style.transform = `scaleX(${scale})`;
        });
        el.addEventListener('pointerleave', () => el.dataset.state !== 'leave' && run());
        el.querySelector('.erp-toast-close').addEventListener('click', close);

        host.appendChild(el);
        if (!timer) bar.hidden = true;
        requestAnimationFrame(() =>
            requestAnimationFrame(() => {
                el.dataset.state = 'shown';
                run();
            }),
        );
    },
};

/** Format angka gaya Indonesia: 1.234.567,5 */
export function formatNumber(value, decimals = 0) {
    const n = Number(value) || 0;

    return n.toLocaleString('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

const pluginInitializers = {
    // Dropdown dengan pencarian: <select data-select> (Choices.js)
    select(el) {
        const blank = el.querySelector('option[value=""]');
        const placeholder = el.dataset.placeholder || blank?.textContent.trim() || 'Pilih...';
        const count = el.options.length;

        const choices = new Choices(el, {
            allowHTML: false,
            shouldSort: false,
            searchEnabled: el.multiple || count > 6,
            searchResultLimit: 100,
            renderChoiceLimit: -1,
            searchPlaceholderValue: 'Ketik untuk mencari…',
            placeholder: true,
            placeholderValue: placeholder,
            removeItemButton: el.multiple || !el.required,
            itemSelectText: '',
            noResultsText: 'Tidak ketemu 🔍 coba kata lain',
            noChoicesText: 'Tidak ada pilihan lagi',
            addItemText: (value) => `Tekan Enter untuk menambah "${value}"`,
            addChoices: el.dataset.create !== undefined,
            loadingText: 'Memuat…',
            position: 'auto',
            // Cocokkan potongan kata di mana pun (mis. sebagian SKU), tidak terlalu "ngawur"
            fuseOptions: { threshold: 0.3, ignoreLocation: true },
        });

        const container = choices.containerOuter.element;
        const dropdown = choices.dropdown.element;

        // Dropdown memakai posisi fixed agar tidak terpotong tabel/kartu yang bisa digeser (overflow)
        const place = () => {
            const r = container.getBoundingClientRect();
            const below = window.innerHeight - r.bottom;
            const up = below < 280 && r.top > below;
            dropdown.style.position = 'fixed';
            dropdown.style.left = `${r.left}px`;
            dropdown.style.width = `${Math.max(r.width, 224)}px`;
            dropdown.style.top = up ? 'auto' : `${r.bottom + 6}px`;
            dropdown.style.bottom = up ? `${window.innerHeight - r.top + 6}px` : 'auto';
            container.classList.toggle('is-flipped', up);
        };
        const onScroll = (e) => (dropdown.contains(e.target) ? null : place());

        el.addEventListener('showDropdown', () => {
            place();
            window.addEventListener('scroll', onScroll, { capture: true, passive: true });
            window.addEventListener('resize', place);
        });
        el.addEventListener('hideDropdown', () => {
            window.removeEventListener('scroll', onScroll, { capture: true });
            window.removeEventListener('resize', place);
        });
        el.addEventListener('change', () => container.classList.remove('is-invalid'));

        if (el.classList.contains('is-invalid')) container.classList.add('is-invalid');

        el._choices = choices;
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

// Select Choices menyembunyikan <select> asli, jadi pesan "wajib diisi" bawaan browser tidak muncul.
// Tandai merah, beri tahu, lalu buka dropdown-nya.
let invalidNotified = false;
document.addEventListener('invalid', (event) => {
    const el = event.target;
    if (!(el instanceof HTMLSelectElement) || !el._choices) return;

    event.preventDefault();
    const container = el._choices.containerOuter.element;
    container.classList.add('is-invalid');

    if (invalidNotified) return;
    invalidNotified = true;
    setTimeout(() => (invalidNotified = false), 300);

    const label = el.id ? document.querySelector(`label[for="${el.id}"]`)?.textContent.replace('*', '').trim() : '';
    toast.fire({ icon: 'warning', title: label ? `${label} belum dipilih` : 'Masih ada pilihan yang kosong', text: 'Pilih salah satu dulu, ya.' });
    container.scrollIntoView({ block: 'center', behavior: 'smooth' });
    setTimeout(() => el._choices.showDropdown(), 250);
}, true);

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
                title: 'Ada isian yang perlu dicek',
                html: flash.dataset.validation,
                confirmButtonText: 'Oke, saya perbaiki',
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
