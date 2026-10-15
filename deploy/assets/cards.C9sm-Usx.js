var e=`/manhuani/`.replace(/\/?$/,`/`),t=e=>e.replace(/[&<>"]/g,e=>({"&":`&amp;`,"<":`&lt;`,">":`&gt;`,'"':`&quot;`})[e]);async function n(){let t=await fetch(`${e}data/search-index.json`);return t.ok?t.json():[]}var r=n=>`
<article class="card">
  <a href="${e}manga/${n.slug}/" class="card-cover">
    <img src="${t(n.cover)}" alt="${t(n.title)} cover" width="175" height="238" loading="lazy" decoding="async">
    <span class="type-badge ${t(n.type)}">${t(n.type)}</span>
  </a>
  <div class="card-body">
    <h3 class="card-title"><a href="${e}manga/${n.slug}/">${t(n.title)}</a></h3>
    <p class="muted small">${t(n.genres.join(`, `))}</p>
    ${n.latest?`<ul class="chapter-pills"><li><a href="${e}manga/${n.slug}/chapter-${String(n.latest.number).replace(`.`,`-`)}/">Chapter ${n.latest.number}</a></li></ul>`:``}
  </div>
</article>`;export{n,r as t};