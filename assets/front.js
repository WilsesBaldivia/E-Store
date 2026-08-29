document.addEventListener('DOMContentLoaded', () => {
  const button = document.querySelector('[data-menu]');
  const sidebar = document.querySelector('[data-sidebar]');
  button?.addEventListener('click', () => sidebar?.classList.toggle('open'));

  document.querySelectorAll('[data-catalog-form]').forEach((form) => {
    const levelSelect = form.querySelector('[data-level-select]');
    const departmentSelect = form.querySelector('[data-department-select]');
    const departmentField = form.querySelector('[data-department-field]');
    const itemSelect = form.querySelector('[data-item-select]');
    const price = form.querySelector('[data-product-price]');
    const addButton = form.querySelector('.add-cart');
    const imageFrame = form.closest('.catalog-group-card')?.querySelector('[data-catalog-image]');
    const photo = imageFrame?.querySelector('[data-product-photo]');
    const icon = imageFrame?.querySelector('[data-product-icon]');
    const zoomButton = imageFrame?.querySelector('[data-product-zoom]');
    const options = Array.from(itemSelect.querySelectorAll('option[data-level]')).map((option) => ({
      value: option.value,
      level: option.dataset.level,
      department: option.dataset.department || '',
      price: option.dataset.price,
      available: Number(option.dataset.available || 0),
      image: option.dataset.image || '',
      label: option.textContent.trim(),
    }));

    const showImage = (source) => {
      if (!photo || !icon) return;
      if (source) {
        const revealPhoto = () => {
          photo.hidden = false;
          icon.hidden = true;
          if (zoomButton) zoomButton.disabled = false;
        };
        photo.hidden = true;
        icon.hidden = false;
        if (zoomButton) zoomButton.disabled = true;
        photo.onload = revealPhoto;
        photo.onerror = () => {
          photo.removeAttribute('src');
          photo.hidden = true;
          icon.hidden = false;
          if (zoomButton) zoomButton.disabled = true;
        };
        photo.src = source;
        if (photo.complete && photo.naturalWidth > 0) revealPhoto();
      } else {
        photo.onload = null;
        photo.onerror = null;
        photo.removeAttribute('src');
        photo.hidden = true;
        icon.hidden = false;
        if (zoomButton) zoomButton.disabled = true;
      }
    };

    const renderItems = () => {
      const level = levelSelect.value;
      const departmentLevel = departmentSelect?.dataset.departmentLevel || '';
      const needsDepartment = Boolean(departmentSelect && (!departmentLevel || departmentLevel === level));
      if (departmentSelect) {
        if (!needsDepartment) departmentSelect.value = '';
        departmentSelect.disabled = !needsDepartment;
        departmentSelect.required = needsDepartment;
      }
      if (departmentField) departmentField.hidden = !needsDepartment;
      const department = needsDepartment ? departmentSelect.value : '';
      const levelMatching = options.filter((option) => option.level === 'all' || option.level === level);
      const matching = levelMatching.filter((option) => !needsDepartment || option.department === department);
      const awaitingDepartment = Boolean(needsDepartment && !department);
      const placeholder = !level
        ? 'Choose an academic level first'
        : awaitingDepartment
          ? 'Choose a department first'
          : 'Choose a size or item';
      itemSelect.replaceChildren(new Option(placeholder, ''));
      matching.forEach((item) => {
        const option = new Option(item.label, item.value, false, false);
        option.dataset.price = item.price;
        option.dataset.image = item.image;
        option.dataset.department = item.department;
        option.disabled = item.available < 1;
        itemSelect.add(option);
      });
      itemSelect.disabled = !level || awaitingDepartment || matching.length === 0;
      addButton.disabled = true;
      addButton.textContent = matching.length || !level || awaitingDepartment ? 'Select options' : 'Not available';
      price.textContent = 'Select an option';
      showImage(matching.find((item) => item.image)?.image || '');
    };

    levelSelect.addEventListener('input', renderItems);
    levelSelect.addEventListener('change', renderItems);
    departmentSelect?.addEventListener('input', renderItems);
    departmentSelect?.addEventListener('change', renderItems);
    itemSelect.addEventListener('change', () => {
      const selected = itemSelect.selectedOptions[0];
      const hasSelection = Boolean(selected?.value);
      addButton.disabled = !hasSelection;
      addButton.textContent = hasSelection ? 'Add to cart' : 'Select options';
      price.textContent = hasSelection ? `₱${Number(selected.dataset.price).toFixed(2)}` : 'Select an option';
      if (hasSelection) showImage(selected.dataset.image || '');
    });
    renderItems();
    window.addEventListener('pageshow', renderItems);
    window.setTimeout(renderItems, 0);
  });

  const productZoomModal = document.querySelector('[data-product-zoom-modal]');
  const productZoomImage = productZoomModal?.querySelector('[data-product-zoom-image]');
  const productZoomTitle = productZoomModal?.querySelector('[data-product-zoom-title]');
  const closeProductZoom = productZoomModal?.querySelector('[data-product-zoom-close]');
  const zoomOutButton = productZoomModal?.querySelector('[data-zoom-out]');
  const zoomResetButton = productZoomModal?.querySelector('[data-zoom-reset]');
  const zoomInButton = productZoomModal?.querySelector('[data-zoom-in]');
  let productZoomScale = 1;
  let activeZoomTrigger = null;

  const renderProductZoom = () => {
    if (!productZoomImage || !zoomResetButton) return;
    productZoomImage.style.transform = `scale(${productZoomScale})`;
    zoomResetButton.textContent = `${Math.round(productZoomScale * 100)}%`;
    if (zoomOutButton) zoomOutButton.disabled = productZoomScale <= 1;
    if (zoomInButton) zoomInButton.disabled = productZoomScale >= 2.5;
  };
  const closeProductZoomModal = () => {
    if (!productZoomModal) return;
    productZoomModal.hidden = true;
    activeZoomTrigger?.focus();
  };

  document.querySelectorAll('[data-product-zoom]').forEach((zoomTrigger) => {
    zoomTrigger.addEventListener('click', () => {
      const photo = zoomTrigger.closest('[data-catalog-image]')?.querySelector('[data-product-photo]');
      if (!productZoomModal || !productZoomImage || !photo || photo.hidden || !photo.src) return;
      activeZoomTrigger = zoomTrigger;
      productZoomScale = 1;
      productZoomImage.src = photo.currentSrc || photo.src;
      productZoomImage.alt = photo.alt;
      if (productZoomTitle) productZoomTitle.textContent = photo.alt || 'Product image';
      productZoomModal.hidden = false;
      renderProductZoom();
      closeProductZoom?.focus();
    });
    zoomTrigger.closest('[data-catalog-image]')?.querySelector('[data-product-photo]')?.addEventListener('click', () => {
      if (!zoomTrigger.disabled) zoomTrigger.click();
    });
  });
  zoomOutButton?.addEventListener('click', () => {
    productZoomScale = Math.max(1, productZoomScale - 0.25);
    renderProductZoom();
  });
  zoomResetButton?.addEventListener('click', () => {
    productZoomScale = 1;
    renderProductZoom();
  });
  zoomInButton?.addEventListener('click', () => {
    productZoomScale = Math.min(2.5, productZoomScale + 0.25);
    renderProductZoom();
  });
  closeProductZoom?.addEventListener('click', closeProductZoomModal);
  productZoomModal?.addEventListener('click', (event) => {
    if (event.target === productZoomModal) closeProductZoomModal();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && productZoomModal && !productZoomModal.hidden) closeProductZoomModal();
  });

  const removeModal = document.querySelector('[data-remove-modal]');
  const removeName = removeModal?.querySelector('[data-remove-name]');
  const cancelRemove = removeModal?.querySelector('[data-remove-cancel]');
  const confirmRemove = removeModal?.querySelector('[data-remove-confirm]');
  let pendingRemoveButton = null;

  const closeRemoveModal = () => {
    if (!removeModal) return;
    removeModal.hidden = true;
    pendingRemoveButton?.focus();
  };

  document.querySelectorAll('[data-remove-item]').forEach((removeButton) => {
    removeButton.addEventListener('click', (event) => {
      if (removeButton.dataset.confirmed === 'true' || !removeModal) return;
      event.preventDefault();
      pendingRemoveButton = removeButton;
      removeName.textContent = removeButton.dataset.itemName || 'Selected item';
      removeModal.hidden = false;
      cancelRemove?.focus();
    });
  });

  cancelRemove?.addEventListener('click', closeRemoveModal);
  confirmRemove?.addEventListener('click', () => {
    if (!pendingRemoveButton) return;
    pendingRemoveButton.dataset.confirmed = 'true';
    pendingRemoveButton.form.requestSubmit(pendingRemoveButton);
  });
  removeModal?.addEventListener('click', (event) => {
    if (event.target === removeModal) closeRemoveModal();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && removeModal && !removeModal.hidden) closeRemoveModal();
  });

  const logoutForm = document.querySelector('[data-logout-form]');
  const logoutModal = document.querySelector('[data-logout-modal]');
  const cancelLogout = logoutModal?.querySelector('[data-logout-cancel]');
  const confirmLogout = logoutModal?.querySelector('[data-logout-confirm]');

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
