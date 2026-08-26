document.addEventListener('DOMContentLoaded', () => {
  const button = document.querySelector('[data-menu]');
  const sidebar = document.querySelector('[data-sidebar]');
  button?.addEventListener('click', () => sidebar?.classList.toggle('open'));

  document.querySelectorAll('[data-catalog-form]').forEach((form) => {
    const levelSelect = form.querySelector('[data-level-select]');
    const itemSelect = form.querySelector('[data-item-select]');
    const price = form.querySelector('[data-product-price]');
    const addButton = form.querySelector('.add-cart');
    const imageFrame = form.closest('.catalog-group-card')?.querySelector('[data-catalog-image]');
    const photo = imageFrame?.querySelector('[data-product-photo]');
    const icon = imageFrame?.querySelector('[data-product-icon]');
    const options = Array.from(itemSelect.querySelectorAll('option[data-level]')).map((option) => ({
      value: option.value,
      level: option.dataset.level,
      price: option.dataset.price,
      available: Number(option.dataset.available || 0),
      image: option.dataset.image || '',
      label: option.textContent.trim(),
    }));

    const showImage = (source) => {
      if (!photo || !icon) return;
      if (source) {
        photo.src = source;
        photo.hidden = false;
        icon.hidden = true;
      } else {
        photo.removeAttribute('src');
        photo.hidden = true;
        icon.hidden = false;
      }
    };

    const renderItems = () => {
      const level = levelSelect.value;
      const matching = options.filter((option) => option.level === 'all' || option.level === level);
      itemSelect.replaceChildren(new Option(level ? 'Choose an item' : 'Choose an academic level first', ''));
      matching.forEach((item) => {
        const option = new Option(item.label, item.value, false, false);
        option.dataset.price = item.price;
        option.dataset.image = item.image;
        option.disabled = item.available < 1;
        itemSelect.add(option);
      });
      itemSelect.disabled = !level || matching.length === 0;
      addButton.disabled = true;
      addButton.textContent = matching.length || !level ? 'Select options' : 'Not available';
      price.textContent = 'Select an option';
      showImage(matching.find((item) => item.image)?.image || '');
    };

    levelSelect.addEventListener('input', renderItems);
    levelSelect.addEventListener('change', renderItems);
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
});
