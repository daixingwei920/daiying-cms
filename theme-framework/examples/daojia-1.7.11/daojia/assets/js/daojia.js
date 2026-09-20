
(()=>{const q=(s,r=document)=>r.querySelector(s);
const menu=q('.dj-menu-btn'),nav=q('.dj-nav');if(menu&&nav)menu.addEventListener('click',()=>{const o=nav.classList.toggle('is-open');menu.setAttribute('aria-expanded',String(o));});
const hero=q('.dj-hero'),incense=q('.dj-incense');
if(incense)incense.addEventListener('click',()=>{
 if(incense.classList.contains('is-burning')||incense.classList.contains('is-igniting'))return;
 const label=q('span',incense);incense.classList.add('is-igniting');if(label)label.textContent='点香…';tone(520,.35,'sine',.02);
 setTimeout(()=>{incense.classList.remove('is-igniting');incense.classList.add('is-burning');hero?.classList.add('is-incense-burning');if(label)label.textContent='清香袅袅'},1350);
 setTimeout(()=>{incense.classList.remove('is-burning');hero?.classList.remove('is-incense-burning');if(label)label.textContent='敬香'},15000);
});

const stage=q('.dj-stage'),obj=q('.dj-stage-object'),fx=q('.dj-stage-fx'),name=q('.dj-stage-name');let timer=0;
const labels={bell:'三清铃',sword:'玄天法剑',bagua:'太极八卦镜',talisman:'符箓'};
function image(k){return document.querySelector('[data-artifact="'+k+'"] img')?.src||''}
function asset(name){const base=image('talisman');return base?base.replace(/talisman\.png(?:\?.*)?$/,'')+name:''}
function clear(){clearTimeout(timer);if(!stage)return;stage.hidden=true;stage.className='dj-stage';obj.innerHTML='';fx.innerHTML='';name.textContent=''}
function show(k){clear();stage.hidden=false;stage.classList.add('is-'+k);name.textContent=labels[k];const im=new Image();im.alt=labels[k];im.src=image(k);obj.appendChild(im);

 if(k==='bell'){
  fx.innerHTML='<i class="dj-wave"></i><i class="dj-wave w2"></i><i class="dj-wave w3"></i>';
  setTimeout(()=>tone(1175,1.05,'sine',.065),820);setTimeout(()=>tone(1046,.98,'sine',.047),1500);setTimeout(()=>tone(880,.92,'sine',.034),2120);timer=setTimeout(clear,3500);
 }
 if(k==='sword'){
  const u=image(k);
  fx.innerHTML=`<i class="dj-sword-ghost g1"><img src="${u}" alt=""></i><i class="dj-sword-ghost g2"><img src="${u}" alt=""></i><i class="dj-sword-ghost g3"><img src="${u}" alt=""></i><i class="dj-sword-ghost g4"><img src="${u}" alt=""></i><i class="dj-sword-final-glint"></i>`;
  [120,700,1290,1800].forEach((ms,i)=>setTimeout(()=>tone(760+i*120,.18,'triangle',.025),ms));timer=setTimeout(clear,4100);
 }
 if(k==='bagua'){
  const gua=[['乾',0],['兑',45],['离',90],['震',135],['坤',180],['艮',225],['坎',270],['巽',315]];
  fx.innerHTML='<i class="dj-mirror-sheen"></i><i class="dj-mirror-mist"></i>'+gua.map((x,i)=>`<b class="dj-trigram" style="--a:${x[1]}deg;--d:${1.55+i*.28}s">${x[0]}</b>`).join('')+'<strong class="dj-dao-char">道</strong>';
  gua.forEach((_,i)=>setTimeout(()=>tone(300+i*28,.32,'sine',.012),1550+i*280));
  setTimeout(()=>tone(432,2.4,'sine',.026),4900);timer=setTimeout(clear,10300);
 }
 if(k==='talisman'){
  fx.innerHTML=`<img class="dj-talisman-exact" src="${asset('talisman-glow.png')}" alt=""><i class="dj-talisman-flash"></i>`;
  tone(396,.7,'triangle',.018);setTimeout(()=>tone(528,1.0,'sine',.02),2700);setTimeout(()=>tone(660,1.4,'sine',.018),5650);timer=setTimeout(clear,8100);
 }
}
document.querySelectorAll('[data-artifact]').forEach(b=>b.addEventListener('click',()=>show(b.dataset.artifact)));
q('.dj-stage-close')?.addEventListener('click',clear);q('.dj-stage-shade')?.addEventListener('click',clear);document.addEventListener('keydown',e=>{if(e.key==='Escape'&&stage&&!stage.hidden)clear()});
function tone(f,d,t='sine',v=.04){try{const A=window.AudioContext||window.webkitAudioContext;if(!A)return;window.__djA=window.__djA||new A();const a=window.__djA,o=a.createOscillator(),g=a.createGain();o.type=t;o.frequency.value=f;g.gain.setValueAtTime(v,a.currentTime);g.gain.exponentialRampToValueAtTime(.001,a.currentTime+d);o.connect(g);g.connect(a.destination);o.start();o.stop(a.currentTime+d)}catch(e){}}
})();

