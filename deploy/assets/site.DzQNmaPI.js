var e=(e,t)=>[e,t].filter(Boolean).map(e=>n(e)).join(` · `),t=`/manhuani/`.replace(/\/?$/,`/`),n=e=>e.replace(/[&<>"']/g,e=>({"&":`&amp;`,"<":`&lt;`,">":`&gt;`,'"':`&quot;`,"'":`&#39;`})[e]),r=e=>/^https?:\/\//i.test(e)?e:`#`,i=e=>`${t}title/?s=${encodeURIComponent(e)}`,a=e=>e.charAt(0).toUpperCase()+e.slice(1),o=e=>e.replace(/-/g,` `).replace(/\b\w/g,e=>e.toUpperCase());function s(e,t){let r=e.split(/\s+/).filter(Boolean).slice(0,2).map(e=>e[0]).join(``).toUpperCase();return`<span class="live-cover" style="--h:${[...t].reduce((e,t)=>(e*31+t.charCodeAt(0))%360,7)}" aria-hidden="true"><b>${n(r)}</b><small>${n(e)}</small></span>`}async function c(e){try{let n=await fetch(t+e,{cache:`no-cache`});return n.ok?await n.json():null}catch{return null}}async function l(){await Promise.all([u(),d(),p(),h()])}async function u(){let t=document.querySelector(`[data-live-series]`);if(!t)return;let i=await c(`data/live/series/${t.dataset.liveSeries}.json`);if(!i)return;let a=new Set([...t.querySelectorAll(`li`)].map(e=>e.dataset.n)),o=(i.chapters??[]).filter(e=>!a.has(String(e.number))&&e.sourceUrl).sort((e,t)=>e.number-t.number);for(let i of o){let a=document.createElement(`li`);a.dataset.n=String(i.number),a.innerHTML=`<a href="${n(r(i.sourceUrl))}" rel="nofollow noopener" target="_blank"><span>Chapter ${i.number}${i.title?` – `+n(i.title):``} <em class="new-badge">NEW</em>${i.source||i.credit?` <small class="live-credit">via ${e(i.source,i.credit)}</small>`:``}</span><time class="ago" datetime="${n(i.date)}">${new Date(i.date).toLocaleDateString()}</time></a>`,t.prepend(a)}}async function d(){let e=document.querySelector(`[data-live-latest] .series-grid`);if(!e)return;let t=await c(`data/live/latest.json`);if(!t?.updates?.length)return;let n=new Map;for(let e of t.updates)e.slug&&e.sourceUrl&&n.set(e.slug,[...n.get(e.slug)??[],e]);let r=[...n.values()].slice(0,12).map(t=>{t.sort((e,t)=>t.chapter-e.chapter);let n=t[0];return e.querySelector(`[data-series="${CSS.escape(n.slug)}"]`)?.remove(),f(n,t.slice(0,2))});e.insertAdjacentHTML(`afterbegin`,r.join(``))}function f(a,o){let c=a.onSite||a.titlePage,l=a.onSite?`${t}manga/${a.slug}/`:a.titlePage?i(a.slug):r(a.sourceUrl),u=c?``:` rel="nofollow noopener" target="_blank"`,d=a.onSite&&a.cover?`<img src="${n(a.cover)}" alt="${n(a.series)} cover" width="175" height="238" loading="lazy" decoding="async">`:s(a.series,a.slug),f=o.map(e=>`<li><a href="${n(r(e.sourceUrl))}" rel="nofollow noopener" target="_blank"${e.readable===!1?` title="Release info on ${n(e.source)}"`:``}>Chapter ${e.chapter}</a><time class="ago" datetime="${n(e.date)}">${new Date(e.date).toLocaleDateString()}</time></li>`).join(``);return`
<article class="card live-card" data-series="${n(a.slug)}">
  <a href="${n(l)}"${u} class="card-cover">${d}<span class="type-badge live">New</span></a>
  <div class="card-body">
    <h3 class="card-title"><a href="${n(l)}"${u}>${n(a.series)}</a></h3>
    <p class="live-source">via ${e(a.source,a.credit)}</p>
    <ul class="chapter-pills">${f}</ul>
  </div>
</article>`}async function p(){let e=document.querySelector(`[data-live-new-titles]`);if(!e)return;let t=((await c(`data/live/titles.json`))?.titles??[]).filter(e=>!e.onSite);if(!t.length)return;let n=e.querySelector(`.series-grid`),r=Number(e.dataset.limit||0),i=e=>{let i=t.filter(t=>!e||t.type===e);n.innerHTML=(r?i.slice(0,r):i).map(m).join(``)||`<p class="muted">Nothing here yet.</p>`};e.querySelectorAll(`[data-type-filter]`).forEach(t=>t.addEventListener(`click`,()=>{e.querySelectorAll(`[data-type-filter]`).forEach(e=>e.setAttribute(`aria-pressed`,String(e===t))),i(t.dataset.typeFilter)})),i(``),e.hidden=!1}function m(e){let t=i(e.slug),r=e.latestDate??e.added;return`
<article class="card live-card" data-series="${n(e.slug)}">
  <a href="${t}" class="card-cover">${s(e.title,e.slug)}${e.type?`<span class="type-badge ${n(e.type)}">${n(e.type)}</span>`:``}</a>
  <div class="card-body">
    <h3 class="card-title"><a href="${t}">${n(e.title)}</a></h3>
    <p class="live-source">via ${n(e.sources.join(` · `))}</p>
    <ul class="chapter-pills">${e.latestChapter===null?`<li><a href="${t}">Details</a><time class="ago" datetime="${n(e.added)}">${new Date(e.added).toLocaleDateString()}</time></li>`:`<li><a href="${t}">Chapter ${e.latestChapter}</a><time class="ago" datetime="${n(r)}">${new Date(r).toLocaleDateString()}</time></li>`}</ul>
  </div>
</article>`}async function h(){let i=document.querySelector(`[data-live-title]`);if(!i)return;let l=new URLSearchParams(location.search).get(`s`)??``,u=/^[a-z0-9-]{1,200}$/.test(l)?await c(`data/live/title/${l}.json`):null;if(!u){i.innerHTML=`<div class="container block"><h1>Title not found</h1><p class="muted">It may have been removed. <a href="${t}new-titles/">See all new titles</a>.</p></div>`;return}if(u.onSite){location.replace(`${t}manga/${u.slug}/`);return}document.title=`${u.title} – ${document.title.split(` | `).pop()}`;let d=u.links.filter(e=>e.kind===`read`),f=u.links.filter(e=>e.kind===`info`),p=(e,t)=>`<a class="${t}" href="${n(r(e.url))}" rel="nofollow noopener" target="_blank">${n(e.name)}</a>`,m=u.chapters.map(t=>`
    <li data-n="${t.number}"><a href="${n(r(t.sourceUrl??`#`))}" rel="nofollow noopener" target="_blank"${t.readable===!1?` title="Release info on ${n(t.source??``)}"`:``}>
      <span>Chapter ${t.number}${t.title?` – `+n(t.title):``}<small class="live-credit">${t.readable===!1?`Release info · `:``}via ${e(t.source,t.credit)}</small></span>
      <time class="ago" datetime="${n(t.date)}">${new Date(t.date).toLocaleDateString()}</time></a></li>`).join(``);i.innerHTML=`
<div class="series-hero"><div class="container">
  <nav class="breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="${t}">Home</a></li><li><a href="${t}new-titles/">New Titles</a></li><li><span>${n(u.title)}</span></li></ol></nav>
  <div class="series-head">
    <div class="series-cover live-cover-wrap">${s(u.title,u.slug)}</div>
    <div class="series-info">
      ${u.type?`<span class="type-badge inline ${n(u.type)}">${n(u.type)}</span>`:``}
      <h1>${n(u.title)}</h1>
      ${u.altTitles.length?`<p class="muted alt">${u.altTitles.slice(0,6).map(n).join(` · `)}</p>`:``}
      <dl class="facts">
        ${u.status?`<div><dt>Status</dt><dd>${n(a(u.status))}</dd></div>`:``}
        ${u.type?`<div><dt>Type</dt><dd class="cap">${n(u.type)}</dd></div>`:``}
        ${u.year?`<div><dt>Released</dt><dd>${u.year}</dd></div>`:``}
        ${u.rating===null?``:`<div><dt>Rating</dt><dd>${u.rating} / 5</dd></div>`}
        <div><dt>Chapters</dt><dd>${u.chapters.length||`—`}</dd></div>
      </dl>
      ${u.genres.length?`<div class="genre-tags">${u.genres.slice(0,10).map(e=>`<span>${n(o(e))}</span>`).join(``)}</div>`:``}
      <div class="series-actions">${d.map((e,t)=>p({...e,name:`Read on `+e.name},t?`btn btn-ghost`:`btn`)).join(``)}</div>
    </div>
  </div>
</div></div>
<div class="container block series-body">
  <section>
    <div class="section-head"><h2>Chapters</h2></div>
    ${m?`<ul class="chapter-list">${m}</ul>`:`<p class="muted">No chapters listed yet.${d.length?` Use the reading links above.`:``}</p>`}
  </section>
  <section>
    <h2>Sources</h2>
    <p class="muted small">Details and chapter lists come from ${u.credits.map(e=>e.url?p({name:e.name,url:e.url},``):n(e.name)).join(`, `)}.
    ${f.filter(e=>!u.credits.some(t=>t.url===e.url)).map(e=>p(e,``)).join(` · `)}
    Chapters open on the site that publishes them.</p>
  </section>
</div>`}var g=document.querySelector(`.menu-toggle`),_=document.getElementById(`genre-nav`);g?.addEventListener(`click`,()=>{let e=_.classList.toggle(`open`);g.setAttribute(`aria-expanded`,String(e))}),document.querySelector(`.theme-toggle`)?.addEventListener(`click`,()=>{let e=document.documentElement,t=(e.dataset.theme??(matchMedia(`(prefers-color-scheme: light)`).matches?`light`:`dark`))===`dark`?`light`:`dark`;e.dataset.theme=t;try{localStorage.setItem(`theme`,t)}catch{}});function v(e=document){let t=new Intl.RelativeTimeFormat(`en`,{numeric:`auto`}),n=Date.now();e.querySelectorAll(`time.ago`).forEach(e=>{let r=Date.parse(e.dateTime);if(Number.isNaN(r))return;let i=Math.round((r-n)/6e4);i>-60?e.textContent=t.format(Math.min(i,0),`minute`):i>-1440?e.textContent=t.format(Math.round(i/60),`hour`):i>-10080&&(e.textContent=t.format(Math.round(i/1440),`day`))})}v();var y=document.querySelector(`.chapter-filter`);y?.addEventListener(`input`,()=>{let e=y.value.trim();document.querySelectorAll(`.chapter-list li`).forEach(t=>{t.hidden=e!==``&&!t.dataset.n.startsWith(e)})}),document.querySelectorAll(`.chapter-jump`).forEach(e=>e.addEventListener(`change`,()=>location.href=e.value));var b=`bookmarks`,x=()=>{try{return JSON.parse(localStorage.getItem(b)??`[]`)}catch{return[]}},S=e=>{try{localStorage.setItem(b,JSON.stringify(e))}catch{}};document.querySelectorAll(`.bookmark-btn`).forEach(e=>{let t=e.dataset.bookmark,n=()=>{let n=x().includes(t);e.textContent=n?`★ Bookmarked`:`☆ Bookmark`,e.setAttribute(`aria-pressed`,String(n))};n(),e.addEventListener(`click`,()=>{let e=x();S(e.includes(t)?e.filter(e=>e!==t):[t,...e]),n()})}),l().then(()=>v());export{x as t};