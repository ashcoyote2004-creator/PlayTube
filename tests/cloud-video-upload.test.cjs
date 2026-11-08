// Unit tests only: fake page, fake provider responses, no external requests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/js/cloud-video-upload.js', 'utf8');
const flush = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };
function setup(responses) {
    const message = { textContent: '' };
    const retry = { hidden: true };
    const submit = { disabled: false };
    const requests = [];
    const timers = [];
    const panel = {
        classList: { add() {}, remove() {} },
        getAttribute() { return '/video-pipeline.php'; },
        querySelector(selector) { return selector === 'button' ? retry : message; }
    };
    const context = {
        window: {}, Date, Error, FormData,
        document: {
            body: { contains() { return true; } },
            getElementById(id) { return id === 'cloud-video-status' ? panel : submit; },
            querySelector() { return { value: 'test-session-hash' }; }
        },
        setTimeout(callback) { timers.push(callback); },
        fetch: async (url, options) => {
            requests.push({ url, options });
            const response = responses.shift();
            if (response instanceof Error) throw response;
            assert.ok(response, 'Unexpected duplicate network request');
            return { ok: response.ok, json: async () => response };
        }
    };
    vm.runInNewContext(source, context);
    return { context, requests, timers, message, retry, submit };
}
const preparing = { ok: true, video: { id: 7, processing: { status: 'preparing' } } };
const ready = { ok: true, video: { id: 7, processing: { status: 'ready', playback_id: 'fixture' } } };
(async () => {
    const data = { status: 200, uploaded_id: 5, file_name: 'Test' };
    let completed = 0;
    let page = setup([preparing, ready]);
    page.context.window.PT_CloudVideoUpload(data, received => {
        assert.equal(received, data);
        completed++;
    });
    await flush();
    assert.equal(completed, 0);
    assert.equal(page.submit.disabled, true);
    assert.equal(page.requests[0].options.method, 'POST');
    assert.equal(page.requests[0].options.body.get('hash'), 'test-session-hash');
    assert.equal(page.requests[0].options.body.get('uploaded_id'), '5');
    page.timers.shift()();
    await flush();
    assert.equal(completed, 1);
    assert.match(page.requests[1].url, /action=status&id=7/);
    assert.equal(page.timers.length, 0, 'Stop polling once ready');
    console.log('PASS staged upload -> authenticated process -> status poll -> metadata form callback');

    page = setup([preparing, new Error('Status temporarily unavailable'), ready]);
    completed = 0;
    page.context.window.PT_CloudVideoUpload(data, () => completed++);
    await flush();
    page.timers.shift()();
    await flush();
    assert.equal(page.retry.hidden, false);
    assert.equal(page.submit.disabled, true);
    page.retry.onclick();
    await flush();
    assert.equal(completed, 1);
    assert.equal(page.requests.filter(r => r.options.method === 'POST').length, 1, 'Retry must not repeat storage or processing creation');
    console.log('PASS transient failure retries only status and avoids duplicate provider charges');

    page = setup([{ ok: true, video: { id: 7, error: 'Processing failed', processing: { status: 'errored' } } }]);
    completed = 0;
    page.context.window.PT_CloudVideoUpload(data, () => completed++);
    await flush();
    assert.equal(completed, 0);
    assert.equal(page.submit.disabled, true);
    assert.equal(page.message.textContent, 'Processing failed');
    assert.equal(page.timers.length, 0);
    console.log('PASS provider errors keep publishing disabled');

    for (const theme of ['default', 'youplay']) {
        const template = fs.readFileSync(`themes/${theme}/layout/upload-video/content.html`, 'utf8');
        const scripts = [...template.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)];
        for (const [, script] of scripts) {
            // PHP fragments generate values, conditions or translated text; this
            // compile check catches bracket/callback syntax in each theme.
            new vm.Script(script.replace(/<\?php[\s\S]*?\?>/g, ''), { filename: theme });
        }
    }
    console.log('PASS both upload theme callback scripts compile');
})().catch(error => { console.error(error); process.exitCode = 1; });
