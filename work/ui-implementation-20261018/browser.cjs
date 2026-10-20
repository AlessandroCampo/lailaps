const { chromium } = require('C:/Users/Alessandro/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
(async () => {
 const browser = await chromium.launch({channel:'msedge',headless:true});
 const page = await browser.newPage({viewport:{width:1440,height:1000}});
 const errors=[]; page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://localhost:8000/login');
 await page.getByLabel('Email address').fill('test@example.com');
 await page.getByLabel('Password',{exact:true}).fill('password');
 await page.getByRole('button',{name:'Log in',exact:true}).click();
 await page.waitForURL('**/projects');
 await page.context().storageState({path:__dirname+'/browser-state.json'});
 const meta=JSON.parse(fs.readFileSync(__dirname+'/fixtures.json'));
 await page.goto('http://localhost:8000/projects/'+meta.empty);
 await page.getByText('Run your first scan',{exact:true}).waitFor();
 await page.screenshot({path:__dirname+'/empty-desktop-light.png',fullPage:true});
 console.log(JSON.stringify({title:await page.title(), text:(await page.locator('main').innerText()).slice(0,1500), overflow:await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth), errors}));
 await browser.close();
})();
