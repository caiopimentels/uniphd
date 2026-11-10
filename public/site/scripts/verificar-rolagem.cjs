const {JSDOM}=require('/private/tmp/uniphd-browser/node_modules/jsdom');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../site');
async function run(mobile,reduced){
 const dom=new JSDOM(fs.readFileSync(path.join(root,'index.html'),'utf8'),{runScripts:'outside-only',pretendToBeVisual:true,url:'http://localhost:4173'});
 const w=dom.window,d=w.document;let drawn=[],imageFiles=[],top=0;
 w.matchMedia=q=>({matches:q.includes('reduced-motion')?reduced:mobile});
 w.IntersectionObserver=class{observe(){}unobserve(){}};
 w.HTMLCanvasElement.prototype.getContext=()=>({drawImage(img,...coords){drawn.push({src:img.src,coords})}});
 w.Image=class{constructor(){this.naturalWidth=mobile?720:1920;this.naturalHeight=1080}set src(value){this._src=value;assert(fs.existsSync(path.join(root,value)),'Frame ausente: '+value);imageFiles.push(value);queueMicrotask(()=>this.onload?.())}get src(){return this._src}};
 const cine=d.querySelector('.cine');Object.defineProperty(cine,'offsetHeight',{get:()=>4200});cine.getBoundingClientRect=()=>({top,bottom:4200+top,height:4200});
 Object.defineProperty(w,'innerHeight',{value:1000});Object.defineProperty(w,'innerWidth',{value:mobile?390:1440});Object.defineProperty(w,'scrollY',{get:()=>-top});Object.defineProperty(w,'devicePixelRatio',{value:1});
 const canvas=d.querySelector('canvas');Object.defineProperty(canvas,'clientWidth',{value:mobile?390:1440});Object.defineProperty(canvas,'clientHeight',{value:1000});
 w.eval(fs.readFileSync(path.join(root,'config.js'),'utf8'));w.eval(fs.readFileSync(path.join(root,'app.js'),'utf8'));
 const wait=()=>new Promise(r=>setTimeout(r,900));
 const go=async(p)=>{top=-p*3200;w.dispatchEvent(new w.Event('scroll'));await wait()};
 await wait();assert(drawn.length>0);assert(d.querySelector('.momento[data-de="0"]').classList.contains('ativo'));
 assert.equal(d.querySelectorAll('#sobre').length,1);
 const about=d.querySelector('#sobre');
 if(!reduced){
  assert.equal(about.closest('.cine-revelacao'),d.querySelector('.cine-revelacao'));
  assert.equal(cine.nextElementSibling.id,'pratica');
  assert.equal(d.querySelectorAll('.momento--direita').length,0);
  await go(.5);assert(d.querySelector('.momento--centro').classList.contains('ativo'));
  await go(.7);assert(drawn.at(-1).src.endsWith('f_0160.webp'));
  const radiusBefore=parseFloat(d.querySelector('.gota-borda').style.width);assert(radiusBefore>0);
  assert.equal(d.querySelector('.cine-revelacao').getAttribute('aria-hidden'),'true');
  await go(.92);assert(!d.querySelector('.momento--centro').classList.contains('ativo'));
  assert(parseFloat(d.querySelector('.gota-borda').style.width)>Math.hypot(w.innerWidth,w.innerHeight)*2);
  assert(d.querySelector('.cine-revelacao #sobre h2').textContent.includes('pessoas'));
  assert.equal(d.querySelector('.cine-revelacao').inert,false);
  assert.equal(d.querySelector('.cine-revelacao').getAttribute('aria-hidden'),'false');
  assert.equal(d.querySelector('#sobre'),about);
  assert(Number.isFinite(parseFloat(d.querySelector('.gota-borda').style.left)));
  await go(0);assert(d.querySelector('.momento[data-de="0"]').classList.contains('ativo'));assert(d.querySelector('.momento--centro').inert);
  assert.equal(d.querySelector('.cine-revelacao').inert,true);
  w.scrollTo=({top:y})=>{top=-y;w.dispatchEvent(new w.Event('scroll'))};
  d.querySelector('.pular').click();await wait();
  assert.equal(d.querySelector('.cine-revelacao').inert,false);
  assert.equal(d.activeElement,about);
 }else{
  assert.equal(about.closest('.cine-revelacao'),null);
  assert.equal(cine.nextElementSibling,about);
  await go(1);assert(d.querySelector('.momento[data-de="0"]').classList.contains('ativo'));assert(imageFiles.every(x=>x.includes('0001')))
 }
 assert(drawn.every(x=>x.coords.every(Number.isFinite)));assert(imageFiles.every(x=>x.includes(mobile?'/mobile/':'/desktop/')));
 console.log('OK:',mobile?'mobile':'desktop',reduced?'movimento reduzido':'rolagem de ida e volta, momentos, transição e frames');w.close();
}
(async()=>{await run(false,false);await run(true,false);await run(true,true)})().catch(e=>{console.error(e);process.exitCode=1});
