'use strict';
const $ = id => document.getElementById(id);
let data = JSON.parse($('initialData').textContent), page = 0, pages = 1, paused = false;
let lastSuccess = Date.now(), failed = false, fetching = false;
const ukTime = new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/London',hour:'2-digit',minute:'2-digit'});
const ukDate = new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/London',weekday:'long',day:'numeric',month:'long'});
const esc = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const state = d => (d.status || 'Booked in').toLowerCase();
const late = d => !['arrived','completed','cancelled'].includes(state(d)) && Date.now() > d.due_epoch * 1000 + 300000;
function card(d){
  const kind = late(d) ? 'late' : state(d).replace(/[^a-z]/g,'');
  const label = late(d) ? 'Late · '+(d.status || 'Booked in') : (d.status || 'Booked in');
  return `<article class="card ${kind}"><div class="details"><div class="card-top"><time class="due">${esc(d.due_time)}</time><span class="status">${esc(label)}</span></div><h2>${esc(d.supplier || 'Contractor not specified')}</h2><div class="by">Booked by ${esc(d.user || '—')}</div><dl class="facts"><div><dt>Material</dt><dd>${esc(d.material || '—')}</dd></div><div><dt>Quantity</dt><dd>${esc(d.qty || '—')}</dd></div><div><dt>Unloading</dt><dd>${esc(d.method || '—')}</dd></div></dl></div><a class="qr" href="/gate.php?id=${Number(d.id)}&src=board" aria-label="Open gate details for ${esc(d.supplier)}"><img src="${esc(d.qr)}" alt="Gate check-in QR code" width="120" height="120">Scan for gate details</a></article>`;
}
function render(){
  const vp=$('viewport'), columns=vp.clientWidth>=1100?2:1;
  const minimum=vp.clientWidth<=650?300:280;
  const rows=Math.max(1,Math.floor((vp.clientHeight+14)/(minimum+14)));
  const perPage=rows*columns;
  pages=Math.max(1,Math.ceil(data.length/perPage)); page=Math.min(page,pages-1);
  vp.style.setProperty('--rows',rows);vp.style.setProperty('--cols',columns);
  const selected=data.slice(page*perPage,(page+1)*perPage);
  vp.innerHTML=data.length?`<div class="page">${selected.map(card).join('')}</div>`:'<div class="empty"><h2>No deliveries booked today</h2><p>New bookings will appear here automatically.</p></div>';
  $('pageInfo').textContent=`${page+1} / ${pages}`;
  $('previous').disabled=$('next').disabled=pages===1;
  $('summary').textContent=`${data.length} today · ${data.filter(d=>late(d)).length} late · ${data.filter(d=>state(d)==='arrived').length} arrived · ${data.filter(d=>state(d)==='completed').length} completed`;
}
function move(step){page=(page+step+pages)%pages;render();}
$('previous').onclick=()=>move(-1);$('next').onclick=()=>move(1);
$('pause').onclick=()=>{paused=!paused;$('pause').textContent=paused?'Resume':'Pause';$('pause').setAttribute('aria-pressed',String(paused));};
setInterval(()=>{if(!paused&&!document.hidden&&!$('viewport').contains(document.activeElement))move(1);},12000);
function clock(){const now=new Date();$('clock').textContent=ukTime.format(now);$('date').textContent=ukDate.format(now);const stale=failed||Date.now()-lastSuccess>45000;$('connection').classList.toggle('stale',stale);$('connection').textContent=stale?'Connection interrupted · showing last update '+ukTime.format(lastSuccess):'Live · updated '+ukTime.format(lastSuccess);}
async function refresh(){
  if(fetching)return;fetching=true;const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),10000);
  try{const response=await fetch(location.pathname+'?ajax=1',{cache:'no-store',signal:controller.signal});if(!response.ok)throw new Error('Refresh failed');const result=await response.json();if(!Array.isArray(result))throw new Error('Invalid feed');data=result;lastSuccess=Date.now();failed=false;render();}catch(e){failed=true;}finally{clearTimeout(timeout);fetching=false;clock();}
}
setInterval(refresh,15000);setInterval(clock,1000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
$('fsBtn').onclick=async()=>{try{if(document.fullscreenElement)await document.exitFullscreen();else if(document.documentElement.requestFullscreen)await document.documentElement.requestFullscreen();else $('fsBtn').textContent='Fullscreen unavailable';}catch(e){$('fsBtn').textContent='Use browser fullscreen';}};
document.addEventListener('fullscreenchange',()=>{$('fsBtn').textContent=document.fullscreenElement?'Exit fullscreen':'Fullscreen';render();});
let resizeTimer;window.addEventListener('resize',()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(render,100);});
render();clock();
