'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');

// Load the actual page functions; network and DOM effects are isolated below.
function loadFunctions(file, names, scope) {
    const source = fs.readFileSync(path.join(root, file), 'utf8');
    for (const name of names) {
        const match = new RegExp('^(?:async )?function ' + name + '\\(', 'm').exec(source);
        assert.ok(match, 'Missing page function: ' + name);
        const tail = source.slice(match.index);
        const next = tail.slice(1).search(/\n(?:async )?function |<\/script>/);
        vm.runInContext(next < 0 ? tail : tail.slice(0, next + 1), scope, {filename: file + ':' + name});
    }
}

function fixture(responder) {
    const calls = [];
    const alerts = [];
    const elements = new Map();
    let finish;
    const done = new Promise(resolve => { finish = resolve; });
    function $(selector) {
        if (!elements.has(selector)) {
            let value = '';
            const element = {
                text(next) { if (!arguments.length) return value; value = String(next); return this; },
                html() { return value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;'); },
                val() { return String(selector).includes('select[name=type]') ? 'A' : '192.0.2.4'; },
            };
            for (const method of ['css', 'find', 'each', 'prop', 'show', 'hide', 'addClass', 'removeClass', 'modal', 'bootstrapTable']) {
                element[method] = function () { return this; };
            }
            elements.set(selector, element);
        }
        return elements.get(selector);
    }
    $.trim = value => String(value).trim();
    $.ajax = options => {
        calls.push(options);
        return Promise.resolve().then(() => responder(options, calls.length));
    };
    const scope = vm.createContext({
        $, console, excelExporting: false, searchResults: [],
        setTimeout(callback) { callback(); },
        layer: {
            open() { return 1; }, close() {},
            msg(message) { if (message.includes('导出') || message.includes('没有')) finish(); },
            alert(message) { alerts.push(message); finish(); },
        },
        downloadRecordExcel(rows) { scope.downloaded = rows; finish(); },
        updateEditProgress() {}, updateSelectedCount() {},
        getSelectedRows() { return scope.searchResults; },
    });
    loadFunctions('app/view/domain/record_search.html', ['normalizeValue', 'valueMatches', 'requestRecords', 'searchDomain', 'searchQingCloudDomain', 'startBatchEdit'], scope);
    loadFunctions('app/view/domain/record.html', ['exportExcel'], scope);
    return {scope, calls, alerts, done};
}

function rows(count, offset = 0) {
    return Array.from({length: count}, (_, index) => ({RecordId: 'record-' + (offset + index), Value: '192.0.2.1'}));
}

test('search handles client arrays and multi-value records', async () => {
    const {scope, calls} = fixture(() => [{RecordId: 'one', Value: ['192.0.2.1', '192.0.2.2']}, {RecordId: 'two', Value: '192.0.2.10'}]);
    const result = await scope.searchDomain({id: 1, type: 'huawei'}, '192.0.2.1');
    assert.equal(result.length, 1);
    assert.equal(result[0].RecordId, 'one');
    assert.equal(calls.length, 1);
});

test('search reads every server page', async () => {
    const {scope, calls} = fixture(options => ({total: 250, rows: rows(Math.min(100, 250 - options.data.offset), options.data.offset)}));
    const result = await scope.searchDomain({id: 1, type: 'aliyun'}, '192.0.2.1');
    assert.equal(result.length, 250);
    assert.deepEqual(calls.map(call => call.data.offset), [0, 100, 200]);
});

test('QingCloud search reads nested record values', async () => {
    const {scope} = fixture(options => options.data.subdomain
        ? {total: 1, rows: [{RecordId: 'child', Value: '192.0.2.1'}]}
        : {total: 1, rows: [{RecordId: 'parent'}]});
    const result = await scope.searchDomain({id: 1, type: 'qingcloud'}, '192.0.2.1');
    assert.equal(result[0].RecordId, 'child');
});

test('HTTP 200 API errors and malformed responses fail the search', async () => {
    for (const response of [{code: -1, msg: 'backend unavailable', total: 0, rows: []}, '<html>login</html>']) {
        const {scope} = fixture(() => response);
        await assert.rejects(scope.searchDomain({id: 1, type: 'aws'}, '192.0.2.1'));
    }
});

test('later search failures cannot return partial results', async () => {
    const {scope} = fixture((options, call) => call === 1 ? {total: 200, rows: rows(100)} : {code: -1, msg: 'page failed', rows: []});
    await assert.rejects(scope.searchDomain({id: 1, type: 'aliyun'}, '192.0.2.1'), /page failed/);
});

test('export reads all records and resets its busy state', async () => {
    const {scope, calls, done} = fixture(options => ({total: 250, rows: rows(Math.min(100, 250 - options.data.offset), options.data.offset)}));
    scope.exportExcel();
    await done;
    assert.equal(scope.downloaded.length, 250);
    assert.equal(scope.excelExporting, false);
    assert.equal(calls.length, 3);
});

test('export never downloads a partial file after an API error or incomplete page', async () => {
    for (const response of [{code: -1, msg: 'page failed', total: 0, rows: []}, {total: 200, rows: []}]) {
        const {scope, alerts, done} = fixture((options, call) => call === 1 ? {total: 200, rows: rows(100)} : response);
        scope.exportExcel();
        await done;
        assert.equal(scope.downloaded, undefined);
        assert.equal(alerts.length, 1);
        assert.equal(scope.excelExporting, false);
    }
});

test('search batch editing retains the new Route 53 ID for the next edit', async () => {
    const {scope} = fixture(() => ({code: 0, success: 1, fail: 0, recordids: {old: 'new'}}));
    scope.searchResults = [{RecordId: 'old', domainId: 1, domainName: 'example.test', Name: 'www', Type: 'A', Value: '192.0.2.1'}];
    await scope.startBatchEdit();
    assert.equal(scope.searchResults[0].RecordId, 'new');
    assert.equal(scope.searchResults[0]._key, '1_new');
    assert.equal(scope.searchResults[0].Value, '192.0.2.4');
});
