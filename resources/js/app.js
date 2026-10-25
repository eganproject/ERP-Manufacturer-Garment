import Alpine from 'alpinejs';
import TomSelect from 'tom-select';
import flatpickr from 'flatpickr';
import { Indonesian } from 'flatpickr/dist/l10n/id.js';
import IMask from 'imask';
import Swal from 'sweetalert2';

window.Alpine = Alpine;
window.Swal = Swal;

flatpickr.localize(Indonesian);

const toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3500,
    timerProgressBar: true,
});

/** Format angka gaya Indonesia: 1.234.567,5 */
export function formatNumber(value, decimals = 0) {
    const n = Number(value) || 0;

    return n.toLocaleString('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

const pluginInitializers = {
    // Dropdown dengan pencarian: <select data-select>
    select(el) {
        const options = {
            allowEmptyOption: true,
            maxOptions: 500,
            placeholder: el.dataset.placeholder || 'Pilih...',
            plugins: el.multiple ? ['remove_button'] : [],
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
            altInputClass: el.className,
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

window.ERP = { initPlugins, setMoney, formatNumber, toast };

document.addEventListener('submit', async (event) => {
    const form = event.target;

    // Konfirmasi aksi: <form data-confirm="Yakin?">
    if (form.dataset.confirm && !form.dataset.confirmed) {
        event.preventDefault();

        const result = await Swal.fire({
            title: form.dataset.confirmTitle || 'Konfirmasi',
            text: form.dataset.confirm,
            icon: form.dataset.confirmIcon || 'warning',
            showCancelButton: true,
            confirmButtonText: form.dataset.confirmButton || 'Ya, lanjutkan',
            cancelButtonText: 'Batal',
            confirmButtonColor: form.dataset.confirmIcon === 'question' ? '#4f46e5' : '#dc2626',
            reverseButtons: true,
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

    // Cegah submit ganda
    form.querySelectorAll('button[type="submit"]').forEach((btn) => {
        btn.disabled = true;
    });
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
                confirmButtonColor: '#4f46e5',
            });
        }
    }
});

Alpine.start();
