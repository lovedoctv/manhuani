// Short "14h ago" / "4d ago" / "1w ago" dates, like comic sites use.
export function relativeTimes(scope: ParentNode = document) {
  const now = Date.now();
  scope.querySelectorAll<HTMLTimeElement>('time.ago').forEach((el) => {
    const t = Date.parse(el.dateTime);
    if (Number.isNaN(t)) return;
    const m = Math.max(0, Math.round((now - t) / 60000));
    el.title = new Date(t).toLocaleString();
    el.textContent = m < 1 ? 'just now' : m < 60 ? `${m}m ago` : m < 1440 ? `${Math.floor(m / 60)}h ago`
      : m < 10080 ? `${Math.floor(m / 1440)}d ago` : m < 43200 ? `${Math.floor(m / 10080)}w ago`
      : m < 525600 ? `${Math.floor(m / 43200)}mo ago` : `${Math.floor(m / 525600)}y ago`;
  });
}
