document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('input[name="first_name"], input[name="last_name"]').forEach((field) => {
    field.setAttribute('autocapitalize', 'words');
    field.addEventListener('blur', () => {
      field.value = field.value
        .trim()
        .toLocaleLowerCase()
        .replace(/(^|[\s'-])\p{L}/gu, (letter) => letter.toLocaleUpperCase());
    });
  });

  document.querySelectorAll('[data-year]').forEach((year) => {
    year.textContent = new Date().getFullYear();
  });

  const button = document.querySelector('[data-menu]');
  const sidebar = document.querySelector('[data-sidebar]');
  button?.addEventListener('click', () => sidebar?.classList.toggle('open'));

  document.querySelectorAll('[data-catalog-form]').forEach((form) => {
    const catalogCard = form.closest('.catalog-group-card');
    const catalogGroup = catalogCard?.dataset.catalogGroup || '';
    const levelSelect = form.querySelector('[data-level-select]');
    const departmentSelect = form.querySelector('[data-department-select]');
    const departmentField = form.querySelector('[data-department-field]');
    const departmentOptions = departmentSelect
      ? Array.from(departmentSelect.querySelectorAll('option[data-level]'))
      : [];
    const itemSelect = form.querySelector('[data-item-select]');
    const price = form.querySelector('[data-product-price]');
    const addButton = form.querySelector('.add-cart');
    const imageFrame = catalogCard?.querySelector('[data-catalog-image]');
    const photo = imageFrame?.querySelector('[data-product-photo]');
    const icon = imageFrame?.querySelector('[data-product-icon]');
    const zoomButton = imageFrame?.querySelector('[data-product-zoom]');
    const initialImage = photo?.getAttribute('src') || '';
    const bookGallery = catalogCard?.querySelector('[data-book-gallery]');
    const bookGalleryTitle = bookGallery?.querySelector('[data-book-gallery-title]');
    const bookGalleryCount = bookGallery?.querySelector('[data-book-gallery-count]');
    const bookGalleryGrid = bookGallery?.querySelector('[data-book-gallery-grid]');
    const bookGalleryEmpty = bookGallery?.querySelector('[data-book-gallery-empty]');
    const options = Array.from(itemSelect.querySelectorAll('option[data-level]')).map((option) => ({
      value: option.value,
      level: option.dataset.level,
      department: option.dataset.department || '',
      price: option.dataset.price,
      available: Number(option.dataset.available || 0),
      image: option.dataset.image || '',
      images: (() => {
        try {
          return JSON.parse(option.dataset.images || '[]');
        } catch (error) {
          return [];
        }
      })(),
      label: option.textContent.trim(),
    }));
    const croppedImageCache = new Map();
    let imageRequestId = 0;

    const showImage = (source, altText = '', cropSide = '') => {
      if (!photo || !icon) return;
      const requestId = ++imageRequestId;
      const displayImage = (displaySource) => {
        if (requestId !== imageRequestId) return;
        const revealPhoto = () => {
          if (requestId !== imageRequestId) return;
          photo.hidden = false;
          icon.hidden = true;
          if (zoomButton) zoomButton.disabled = false;
        };
        photo.hidden = true;
        icon.hidden = false;
        if (zoomButton) zoomButton.disabled = true;
        photo.onload = revealPhoto;
        photo.onerror = () => {
          if (requestId !== imageRequestId) return;
          photo.removeAttribute('src');
          photo.hidden = true;
          icon.hidden = false;
          if (zoomButton) zoomButton.disabled = true;
        };
        if (altText) photo.alt = altText;
        photo.src = displaySource;
        if (photo.complete && photo.naturalWidth > 0) revealPhoto();
      };
      if (source) {
        if (!cropSide) {
          displayImage(source);
          return;
        }
        const cacheKey = `${source}|${cropSide}`;
        if (croppedImageCache.has(cacheKey)) {
          displayImage(croppedImageCache.get(cacheKey));
          return;
        }
        const sourceImage = new Image();
        sourceImage.onload = () => {
          if (requestId !== imageRequestId) return;
          const halfWidth = Math.floor(sourceImage.naturalWidth / 2);
          const canvas = document.createElement('canvas');
          canvas.width = halfWidth;
          canvas.height = sourceImage.naturalHeight;
          const context = canvas.getContext('2d');
          if (!context) {
            displayImage(source);
            return;
          }
          const sourceX = cropSide === 'right' ? sourceImage.naturalWidth - halfWidth : 0;
          context.fillStyle = '#fff';
          context.fillRect(0, 0, halfWidth, sourceImage.naturalHeight);
          context.drawImage(sourceImage, sourceX, 0, halfWidth, sourceImage.naturalHeight, 0, 0, halfWidth, sourceImage.naturalHeight);
          const croppedSource = canvas.toDataURL('image/jpeg', 0.92);
          croppedImageCache.set(cacheKey, croppedSource);
          displayImage(croppedSource);
        };
        sourceImage.onerror = () => displayImage(source);
        sourceImage.src = source;
      } else {
        photo.onload = null;
        photo.onerror = null;
        photo.removeAttribute('src');
        photo.hidden = true;
        icon.hidden = false;
        if (zoomButton) zoomButton.disabled = true;
      }
    };

    const renderBookGallery = (selectedItem = null) => {
      if (!bookGallery || !bookGalleryGrid || !bookGalleryEmpty) return;
      bookGallery.hidden = !selectedItem;
      bookGalleryGrid.replaceChildren();
      if (!selectedItem) return;

      const covers = Array.isArray(selectedItem.images) ? selectedItem.images : [];
      if (bookGalleryTitle) bookGalleryTitle.textContent = `${selectedItem.department} covers`;
      if (bookGalleryCount) bookGalleryCount.textContent = covers.length
        ? `${covers.length} student ${covers.length === 1 ? 'cover' : 'covers'}`
        : 'Photos coming soon';
      bookGalleryEmpty.hidden = covers.length > 0;
      bookGalleryGrid.hidden = covers.length === 0;

      covers.forEach((cover, index) => {
        const thumbnail = document.createElement('button');
        thumbnail.type = 'button';
        thumbnail.className = 'book-cover-thumbnail';
        thumbnail.setAttribute('aria-label', `Show ${cover.alt || `cover ${index + 1}`}`);
        thumbnail.setAttribute('aria-pressed', index === 0 ? 'true' : 'false');

        const image = document.createElement('img');
        image.src = cover.path;
        image.alt = cover.alt || `${selectedItem.department} cover ${index + 1}`;
        image.loading = 'lazy';
        thumbnail.append(image);
        thumbnail.addEventListener('click', () => {
          bookGalleryGrid.querySelectorAll('.book-cover-thumbnail').forEach((button) => button.setAttribute('aria-pressed', 'false'));
          thumbnail.setAttribute('aria-pressed', 'true');
          showImage(cover.path, image.alt);
        });
        bookGalleryGrid.append(thumbnail);
      });
    };

    const updateSelectedItem = () => {
      const selected = itemSelect.selectedOptions[0];
      const hasSelection = Boolean(selected?.value);
      const selectedItem = hasSelection ? options.find((item) => item.value === selected.value) : null;
      addButton.disabled = !hasSelection;
      addButton.textContent = hasSelection ? 'Add to cart' : 'Select options';
      price.textContent = hasSelection ? `₱${Number(selected.dataset.price).toFixed(2)}` : 'Select an option';
      if (bookGallery) renderBookGallery(selectedItem);
      if (selectedItem?.images?.length) {
        showImage(selectedItem.images[0].path, selectedItem.images[0].alt || selectedItem.department);
      } else if (hasSelection) {
        const uniformCrop = catalogGroup === 'uniform'
          ? (/\bGirls\b/i.test(selectedItem?.department || '') ? 'right' : 'left')
          : '';
        showImage(selected.dataset.image || initialImage, selectedItem?.department || '', uniformCrop);
      } else if (catalogGroup === 'books') {
        showImage('');
      } else {
        showImage(initialImage);
      }
    };

    const renderItems = () => {
      const previousValue = itemSelect.value;
      const level = levelSelect.value;
      const applicableDepartments = departmentOptions.filter((option) => option.dataset.level === 'all' || option.dataset.level === level);
      const needsDepartment = Boolean(departmentSelect && applicableDepartments.length);
      if (departmentSelect) {
        departmentOptions.forEach((option) => {
          const isApplicable = applicableDepartments.includes(option);
          option.hidden = !isApplicable;
          option.disabled = !isApplicable;
        });
        const hasApplicableSelection = applicableDepartments.some((option) => option.value === departmentSelect.value);
        if (!needsDepartment) {
          departmentSelect.value = '';
        } else if (!hasApplicableSelection && catalogGroup === 'uniform') {
          departmentSelect.value = applicableDepartments[0]?.value || '';
        } else if (!hasApplicableSelection) {
          departmentSelect.value = '';
        }
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
        option.dataset.images = JSON.stringify(item.images || []);
        option.dataset.department = item.department;
        option.disabled = item.available < 1;
        itemSelect.add(option);
      });
      itemSelect.disabled = !level || awaitingDepartment || matching.length === 0;
      if (!itemSelect.disabled) {
        const selectableOptions = Array.from(itemSelect.options).filter((option) => option.value && !option.disabled);
        const retainedOption = selectableOptions.find((option) => option.value === previousValue);
        const automaticOption = retainedOption || selectableOptions[0];
        if (automaticOption) itemSelect.value = automaticOption.value;
      }
      updateSelectedItem();
      if (!matching.length && level && !awaitingDepartment) addButton.textContent = 'Not available';
    };

    levelSelect.addEventListener('input', renderItems);
    levelSelect.addEventListener('change', renderItems);
    departmentSelect?.addEventListener('input', renderItems);
    departmentSelect?.addEventListener('change', renderItems);
    itemSelect.addEventListener('change', updateSelectedItem);
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
    productZoomImage.style.width = `${productZoomScale * 100}%`;
    productZoomImage.style.height = `${productZoomScale * 100}%`;
    zoomResetButton.textContent = `${Math.round(productZoomScale * 100)}%`;
    if (zoomOutButton) zoomOutButton.disabled = productZoomScale <= 1;
    if (zoomInButton) zoomInButton.disabled = productZoomScale >= 2.5;
  };
  const closeProductZoomModal = () => {
    if (!productZoomModal) return;
    productZoomModal.hidden = true;
    document.body.classList.remove('zoom-open');
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
      document.body.classList.add('zoom-open');
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

window.addEventListener('pageshow', (event) => {
  if (document.body.hasAttribute('data-protected-page') && event.persisted) {
    window.location.reload();
  }
});
