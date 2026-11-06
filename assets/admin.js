document.addEventListener('DOMContentLoaded', () => {
  const protectedAdminBrand = document.querySelector('body[data-protected-page] .admin-brand');
  if (protectedAdminBrand) {
    protectedAdminBrand.removeAttribute('href');
    protectedAdminBrand.removeAttribute('aria-label');
    protectedAdminBrand.setAttribute('aria-disabled', 'true');
    protectedAdminBrand.setAttribute('tabindex', '-1');
    protectedAdminBrand.addEventListener('click', event => event.preventDefault());
  }

  const legacySkuField = document.querySelector('input[name="sku"]');
  if (legacySkuField) {
    const legacySkuLabel = legacySkuField.previousElementSibling;
    legacySkuField.required = false;
    legacySkuField.disabled = true;
    legacySkuField.hidden = true;
    if (legacySkuLabel?.tagName === 'LABEL') legacySkuLabel.hidden = true;
  }

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

  const inventorySearch = document.querySelector('[data-inventory-search]');
  const inventoryGroups = [...document.querySelectorAll('[data-inventory-group]')];
  const inventoryEmpty = document.querySelector('[data-inventory-search-empty]');
  const inventoryCount = document.querySelector('[data-inventory-search-count]');
  const inventoryClear = document.querySelector('[data-inventory-search-clear]');
  if (inventorySearch) {
    inventorySearch.placeholder = 'Search product, category, or size';
    inventorySearch.setAttribute('aria-label', 'Search inventory by product, category, or size');
  }
  const inventoryEmptyHelp = inventoryEmpty?.querySelector('p');
  if (inventoryEmptyHelp) inventoryEmptyHelp.textContent = 'Try a different product name, category, or size.';
  const filterInventory = () => {
    if (!inventorySearch) return;
    const query = inventorySearch.value.trim().toLowerCase();
    let visible = 0;
    inventoryGroups.forEach(group => {
      const matches = (group.dataset.search || '').includes(query);
      group.hidden = !matches;
      if (matches) {
        visible += 1;
        if (query) group.open = true;
      }
    });
    if (inventoryEmpty) inventoryEmpty.hidden = visible !== 0;
    if (inventoryCount) inventoryCount.textContent = `${visible} ${visible === 1 ? 'product' : 'products'}`;
    if (inventoryClear) inventoryClear.hidden = query === '';
  };
  inventorySearch?.addEventListener('input', filterInventory);
  inventoryClear?.addEventListener('click', () => {
    if (!inventorySearch) return;
    inventorySearch.value = '';
    filterInventory();
    inventorySearch.focus();
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
