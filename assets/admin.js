document.addEventListener('DOMContentLoaded', () => {
  const button = document.querySelector('[data-menu]');
  const sidebar = document.querySelector('[data-sidebar]');
  button?.addEventListener('click', () => sidebar?.classList.toggle('open'));

  const userSearch = document.querySelector('[data-user-search]');
  const userRows = [...document.querySelectorAll('[data-user-row]')];
  const emptyMessage = document.querySelector('[data-user-empty]');
  userSearch?.addEventListener('input', () => {
    const query = userSearch.value.trim().toLowerCase();
    let visible = 0;
    userRows.forEach(row => {
      const matches = (row.dataset.search || '').includes(query);
      row.hidden = !matches;
      if (matches) visible += 1;
    });
    if (emptyMessage) emptyMessage.hidden = visible !== 0;
  });
});
