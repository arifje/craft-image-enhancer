'use strict';
// Run against the isolated Craft audit site with a Puppeteer module supplied by IE_BROWSER_DRIVER.
const puppeteer = require(process.env.IE_BROWSER_DRIVER);
const fs = require('node:fs');
const assert = require('node:assert/strict');
const root = '/tmp/image-enhancer-audit-20261020';
if (process.env.IE_AUDIT_ROOT !== root) throw new Error('Wrong audit root');
const fixture = JSON.parse(fs.readFileSync(root + '/fixture.json'));
const base = 'http://127.0.0.1:8422';
const cardSelector = `.elementselect .element[data-id="${fixture.assetId}"]`;
let checks = 0;
const equal = (actual, expected, message) => { assert.equal(actual, expected, message); checks++; console.log('PASS ' + message); };
(async () => {
    const browser = await puppeteer.launch({executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox']});
    const page = await browser.newPage();
    await page.setViewport({width: 1440, height: 1050});
    page.setDefaultTimeout(15000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setRequestInterception(true);
    page.on('request', request => {
        if (process.env.IE_BASELINE_SCRIPT && request.url().includes('/cp-field-enhancer.js')) {
            return request.respond({status: 200, contentType: 'application/javascript', body: fs.readFileSync(process.env.IE_BASELINE_SCRIPT, 'utf8')});
        }
        return new URL(request.url()).origin === base || /^(data|blob):/.test(request.url()) ? request.continue() : request.abort();
    });
    const count = () => page.$$eval(cardSelector + ' .image-enhancer-cp-field-button', nodes => nodes.length);
    async function refresh() {
        const rendered = page.waitForResponse(response => decodeURIComponent(response.url()).includes('app/render-elements'));
        await page.evaluate(id => Craft.refreshElementInstances(id), fixture.assetId);
        await rendered;
        // Include the scanner's delayed follow-up passes to detect duplicates/observer loops.
        await new Promise(resolve => setTimeout(resolve, 1100));
    }
    try {
        await page.goto(base + '/admin/login');
        await page.type('input[name="username"]', 'ie-audit-writer@example.invalid');
        await page.type('input[name="password"]', fixture.password);
        await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);
        await page.goto(base + new URL(fixture.entryUrl).pathname);
        await page.waitForSelector(cardSelector + ' .image-enhancer-cp-field-button');
        equal(await count(), 1, 'One initial Enhance button');
        if (process.env.IE_BASELINE_SCRIPT) {
            await refresh(); await refresh();
            equal((await count()) > 1, true, 'Old release reproduces duplicate buttons after native refreshes');
            return;
        }
        for (let i = 0; i < 4; i++) {
            await refresh();
            equal(await count(), 1, 'One button after native asset refresh ' + (i + 1));
            equal(await page.$$eval(cardSelector + ' > .image-enhancer-cp-field-action > button', nodes => nodes.length), 1, 'Button wrapper restored after refresh');
        }
        // Repair pages that already have duplicates, including buttons Craft orphaned from wrappers.
        await page.$eval(cardSelector, card => {
            const button = card.querySelector('.image-enhancer-cp-field-button');
            card.append(button.cloneNode(true), button.parentElement.cloneNode(true));
        });
        await refresh();
        equal(await count(), 1, 'Existing wrapped and orphaned duplicates removed');
        // A preserved marker must not suppress a missing control.
        await page.$eval(cardSelector, card => {
            card.dataset.imageEnhancerCpReady = '1';
            card.querySelector('.image-enhancer-cp-field-action').remove();
            const image = card.querySelector('img');
            if (image) card.append(image.cloneNode(true));
        });
        await page.waitForSelector(cardSelector + ' .image-enhancer-cp-field-button');
        equal(await count(), 1, 'Missing button restored despite stale readiness marker');
        await refresh();
        await page.click(cardSelector + ' .image-enhancer-cp-field-button');
        await page.waitForFunction(() => document.querySelector('.ie-canvas')?.width === 320 && Number(getComputedStyle(document.querySelector('.ie-modal')).opacity) > 0.99);
        equal(await page.$$eval('.ie-modal', nodes => nodes.length), 1, 'Surviving button opens exactly one editor');
        await page.focus('.ie-modal .image-enhancer-cp-close');
        await page.keyboard.press('Enter');
        await page.waitForFunction(() => !document.querySelector('.ie-modal'));
        // Save the asset through Craft's native slideout, exercising the real refresh event.
        for (let i = 0; i < 2; i++) {
            await page.evaluate(selector => Craft.createElementEditor('craft\\elements\\Asset', jQuery(selector)), cardSelector);
            const slideout = '#' + await page.evaluate(() => [...document.querySelectorAll('.slideout')].at(-1).id);
            await page.waitForSelector(slideout + ' input[name$="[title]"]', {visible: true});
            await page.waitForSelector(slideout + ' .submit', {visible: true});
            const rendered = page.waitForResponse(response => decodeURIComponent(response.url()).includes('app/render-elements'));
            await page.focus(slideout + ' .submit');
            await Promise.all([rendered, page.keyboard.press('Enter')]);
            await page.waitForSelector(slideout, {hidden: true});
            await new Promise(resolve => setTimeout(resolve, 1100));
            equal(await count(), 1, 'One Enhance button after native asset Save ' + (i + 1));
        }
        equal(errors.length, 0, 'No browser JavaScript errors');
        await page.screenshot({path: root + '/asset-button-refresh.png'});
        console.log('Passed ' + checks + ' asset button checks');
    } catch (error) {
        console.error(error);
        await page.screenshot({path: root + '/asset-button-refresh-failure.png'});
        fs.writeFileSync(root + '/asset-button-refresh-failure.html', await page.content());
        throw error;
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