// Daojia 1.6.1 — direct artifact feedback + stage interaction bridge
(()=>{
 const pulse=(el,ms)=>{if(!el)return;el.classList.remove('animating');void el.offsetWidth;el.classList.add('animating');setTimeout(()=>el.classList.remove('animating'),ms)};
 document.querySelector('.dj-bell')?.addEventListener('click',e=>pulse(e.currentTarget,2200));
 document.querySelector('.dj-sword')?.addEventListener('click',e=>pulse(e.currentTarget,1100));
 document.querySelector('.dj-bagua')?.addEventListener('click',e=>pulse(e.currentTarget,8000));
 document.querySelector('.dj-talisman')?.addEventListener('click',e=>pulse(e.currentTarget,1600));
})();


// Daojia 1.7.1 — TRUE Taiji Eye: SEALED -> TAIJI SPLIT -> OPEN
(()=>{
 const hero=document.querySelector('.dj-hero'),eye=document.querySelector('.dj-taiji-eye'),gate=document.querySelector('.dj-xuanmen'),classics=document.querySelector('#classics');
 if(!hero||!eye||!gate||!classics)return;
 let state='SEALED',busy=false;
 document.documentElement.classList.add('dj-sealed');document.body.classList.add('dj-sealed');
 const nudge=()=>{eye.classList.remove('dj-eye-nudge');void eye.offsetWidth;eye.classList.add('dj-eye-nudge');setTimeout(()=>eye.classList.remove('dj-eye-nudge'),900)};
 const blockScroll=e=>{if(state==='SEALED'&&!busy){e.preventDefault();nudge()}};
 window.addEventListener('wheel',blockScroll,{passive:false});
 window.addEventListener('touchmove',blockScroll,{passive:false});
 document.querySelectorAll('a').forEach(a=>{
   const href=(a.getAttribute('href')||'').trim();
   if(href==='#classics'||/\/articles(?:[\/#?]|$)/.test(href))a.addEventListener('click',e=>{if(state==='SEALED'){e.preventDefault();nudge()}});
 });
 if(location.hash==='#classics'){history.replaceState(null,'',location.pathname+location.search);scrollTo(0,0)}
 eye.addEventListener('click',()=>{
   if(busy||state!=='SEALED')return;busy=true;eye.setAttribute('aria-disabled','true');
   classics.style.visibility='visible';classics.style.opacity='1';classics.style.pointerEvents='none';
   gate.classList.add('is-active','is-forming');hero.classList.add('dj-taiji-opening');
   setTimeout(()=>{gate.classList.add('is-splitting');document.documentElement.classList.remove('dj-sealed');document.body.classList.remove('dj-sealed');document.body.classList.add('dj-unsealed');state='OPEN'},2850);
   setTimeout(()=>gate.classList.add('is-opened'),2920);
   setTimeout(()=>{hero.classList.add('is-open');gate.classList.remove('is-active','is-forming','is-splitting','is-opened');gate.style.opacity='';classics.style.pointerEvents='';busy=false;classics.scrollIntoView({block:'start',behavior:'auto'})},3460);
 });
})();


// Daojia 1.7.5 — complete classics list with in-page pagination (10 per page)
(()=>{
 const grid=document.querySelector('.dj-classics-grid'),pager=document.querySelector('.dj-home-pagination');
 if(!grid||!pager||pager.classList.contains('dj-server-pagination'))return;
 const cards=[...grid.querySelectorAll('.dj-classic-card')],status=document.querySelector('.dj-classics-page-status');
 const size=Math.max(1,parseInt(grid.dataset.pageSize||'10',10)||10),pages=Math.max(1,Math.ceil(cards.length/size));
 let page=1;
 const label=(p)=>String(p);
 const renderPager=()=>{
   pager.innerHTML='';
   if(pages<=1){pager.hidden=true;return;}
   pager.hidden=false;
   const add=(text,p,opts={})=>{const b=document.createElement('button');b.type='button';b.textContent=text;b.dataset.page=String(p);if(opts.current)b.setAttribute('aria-current','page');if(opts.disabled)b.disabled=true;b.addEventListener('click',()=>show(p,true));pager.appendChild(b)};
   add('上一页',page-1,{disabled:page===1});
   let seq=[];
   if(pages<=7)seq=Array.from({length:pages},(_,i)=>i+1);
   else {seq=[1];const a=Math.max(2,page-2),z=Math.min(pages-1,page+2);if(a>2)seq.push('…');for(let p=a;p<=z;p++)seq.push(p);if(z<pages-1)seq.push('…');seq.push(pages)}
   seq.forEach(p=>{if(p==='…'){const s=document.createElement('span');s.textContent='…';s.setAttribute('aria-hidden','true');pager.appendChild(s)}else add(label(p),p,{current:p===page})});
   add('下一页',page+1,{disabled:page===pages});
 };
 function show(next,scroll){page=Math.min(pages,Math.max(1,next));const start=(page-1)*size,end=start+size;cards.forEach((c,i)=>c.hidden=!(i>=start&&i<end));if(status)status.textContent=`第 ${page} / ${pages} 页`;renderPager();if(scroll&&document.body.classList.contains('dj-unsealed'))document.querySelector('#classics')?.scrollIntoView({block:'start',behavior:'smooth'});}
 show(1,false);
})();
