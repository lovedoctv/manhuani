var e=e=>e.replace(/[&<>"]/g,e=>({"&":`&amp;`,"<":`&lt;`,">":`&gt;`,'"':`&quot;`})[e]);async function t(){let e=await fetch(`/data/search-index.json`);return e.ok?e.json():[]}var n=t=>`
<article class="card">
  <a href="/manga/${t.slug}/" class="card-cover">
    <img src="${e(t.cover)}" alt="${e(t.title)} cover" width="175" height="238" loading="lazy" decoding="async">
    <span class="type-badge ${e(t.type)}">${e(t.type)}</span>
  </a>
  <div class="card-body">
    <h3 class="card-title"><a href="/manga/${t.slug}/">${e(t.title)}</a></h3>
    <p class="muted small">${e(t.genres.join(`, `))}</p>
    ${t.latest?`<ul class="chapter-pills"><li><a href="/manga/${t.slug}/chapter-${String(t.latest.number).replace(`.`,`-`)}/">Chapter ${t.latest.number}</a></li></ul>`:``}
  </div>
</article>`;export{t as n,n as t};