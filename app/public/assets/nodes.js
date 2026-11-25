// Node form: keeps the decimal-degree helper fields in step with the degree/minute/second fields.

function dmsToDecimal(degrees, minutes, seconds) {
    return (degrees + minutes / 60 + seconds / 3600).toFixed(7).replace(/\.?0+$/, '');
}

// Works in thousandths of an arc second, so rounding never yields 60 seconds.
function decimalToDms(decimal) {
    const total = Math.round(decimal * 3600000);

    return [Math.floor(total / 3600000), Math.floor(total % 3600000 / 60000), total % 60000 / 1000];
}

document.querySelectorAll('[data-decimal]').forEach((decimal) => {
    const parts = ['deg', 'min', 'sec'].map((part) => document.getElementById('f-gps_' + decimal.dataset.decimal + '_' + part));
    const maximum = decimal.dataset.decimal === 'lat' ? 90 : 180;
    const normalized = (input) => input.value.trim().replace(',', '.');
    const read = (input) => {
        const value = normalized(input);
        return value === '' ? NaN : Number(value);
    };
    const showDecimal = () => {
        const values = parts.map(read);
        decimal.value = values.every(Number.isFinite) ? dmsToDecimal(...values) : '';
        decimal.setCustomValidity('');
    };

    parts.forEach((input) => input.addEventListener('input', showDecimal));
    decimal.addEventListener('input', () => {
        const text = normalized(decimal);
        const value = Number(text);
        if (text !== '' && (!/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/.test(text) || value > maximum)) {
            decimal.setCustomValidity(`Zahl zwischen 0 und ${maximum} erwartet.`);
            return;
        }
        decimal.setCustomValidity('');
        const values = text === '' ? ['', '', ''] : decimalToDms(value);
        parts.forEach((part, index) => { part.value = String(values[index]); });
    });
    showDecimal();
});
