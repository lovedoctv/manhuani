var e=(e,t)=>[e,t].filter(Boolean).map(e=>n(e)).join(` · `),t=`/manhuani/`.replace(/\/?$/,`/`),n=e=>e.replace(/[&<>"']/g,e=>({"&":`&amp;`,"<":`&lt;`,">":`&gt;`,'"':`&quot;`,"'":`&#39;`})[e]),r=e=>/^https?:\/\//i.test(e)?e:`#`,i=e=>`${t}title/?s=${encodeURIComponent(e)}`,a=(e,n)=>`${t}chapter/?s=${encodeURIComponent(e)}&c=${n}`,o=(e,t,i)=>e?`href="${n(a(e,t))}"`:`href="${n(r(i??`#`))}" rel="nofollow noopener" target="_blank"`,s=e=>e.charAt(0).toUpperCase()+e.slice(1),c=e=>e.replace(/-/g,` `).replace(/\b\w/g,e=>e.toUpperCase());function l(e,t){let r=e.split(/\s+/).filter(Boolean).slice(0,2).map(e=>e[0]).join(``).toUpperCase();return`<span class="live-cover" style="--h:${[...t].reduce((e,t)=>(e*31+t.charCodeAt(0))%360,7)}" aria-hidden="true"><b>${n(r)}</b><small>${n(e)}</small></span>`}async function u(e){try{let n=await fetch(t+e,{cache:`no-cache`});return n.ok?await n.json():null}catch{return null}}async function d(){await Promise.all([f(),p(),h(),_(),v()])}async function f(){let t=document.querySelector(`[data-live-series]`);if(!t)return;let r=await u(`data/live/series/${t.dataset.liveSeries}.json`);if(!r)return;let i=new Set([...t.querySelectorAll(`li`)].map(e=>e.dataset.n)),a=(r.chapters??[]).filter(e=>!i.has(String(e.number))&&e.sourceUrl).sort((e,t)=>e.number-t.number);for(let r of a){let i=document.createElement(`li`);i.dataset.n=String(r.number),i.innerHTML=`<a ${o(r.titleSlug,r.number,r.sourceUrl)}><span>Chapter ${r.number}${r.title?` – `+n(r.title):``} <em class="new-badge">NEW</em>${r.source||r.credit?` <small class="live-credit">via ${e(r.source,r.credit)}</small>`:``}</span><time class="ago" datetime="${n(r.date)}">${new Date(r.date).toLocaleDateString()}</time></a>`,t.prepend(i)}}async function p(){let e=document.querySelector(`[data-live-latest] .series-grid`);if(!e)return;let t=await u(`data/live/latest.json`);if(!t?.updates?.length)return;let n=new Map;for(let e of t.updates)e.slug&&e.sourceUrl&&n.set(e.slug,[...n.get(e.slug)??[],e]);let r=[...n.values()].slice(0,12).map(t=>{t.sort((e,t)=>t.chapter-e.chapter);let n=t[0];return e.querySelector(`[data-series="${CSS.escape(n.slug)}"]`)?.remove(),m(n,t.slice(0,2))});e.insertAdjacentHTML(`afterbegin`,r.join(``))}function m(a,s){let c=a.onSite||a.titlePage,u=a.onSite?`${t}manga/${a.slug}/`:a.titlePage?i(a.slug):r(a.sourceUrl),d=c?``:` rel="nofollow noopener" target="_blank"`,f=a.onSite&&a.cover?`<img src="${n(a.cover)}" alt="${n(a.series)} cover" width="175" height="238" loading="lazy" decoding="async">`:l(a.series,a.slug),p=s.map(e=>`<li><a ${o(e.titleSlug,e.chapter,e.sourceUrl)}>Chapter ${e.chapter}</a><time class="ago" datetime="${n(e.date)}">${new Date(e.date).toLocaleDateString()}</time></li>`).join(``);return`
<article class="card live-card" data-series="${n(a.slug)}">
  <a href="${n(u)}"${d} class="card-cover">${f}<span class="type-badge live">New</span></a>
  <div class="card-body">
    <h3 class="card-title"><a href="${n(u)}"${d}>${n(a.series)}</a></h3>
    <p class="live-source">via ${e(a.source,a.credit)}</p>
    <ul class="chapter-pills">${p}</ul>
  </div>
</article>`}async function h(){let e=document.querySelector(`[data-live-new-titles]`);if(!e)return;let t=((await u(`data/live/titles.json`))?.titles??[]).filter(e=>!e.onSite);if(!t.length)return;let n=e.querySelector(`.series-grid`),r=Number(e.dataset.limit||0),i=e=>{let i=t.filter(t=>!e||t.type===e);n.innerHTML=(r?i.slice(0,r):i).map(g).join(``)||`<p class="muted">Nothing here yet.</p>`};e.querySelectorAll(`[data-type-filter]`).forEach(t=>t.addEventListener(`click`,()=>{e.querySelectorAll(`[data-type-filter]`).forEach(e=>e.setAttribute(`aria-pressed`,String(e===t))),i(t.dataset.typeFilter)})),i(``),e.hidden=!1}function g(e){let t=i(e.slug),r=e.latestDate??e.added;return`
<article class="card live-card" data-series="${n(e.slug)}">
  <a href="${t}" class="card-cover">${l(e.title,e.slug)}${e.type?`<span class="type-badge ${n(e.type)}">${n(e.type)}</span>`:``}</a>
  <div class="card-body">
    <h3 class="card-title"><a href="${t}">${n(e.title)}</a></h3>
    <p class="live-source">via ${n(e.sources.join(` · `))}</p>
    <ul class="chapter-pills">${e.latestChapter===null?`<li><a href="${t}">Details</a><time class="ago" datetime="${n(e.added)}">${new Date(e.added).toLocaleDateString()}</time></li>`:`<li><a href="${t}">Chapter ${e.latestChapter}</a><time class="ago" datetime="${n(r)}">${new Date(r).toLocaleDateString()}</time></li>`}</ul>
  </div>
</article>`}async function _(){let i=document.querySelector(`[data-live-title]`);if(!i)return;let o=new URLSearchParams(location.search).get(`s`)??``,d=/^[a-z0-9-]{1,200}$/.test(o)?await u(`data/live/title/${o}.json`):null;if(!d){i.innerHTML=`<div class="container block"><h1>Title not found</h1><p class="muted">It may have been removed. <a href="${t}new-titles/">See all new titles</a>.</p></div>`;return}if(d.onSite){location.replace(`${t}manga/${d.slug}/`);return}document.title=`${d.title} – ${document.title.split(` | `).pop()}`;let f=d.links.filter(e=>e.kind===`read`),p=d.links.filter(e=>e.kind===`info`),m=d.chapters[0],h=d.chapters[d.chapters.length-1],g=(e,t)=>`<a class="${t}" href="${n(r(e.url))}" rel="nofollow noopener" target="_blank">${n(e.name)}</a>`,_=d.chapters.map(t=>`
    <li data-n="${t.number}"><a href="${n(a(d.slug,t.number))}">
      <span>Chapter ${t.number}${t.title?` – `+n(t.title):``}<small class="live-credit">${t.pages?.length?`Read here · `:t.readable===!1?`Release info · `:``}Source: ${e(t.source,t.credit)}</small></span>
      <time class="ago" datetime="${n(t.date)}">${new Date(t.date).toLocaleDateString()}</time></a></li>`).join(``);i.innerHTML=`
<div class="series-hero"><div class="container">
  <nav class="breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="${t}">Home</a></li><li><a href="${t}new-titles/">New Titles</a></li><li><span>${n(d.title)}</span></li></ol></nav>
  <div class="series-head">
    <div class="series-cover live-cover-wrap">${l(d.title,d.slug)}</div>
    <div class="series-info">
      ${d.type?`<span class="type-badge inline ${n(d.type)}">${n(d.type)}</span>`:``}
      <h1>${n(d.title)}</h1>
      ${d.altTitles.length?`<p class="muted alt">${d.altTitles.slice(0,6).map(n).join(` · `)}</p>`:``}
      <dl class="facts">
        ${d.status?`<div><dt>Status</dt><dd>${n(s(d.status))}</dd></div>`:``}
        ${d.type?`<div><dt>Type</dt><dd class="cap">${n(d.type)}</dd></div>`:``}
        ${d.year?`<div><dt>Released</dt><dd>${d.year}</dd></div>`:``}
        ${d.rating===null?``:`<div><dt>Rating</dt><dd>${d.rating} / 5</dd></div>`}
        <div><dt>Chapters</dt><dd>${d.chapters.length||`—`}</dd></div>
        ${d.sources.length?`<div><dt>Source</dt><dd>${n(d.sources.join(`, `))}</dd></div>`:``}
      </dl>
      ${d.genres.length?`<div class="genre-tags">${d.genres.slice(0,10).map(e=>`<span>${n(c(e))}</span>`).join(``)}</div>`:``}
      <div class="series-actions">${h?`<a class="btn" href="${n(a(d.slug,h.number))}">Chapter ${h.number}</a>`:``}${m&&m!==h?`<a class="btn" href="${n(a(d.slug,m.number))}">Latest: Chapter ${m.number}</a>`:``}${f.map(e=>g({...e,name:`Visit original source`+(f.length>1?` (${e.name})`:``)},`btn btn-ghost`)).join(``)}</div>
    </div>
  </div>
</div></div>
<div class="container block series-body">
  <section>
    <div class="section-head"><h2>Chapters</h2></div>
    ${_?`<ul class="chapter-list">${_}</ul>`:`<p class="muted">No chapters listed yet.${f.length?` Use the reading links above.`:``}</p>`}
  </section>
  <section>
    <h2>Sources</h2>
    <p class="muted small">Details and chapter lists come from ${d.credits.map(e=>e.url?g({name:e.name,url:e.url},``):n(e.name)).join(`, `)}.
    ${p.filter(e=>!d.credits.some(t=>t.url===e.url)).map(e=>g(e,``)).join(` · `)}
    Each chapter page names its original source and links to it.</p>
  </section>
</div>`}async function v(){let o=document.querySelector(`[data-live-chapter]`);if(!o)return;let s=new URLSearchParams(location.search),c=s.get(`s`)??``,l=s.get(`c`)??``,d=/^[a-z0-9-]{1,200}$/.test(c)&&/^\d{1,5}(\.\d{1,2})?$/.test(l)?await u(`data/live/title/${c}.json`):null,f=d?[...d.chapters].sort((e,t)=>e.number-t.number):[],p=f.findIndex(e=>e.number===Number(l));if(!d||p<0){o.innerHTML=`<div class="container block"><h1>Chapter not found</h1><p class="muted">It may have been removed. ${d?`<a href="${n(i(d.slug))}">See all chapters of ${n(d.title)}</a>.`:`<a href="${t}">Back to the home page</a>.`}</p></div>`;return}let m=f[p],h=f[p-1],g=f[p+1],_=d.onSite?`${t}manga/${d.slug}/`:i(d.slug),v=`${d.title} Chapter ${m.number}`;document.title=`${v} – ${document.title.split(` | `).pop()}`;let y=m.source||d.sources[0]||`the original source`,b=m.sourceUrl?r(m.sourceUrl):`#`,x=e=>`<a class="${e}" href="${n(b)}" rel="nofollow noopener" target="_blank">Visit original source</a>`,S=(m.pages??[]).filter(e=>/^data\/pages\/\d+\/\d{3,4}\.(jpg|png|webp|gif)$/.test(e)),C=e=>`<nav class="reader-nav" aria-label="Chapter navigation">
      ${h?`<a class="btn btn-ghost" href="${n(a(d.slug,h.number))}" rel="prev">&laquo; Prev</a>`:`<span class="btn btn-ghost disabled">&laquo; Prev</span>`}
      ${e?`<select class="chapter-jump" aria-label="Jump to chapter">${[...f].reverse().map(e=>`<option value="${n(a(d.slug,e.number))}"${e===m?` selected`:``}>Chapter ${e.number}</option>`).join(``)}</select>`:`<a class="btn btn-ghost" href="${n(_)}">All Chapters</a>`}
      ${g?`<a class="btn" href="${n(a(d.slug,g.number))}" rel="next">Next &raquo;</a>`:`<span class="btn disabled">Next &raquo;</span>`}
    </nav>`,w=S.length?S.map((e,r)=>`<img src="${n(t+e)}" alt="${n(v)} page ${r+1}" loading="${r<2?`eager`:`lazy`}" decoding="async">`).join(``):`<div class="reader-empty">
        <p>${m.readable===!1?`Only the release details of this chapter are known, from ${n(y)}. It isn't hosted on Aduna.fit.`:`This chapter isn't hosted on Aduna.fit. You can read it on ${n(y)}.`}</p>
        ${x(`btn`)}
      </div>`;o.innerHTML=`
<div class="container reader-wrap">
  <nav class="breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="${t}">Home</a></li><li><a href="${n(_)}">${n(d.title)}</a></li><li><span>Chapter ${m.number}</span></li></ol></nav>
  <h1 class="reader-title">${n(v)}${m.title?`<small> – ${n(m.title)}</small>`:``}</h1>
  <p class="muted center">Released <time datetime="${n(m.date)}">${new Date(m.date).toLocaleDateString()}</time> · Source: <strong>${e(y,m.credit)}</strong></p>
  ${S.length?`<p class="center">${x(`btn btn-ghost`)}</p>`:``}
  ${C(!0)}
  <div class="reader">${w}</div>
  ${C(!1)}
</div>`,o.querySelector(`.chapter-jump`)?.addEventListener(`change`,e=>location.href=e.target.value)}var y=document.querySelector(`.menu-toggle`),b=document.getElementById(`genre-nav`);y?.addEventListener(`click`,()=>{let e=b.classList.toggle(`open`);y.setAttribute(`aria-expanded`,String(e))}),document.querySelector(`.theme-toggle`)?.addEventListener(`click`,()=>{let e=document.documentElement,t=(e.dataset.theme??(matchMedia(`(prefers-color-scheme: light)`).matches?`light`:`dark`))===`dark`?`light`:`dark`;e.dataset.theme=t;try{localStorage.setItem(`theme`,t)}catch{}});function x(e=document){let t=new Intl.RelativeTimeFormat(`en`,{numeric:`auto`}),n=Date.now();e.querySelectorAll(`time.ago`).forEach(e=>{let r=Date.parse(e.dateTime);if(Number.isNaN(r))return;let i=Math.round((r-n)/6e4);i>-60?e.textContent=t.format(Math.min(i,0),`minute`):i>-1440?e.textContent=t.format(Math.round(i/60),`hour`):i>-10080&&(e.textContent=t.format(Math.round(i/1440),`day`))})}x();var S=document.querySelector(`.chapter-filter`);S?.addEventListener(`input`,()=>{let e=S.value.trim();document.querySelectorAll(`.chapter-list li`).forEach(t=>{t.hidden=e!==``&&!t.dataset.n.startsWith(e)})}),document.querySelectorAll(`.chapter-jump`).forEach(e=>e.addEventListener(`change`,()=>location.href=e.value));var C=`bookmarks`,w=()=>{try{return JSON.parse(localStorage.getItem(C)??`[]`)}catch{return[]}},T=e=>{try{localStorage.setItem(C,JSON.stringify(e))}catch{}};document.querySelectorAll(`.bookmark-btn`).forEach(e=>{let t=e.dataset.bookmark,n=()=>{let n=w().includes(t);e.textContent=n?`★ Bookmarked`:`☆ Bookmark`,e.setAttribute(`aria-pressed`,String(n))};n(),e.addEventListener(`click`,()=>{let e=w();T(e.includes(t)?e.filter(e=>e!==t):[t,...e]),n()})}),d().then(()=>x());export{w as t};