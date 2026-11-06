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

  const productForm = document.querySelector('input[name="action"][value="product"]')?.closest('form');
  if (productForm) {
    productForm.enctype = 'multipart/form-data';
    const productNameField = productForm.querySelector('input[name="name"]');
    const productNameLabel = productNameField?.previousElementSibling;
    if (productNameField) {
      productNameField.required = false;
      productNameField.disabled = true;
      productNameField.hidden = true;
    }
    if (productNameLabel?.tagName === 'LABEL') productNameLabel.hidden = true;

    const categorySelect = productForm.querySelector('select[name="category_id"]');
    if (categorySelect && !productForm.querySelector('[name="gender"]')) {
      const genderLabel = document.createElement('label');
      genderLabel.textContent = 'Intended for';
      const genderSelect = document.createElement('select');
      genderSelect.name = 'gender';
      genderSelect.innerHTML = '<option value="all">All students</option><option value="girls">Girls</option><option value="boys">Boys</option>';
      categorySelect.insertAdjacentElement('afterend', genderSelect);
      genderSelect.insertAdjacentElement('beforebegin', genderLabel);
    }

    const descriptionField = productForm.querySelector('textarea[name="description"]');
    if (descriptionField && !productForm.querySelector('[name="product_image"]')) {
      const pictureLabel = document.createElement('label');
      pictureLabel.textContent = 'Product picture';
      const upload = document.createElement('div');
      upload.className = 'product-picture-upload';
      upload.innerHTML = '<input id="productPicture" type="file" name="product_image" accept="image/jpeg,image/png,image/webp"><label class="picture-button" for="productPicture"><i class="bx bx-image-add"></i><span>Choose picture</span></label><div class="picture-preview" hidden><img alt="Product picture preview"><button type="button" aria-label="Remove selected picture"><i class="bx bx-x"></i></button></div><small>JPG, PNG, or WebP · Maximum 5 MB</small>';
      descriptionField.insertAdjacentElement('afterend', pictureLabel);
      pictureLabel.insertAdjacentElement('afterend', upload);
      const fileInput = upload.querySelector('input');
      const buttonText = upload.querySelector('.picture-button span');
      const preview = upload.querySelector('.picture-preview');
      const previewImage = preview.querySelector('img');
      const clearPicture = preview.querySelector('button');
      const resetPicture = () => {
        fileInput.value = '';
        previewImage.removeAttribute('src');
        preview.hidden = true;
        buttonText.textContent = 'Choose picture';
      };
      fileInput.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (!file) return resetPicture();
        if (file.size > 5 * 1024 * 1024) {
          window.alert('Please choose a picture that is 5 MB or smaller.');
          return resetPicture();
        }
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
          window.alert('Please choose a JPG, PNG, or WebP picture.');
          return resetPicture();
        }
        buttonText.textContent = file.name;
        const reader = new FileReader();
        reader.addEventListener('load', () => {
          previewImage.src = String(reader.result);
          preview.hidden = false;
        });
        reader.readAsDataURL(file);
      });
      clearPicture.addEventListener('click', resetPicture);
    }
  }

  const button = document.querySelector('[data-menu]');
  const sidebar = document.querySelector('[data-sidebar]');
  button?.addEventListener('click', () => sidebar?.classList.toggle('open'));

  const reservationsPanel = document.querySelector('.reservations-panel');
  const reservationRows = [...document.querySelectorAll('.reservations-table tbody tr')];
  if (reservationsPanel && reservationRows.length) {
    reservationRows.forEach((row, index) => {
      row.dataset.originalOrder = String(index);
      const terminal = row.querySelector('.badge.cancelled, .badge.claimed');
      if (!terminal) return;
      const select = row.querySelector('.reservation-action select');
      const save = row.querySelector('.reservation-action button');
      if (select) select.disabled = true;
      if (save) {
        save.disabled = true;
        save.innerHTML = '<i class="bx bx-lock"></i> Locked';
      }
      row.querySelector('.reservation-action')?.classList.add('is-locked');
    });

    const toolbar = document.createElement('div');
    toolbar.className = 'reservation-tools';
    toolbar.innerHTML = '<label class="reservation-search"><i class="bx bx-search"></i><input type="search" placeholder="Search voucher or student" aria-label="Search reservations"></label><label><span>Status</span><select data-reservation-status><option value="all">All statuses</option><option value="pending">Pending</option><option value="processing">Processing</option><option value="ready">Ready</option><option value="claimed">Claimed</option><option value="cancelled">Cancelled</option></select></label><label><span>Sort by</span><select data-reservation-sort><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="total-high">Highest total</option><option value="total-low">Lowest total</option><option value="student">Student A–Z</option></select></label>';
    reservationsPanel.querySelector('.data-view-heading')?.insertAdjacentElement('afterend', toolbar);
    const search = toolbar.querySelector('input');
    const status = toolbar.querySelector('[data-reservation-status]');
    const sort = toolbar.querySelector('[data-reservation-sort]');
    const tbody = reservationsPanel.querySelector('tbody');
    const counter = reservationsPanel.querySelector('.data-count');
    const empty = document.createElement('p');
    empty.className = 'reservation-empty';
    empty.textContent = 'No reservations match your search or filter.';
    empty.hidden = true;
    reservationsPanel.querySelector('.table-wrap')?.insertAdjacentElement('afterend', empty);

    const cellText = (row, label) => row.querySelector(`[data-label="${label}"]`)?.textContent.trim() || '';
    const applyReservations = () => {
      const query = search.value.trim().toLowerCase();
      const selectedStatus = status.value;
      const sorted = [...reservationRows].sort((a, b) => {
        if (sort.value === 'oldest') return Date.parse(cellText(a, 'Date')) - Date.parse(cellText(b, 'Date'));
        if (sort.value === 'total-high' || sort.value === 'total-low') {
          const amount = row => Number(cellText(row, 'Total').replace(/[^0-9.]/g, '')) || 0;
          return sort.value === 'total-high' ? amount(b) - amount(a) : amount(a) - amount(b);
        }
        if (sort.value === 'student') return cellText(a, 'Student').localeCompare(cellText(b, 'Student'));
        return Date.parse(cellText(b, 'Date')) - Date.parse(cellText(a, 'Date'));
      });
      sorted.forEach(row => tbody.appendChild(row));
      let visible = 0;
      reservationRows.forEach(row => {
        const rowStatus = row.querySelector('.badge')?.textContent.trim().toLowerCase() || '';
        const searchable = `${cellText(row, 'Voucher')} ${cellText(row, 'Student')}`.toLowerCase();
        const matches = searchable.includes(query) && (selectedStatus === 'all' || rowStatus === selectedStatus);
        row.hidden = !matches;
        if (matches) visible += 1;
      });
      if (counter) counter.textContent = `${visible} ${visible === 1 ? 'order' : 'orders'}`;
      empty.hidden = visible !== 0;
    };
    search.addEventListener('input', applyReservations);
    status.addEventListener('change', applyReservations);
    sort.addEventListener('change', applyReservations);
  }

  const userSearch = document.querySelector('[data-user-search]');
  const userRows = [...document.querySelectorAll('[data-user-row]')];
  const emptyMessage = document.querySelector('[data-user-empty]');
  const userDirectoryHead = document.querySelector('.user-directory-head>div');
  if (userDirectoryHead) {
    const cleanupNote = document.createElement('small');
    cleanupNote.className = 'account-cleanup-note';
    cleanupNote.innerHTML = '<i class="bx bx-time-five"></i> Suspended accounts without reservations are deleted after 6 months.';
    userDirectoryHead.appendChild(cleanupNote);
  }
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
