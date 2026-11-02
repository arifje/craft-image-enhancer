'use strict';
// Native Craft UI; only the guarded isolated fixture is permitted. AI transport is mocked server-side.
const puppeteer = require(process.env.IE_BROWSER_DRIVER || '/opt/puppeteer/node_modules/puppeteer');
const fs = require('node:fs'), assert = require('node:assert/strict'), {execFileSync} = require('node:child_process');
const root = '/tmp/ie-mcp-20261031', base = 'http://127.0.0.1:8424';
if (process.env.IE_MCP_TEST_SITE !== root + '/site') throw Error('Wrong isolated site');
const f = JSON.parse(fs.readFileSync(root + '/ui-fixture.json'));
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; console.log('PASS ' + label); };
(async () => {
    const browser = await puppeteer.launch({executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox']});
    const page = await browser.newPage(); page.setDefaultTimeout(20000); await page.setViewport({width: 1440, height: 1050});
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.setRequestInterception(true); page.on('request', request => {
        if (process.env.IE_BASELINE_DIR && /\/(image-editor|cp-field-enhancer)\.js/.test(request.url())) {
            return request.respond({status: 200, contentType: 'application/javascript', body: fs.readFileSync(process.env.IE_BASELINE_DIR + '/' + new URL(request.url()).pathname.split('/').pop())});
        }
        return new URL(request.url()).origin === base || /^(data|blob):/.test(request.url()) ? request.continue() : request.abort();
    });
    const action = (name, data = {}) => page.evaluate(async ({name, data}) => (await Craft.sendActionRequest('POST', 'craft-image-enhancer/article-image/' + name, {data})).data, {name, data: {assetId: f.sourceAssetId, ...data}});
    const open = async () => { await page.click(`.elementselect .element[data-id="${f.sourceAssetId}"] .image-enhancer-cp-field-button`); await page.waitForFunction(() => document.querySelector('.ie-canvas')?.width === 1600); await page.waitForSelector('.ie-modal .ie-header-actions button', {visible: true}); await page.waitForFunction(() => { const modal = document.querySelector('.ie-modal'); return modal.getClientRects().length && Number(getComputedStyle(modal).opacity) > .99 && !modal.classList.contains('velocity-animating'); }); };
    const compareVisible = () => page.evaluate(() => [...document.querySelectorAll('.ie-comparison-bar, .image-enhancer-cp-compare')].some(node => node.getClientRects().length > 0));
    const enhance = async () => {
        await page.click('.ie-tabs button:nth-child(2)');
        const response = page.waitForResponse(response => decodeURIComponent(response.url()).includes('article-image/enhance') && response.request().method() === 'POST');
        await page.click('[data-enhance]'); const q = await (await response).json(); check(q.success, 'Native enhancement queued');
        execFileSync('php', [root + '/ui-job.php', q.token]);
        await page.waitForFunction(() => !document.querySelector('.ie-save')?.disabled);
        await new Promise(resolve => setTimeout(resolve, 1600));
    };
    try {
        await page.goto(base + '/admin/login'); await page.type('input[name="username"]', f.username); await page.type('input[name="password"]', f.password);
        await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);
        const url = new URL(f.entryUrl); await page.goto(base + url.pathname + url.search);
        const previous = await action('status'); if (previous.token) await action('cancel', {token: previous.token}); await action('reset');
        await open(); await enhance();
        if (process.env.IE_BASELINE_DIR) { check(!await compareVisible(), 'Released version reproduces disappearing comparison after auto-accept'); return; }
        check(await page.$eval('.ie-tabs button:nth-child(2)', node => node.getAttribute('aria-pressed') === 'true'), 'AI tab stays selected after enhancement');
        check(await compareVisible(), 'Comparison remains visible after acceptance and delayed callbacks');
        check(await page.$eval('.ie-compare-toggle', node => node.getAttribute('aria-pressed') === 'true'), 'Compare toggle announces its active state');
        await page.focus('.ie-comparison-bar input'); await page.keyboard.press('Home');
        check(await page.$eval('.ie-comparison-original', node => node.style.clipPath === 'inset(0px 100% 0px 0px)'), 'Keyboard slider reveals complete current edit');
        await page.keyboard.press('End'); check(await page.$eval('.ie-comparison-original', node => node.style.clipPath === 'inset(0px 0% 0px 0px)'), 'Keyboard slider reveals complete original');
        await page.$eval('.ie-comparison-bar input', node => { node.value = 50; node.dispatchEvent(new Event('input', {bubbles: true})); });
        await page.screenshot({path: root + '/comparison-dark.png'});
        const pixels = await page.$eval('.ie-canvas', canvas => canvas.toDataURL());
        await page.click('[aria-label="Use light theme"]');
        check(await page.$eval('.ie-modal', node => node.dataset.theme === 'light' && getComputedStyle(node).colorScheme === 'light'), 'Light theme applies to modal and native controls');
        check(await page.$eval('.ie-sidebar', node => getComputedStyle(node).backgroundColor === 'rgb(255, 255, 255)'), 'Light sidebar uses a white surface');
        check(await page.$eval('.ie-canvas', canvas => canvas.toDataURL()) === pixels, 'Theme does not change image pixels');
        await page.screenshot({path: root + '/comparison-light.png'});
        await page.click('[aria-label="Rectangle (R)"]'); check(!await compareVisible(), 'Selecting a drawing tool returns to editing');
        const board = await page.$('.ie-artboard'), box = await board.boundingBox();
        await page.mouse.move(box.x + 30, box.y + 30); await page.mouse.down(); await page.mouse.move(box.x + 140, box.y + 110, {steps: 5}); await page.mouse.up();
        check(await page.$$eval('.ie-layer:not(.ie-base)', nodes => nodes.length) === 1, 'A shape can be added after AI enhancement');
        await page.click('.ie-compare-toggle'); check(await compareVisible(), 'Comparison reopens without a new provider call');
        await page.click('.ie-comparison-bar button'); check(!await compareVisible(), 'Done exits comparison');
        await page.click('[aria-label="Undo (⌘/Ctrl Z)"]'); check(await page.$$eval('.ie-layer:not(.ie-base)', nodes => nodes.length) === 0, 'Undo retains session history');
        await page.click('[aria-label="Redo (⌘/Ctrl Shift Z)"]');
        await enhance(); check(await compareVisible(), 'A subsequent AI enhancement also keeps comparison open');
        check(await page.$$eval('.ie-layer:not(.ie-base)', nodes => nodes.length) === 1, 'Subsequent enhancement preserves layers');
        await page.setViewport({width: 560, height: 850});
        check(await page.$eval('.ie-modal', node => node.getBoundingClientRect().right <= innerWidth && node.getBoundingClientRect().left >= 0), 'Light modal fits a narrow screen');
        await page.screenshot({path: root + '/comparison-light-narrow.png'});
        await page.setViewport({width: 1440, height: 1050});
        await page.click('[aria-label="Close"]'); await page.click('.ie-discard-edits'); await page.waitForFunction(() => !document.querySelector('.ie-modal') && ![...document.querySelectorAll('.modal-shade')].some(node => node.getClientRects().length && getComputedStyle(node).display !== 'none'));
        await open(); check(await page.$eval('.ie-modal', node => node.dataset.theme === 'light'), 'Light theme persists when reopening the editor');
        check(await page.$eval('.ie-tabs button:nth-child(2)', node => node.getAttribute('aria-pressed') === 'true'), 'AI tab persists when reopening');
        await page.click('.ie-tabs button:first-child');
        await page.click('[aria-label="Close"]');
        await page.waitForFunction(() => !document.querySelector('.ie-modal') && ![...document.querySelectorAll('.modal-shade')].some(node => node.getClientRects().length && getComputedStyle(node).display !== 'none'));
        await page.reload(); await open();
        check(await page.$eval('.ie-tabs button:first-child', node => node.getAttribute('aria-pressed') === 'true'), 'Layers tab persists after page reload');
        await page.click('[aria-label="Use dark theme"]'); check(await page.$eval('.ie-modal', node => node.dataset.theme === 'dark'), 'Dark theme can be restored');
        check(errors.length === 0, 'No browser JavaScript errors: ' + errors.join('; '));
        console.log(`Passed ${checks} native comparison/theme UI checks.`);
    } catch (error) { console.error('Browser errors:', errors); await page.screenshot({path: root + '/comparison-failure.png'}); fs.writeFileSync(root + '/comparison-failure.html', await page.content()); throw error; }
    finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
