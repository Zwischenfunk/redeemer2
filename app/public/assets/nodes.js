// Node form: keeps the decimal-degree helper fields in step with the degree/minute/second fields.

function dmsToDecimal(degrees, minutes, seconds) {
    return Math.round((degrees + minutes / 60 + seconds / 3600) * 1e7) / 1e7;
}

// Works in thousandths of an arc second, so rounding never yields 60 seconds.
function decimalToDms(decimal) {
    const total = Math.round(decimal * 3600000);

    return [Math.floor(total / 3600000), Math.floor(total % 3600000 / 60000), total % 60000 / 1000];
}

document.querySelectorAll('[data-decimal]').forEach((decimal) => {
    const parts = ['deg', 'min', 'sec'].map((part) => document.getElementById('f-gps_' + decimal.dataset.decimal + '_' + part));
    const read = (input) => {
        input.value = input.value.replace(',', '.');

        return input.value.trim() === '' ? NaN : Number(input.value);
    };
    const showDecimal = () => {
        const values = parts.map(read);
        decimal.value = values.some(Number.isNaN) ? '' : dmsToDecimal(...values);
    };

    parts.forEach((input) => input.addEventListener('input', showDecimal));
    decimal.addEventListener('input', () => {
        const value = read(decimal);
        if (!Number.isNaN(value) && value >= 0) {
            decimalToDms(value).forEach((part, index) => { parts[index].value = part; });
        }
    });
    showDecimal();
});
