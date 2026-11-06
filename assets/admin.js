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

  const logoutForm = document.querySelector('[data-admin-logout-form]');
  const logoutModal = document.querySelector('[data-admin-logout-modal]');
  const cancelLogout = logoutModal?.querySelector('[data-admin-logout-cancel]');
  const confirmLogout = logoutModal?.querySelector('[data-admin-logout-confirm]');

  const closeLogoutModal = () => {
    if (!logoutModal) return;
    logoutModal.hidden = true;
    logoutForm?.querySelector('button[type="submit"]')?.focus();
  };

  logoutForm?.addEventListener('submit', (event) => {
    if (logoutForm.dataset.confirmed === 'true' || !logoutModal) return;
    event.preventDefault();
    logoutModal.hidden = false;
    cancelLogout?.focus();
  });
  cancelLogout?.addEventListener('click', closeLogoutModal);
  confirmLogout?.addEventListener('click', () => {
    if (!logoutForm) return;
    logoutForm.dataset.confirmed = 'true';
    logoutForm.requestSubmit();
  });
  logoutModal?.addEventListener('click', (event) => {
    if (event.target === logoutModal) closeLogoutModal();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && logoutModal && !logoutModal.hidden) closeLogoutModal();
  });
});

window.addEventListener('pageshow', (event) => {
  if (document.body.hasAttribute('data-protected-page') && event.persisted) {
    window.location.reload();
  }
});
