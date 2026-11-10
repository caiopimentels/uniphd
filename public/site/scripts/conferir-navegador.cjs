// Executar no Terminal do Mac, onde o Chromium tem permissão de iniciar.
const {chromium}=require('/private/tmp/uniphd-browser/node_modules/playwright');
const fs=require('node:fs');const path=require('node:path');
(async()=>{
 const out=path.resolve(__dirname,'../revisao');fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({headless:true});
 try{
  for(const viewport of [{width:1440,height:1000},{width:390,height:844}]){
   const page=await browser.newPage({viewport});const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.goto('http://localhost:4173',{waitUntil:'networkidle'});
   const name=viewport.width===390?'mobile':'desktop';
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
   if(overflow)throw Error(name+': largura excedeu viewport');
   const hero=await page.locator('.cine').evaluate(el=>el.offsetHeight-innerHeight);
   for(const [label,p] of [['inicio',0],['seringa',.5],['gota',.82],['transicao',1]]){
    await page.evaluate(y=>scrollTo({top:y,behavior:'instant'}),hero*p);await page.waitForTimeout(1600);
    await page.screenshot({path:path.join(out,name+'-'+label+'.png')});
   }
   for(const id of ['sobre','pratica','pos','cursos','instituicao','contato']){
    await page.locator('#'+id).scrollIntoViewIfNeeded();await page.waitForTimeout(900);
    await page.screenshot({path:path.join(out,name+'-'+id+'.png')});
   }
   if(errors.length)throw Error(errors.join('\n'));
   await page.close();console.log(name+': navegação e capturas concluídas');
  }
 }finally{await browser.close()}
})().catch(e=>{console.error(e.message);process.exitCode=1});
