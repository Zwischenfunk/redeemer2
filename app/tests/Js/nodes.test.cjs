const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function input(value, dataset = {}) {
    let onInput;

    return {
        value,
        dataset,
        validationMessage: '',
        addEventListener(type, listener) {
            assert.equal(type, 'input');
            onInput = listener;
        },
        setCustomValidity(message) {
            this.validationMessage = message;
        },
        change(value) {
            this.value = value;
            onInput();
        },
    };
}

test('decimal GPS edits update or clear the submitted coordinates and block invalid values', () => {
    const decimal = input('', { decimal: 'lat' });
    const degrees = input('48');
    const minutes = input('12');
    const seconds = input('30');
    const parts = { deg: degrees, min: minutes, sec: seconds };
    const values = () => Object.values(parts).map((part) => part.value);
    const document = {
        querySelectorAll: () => [decimal],
        getElementById: (id) => parts[id.replace('f-gps_lat_', '')],
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/assets/nodes.js'), 'utf8'), { document });

    for (const invalid of ['not a number', '0x10', 'Infinity', '-1', '90.0001']) {
        decimal.change(invalid);
        assert.notEqual(decimal.validationMessage, '', `${invalid} must block submission`);
        assert.deepEqual(values(), ['48', '12', '30']);
    }

    degrees.change('46');
    assert.equal(decimal.validationMessage, '');
    assert.equal(decimal.value, '46.2083333');

    decimal.change('47,5');
    assert.equal(decimal.validationMessage, '');
    assert.deepEqual(values(), ['47', '30', '0']);

    decimal.change(',5');
    assert.equal(decimal.validationMessage, '');
    assert.deepEqual(values(), ['0', '30', '0']);

    decimal.change('47.');
    assert.equal(decimal.validationMessage, '');
    assert.deepEqual(values(), ['47', '0', '0']);

    degrees.change('0');
    seconds.change('0.00036');
    assert.equal(decimal.value, '0.0000001');

    decimal.change(' ');
    assert.equal(decimal.validationMessage, '');
    assert.deepEqual(values(), ['', '', '']);
});
