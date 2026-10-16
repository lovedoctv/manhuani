import{i as e}from"./store.C0cLnJQk.js";var t=`/manhuani/`.replace(/\/?$/,`/`),n=e=>e.replace(/[&<>"]/g,e=>({"&":`&amp;`,"<":`&lt;`,">":`&gt;`,'"':`&quot;`})[e]);async function r(){let e=await fetch(`${t}data/search-index.json`);return e.ok?e.json():[]}var i=r=>{let i=e(r.slug);return`
<article class="card" data-series="${n(r.slug)}">
  <div class="card-cover-wrap">
    <a href="${t}manga/${r.slug}/" class="card-cover">
      <img src="${n(r.cover)}" alt="${n(r.title)} cover" width="175" height="238" loading="lazy" decoding="async">
      <span class="type-badge ${n(r.type)}">${n(r.type)}</span>
    </a>
    <button type="button" class="follow-btn" data-follow="${n(r.slug)}" aria-pressed="${i}" aria-label="${i?`Remove from`:`Add to`} bookmarks">${i?`✓`:`+`}</button>
  </div>
  ${r.latest?`<div class="card-meta"><a href="${t}manga/${r.slug}/chapter-${String(r.latest.number).replace(`.`,`-`)}/">Ch.${r.latest.number}</a><time class="ago" datetime="${n(r.latest.date)}"></time></div>`:``}
  <h3 class="card-title"><a href="${t}manga/${r.slug}/">${n(r.title)}</a></h3>
  <p class="live-source">${n(r.genres.join(`, `))}</p>
</article>`};export{r as n,i as t};