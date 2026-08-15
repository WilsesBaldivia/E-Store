/* CSCQC E-Store front-end demo. Database functions can be connected later. */
(function () {
  const CART_KEY = 'cscqcCart';

  function getCart() {
    try { return JSON.parse(localStorage.getItem(CART_KEY)) || []; }
    catch (error) { return []; }
  }

  function saveCart(cart) {
    localStorage.setItem(CART_KEY, JSON.stringify(cart));
    updateCartCount();
  }

  function money(amount) {
    return '₱' + Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2 });
  }

  function safeText(value) {
    return String(value).replace(/[&<>'"]/g, function (character) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character];
    });
  }

  function updateCartCount() {
    const count = getCart().reduce((sum, item) => sum + item.quantity, 0);
    document.querySelectorAll('[data-cart-count]').forEach(element => { element.textContent = count; });
  }

  function showToast(message) {
    const toast = document.querySelector('.toast');
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('show');
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => toast.classList.remove('show'), 2200);
  }

  function addToCart(button) {
    const cart = getCart();
    const existing = cart.find(item => item.id === button.dataset.id);
    if (existing) existing.quantity += 1;
    else cart.push({ id: button.dataset.id, name: button.dataset.name, price: Number(button.dataset.price), quantity: 1 });
    saveCart(cart);
    showToast(button.dataset.name + ' added to your cart');
    const original = button.textContent;
    button.textContent = 'Added ✅';
    window.setTimeout(() => { button.textContent = original; }, 1000);
  }

  function cartIcon(item) {
    return item.id.includes('shirt') || item.id.includes('uniform') || item.id.includes('pants') || item.id.includes('skirt') ? '👕' : '📘';
  }

  function renderCart() {
    const container = document.getElementById('cartItems');
    if (!container) return;
    const cart = getCart();
    if (!cart.length) {
      container.innerHTML = '<div class="empty-cart"><span>🛒</span><h2>Your cart is empty</h2><p>Browse the store and add the supplies you need.</p><a class="checkout-button" href="uniform.html">Browse uniforms</a></div>';
    } else {
      container.innerHTML = cart.map(item => '<article class="cart-item" data-cart-id="' + safeText(item.id) + '">' +
        '<div class="cart-item-icon">' + cartIcon(item) + '</div><div><h3>' + safeText(item.name) + '</h3><p>CSCQC official school item</p></div>' +
        '<div class="quantity"><button type="button" data-change="-1" aria-label="Decrease quantity">−</button><span>' + item.quantity + '</span><button type="button" data-change="1" aria-label="Increase quantity">+</button></div>' +
        '<div class="cart-price"><strong>' + money(item.price * item.quantity) + '</strong><button class="remove-item" type="button">Remove</button></div></article>').join('');
    }
    updateTotals(cart);
  }

  function updateTotals(cart) {
    const total = cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
    document.querySelectorAll('[data-cart-subtotal], [data-cart-total]').forEach(element => { element.textContent = money(total); });
    const checkoutButton = document.getElementById('checkoutButton');
    if (checkoutButton) checkoutButton.classList.toggle('disabled', cart.length === 0);
  }

  function changeCartItem(target) {
    const row = target.closest('[data-cart-id]');
    if (!row) return;
    let cart = getCart();
    const item = cart.find(entry => entry.id === row.dataset.cartId);
    if (!item) return;
    if (target.classList.contains('remove-item')) cart = cart.filter(entry => entry.id !== item.id);
    if (target.dataset.change) {
      item.quantity += Number(target.dataset.change);
      if (item.quantity < 1) cart = cart.filter(entry => entry.id !== item.id);
    }
    saveCart(cart);
    renderCart();
  }

  function renderCheckout() {
    const container = document.getElementById('checkoutItems');
    if (!container) return;
    const cart = getCart();
    if (!cart.length) {
      container.innerHTML = '<div class="empty-cart"><p>No items are currently in your cart.</p><a href="uniform.html">Browse products</a></div>';
    } else {
      container.innerHTML = cart.map(item => '<div class="checkout-summary-item"><div><strong>' + safeText(item.name) + '</strong><span>Quantity: ' + item.quantity + '</span></div><b>' + money(item.price * item.quantity) + '</b></div>').join('');
    }
    updateTotals(cart);
  }

  function setupForms() {
    const loginForm = document.getElementById('loginForm');
    if (loginForm) loginForm.addEventListener('submit', event => { event.preventDefault(); window.location.href = 'homepage.html'; });

    const registerForm = document.getElementById('registerForm');
    if (registerForm) registerForm.addEventListener('submit', event => {
      event.preventDefault();
      let registeredUsers = [];
      try { registeredUsers = JSON.parse(localStorage.getItem('cscqcUsers')) || []; } catch (error) { registeredUsers = []; }
      registeredUsers.push({
        firstName: document.getElementById('firstName').value.trim(),
        lastName: document.getElementById('lastName').value.trim(),
        studentId: document.getElementById('regStudentId').value.trim(),
        email: document.getElementById('email').value.trim(),
        level: document.getElementById('academicLevel').value,
        registered: new Date().toLocaleDateString('en-PH', { month: 'short', day: '2-digit', year: 'numeric' }),
        status: 'active'
      });
      localStorage.setItem('cscqcUsers', JSON.stringify(registeredUsers));
      window.location.href = 'login.html';
    });

    const checkoutForm = document.getElementById('checkoutForm');
    if (checkoutForm) checkoutForm.addEventListener('submit', event => {
      event.preventDefault();
      if (!getCart().length) { window.alert('Your cart is empty. Please add an item first.'); return; }
      const number = 'CSC-' + String(Math.floor(1000 + Math.random() * 9000));
      document.getElementById('reservationNumber').textContent = number;
      document.getElementById('successModal').hidden = false;
      saveCart([]);
    });
  }

  function applyProductFilters() {
    const input = document.querySelector('[data-product-search]');
    const query = input ? input.value.trim().toLowerCase() : '';
    const catalog = document.getElementById('uniformCatalog');
    const activeLevel = catalog ? catalog.dataset.activeLevel || '' : '';

    document.querySelectorAll('[data-product-name]').forEach(card => {
      const matchesSearch = card.dataset.productName.toLowerCase().includes(query);
      const matchesLevel = !card.dataset.uniformLevel || !activeLevel || card.dataset.uniformLevel === activeLevel;
      card.hidden = !(matchesSearch && matchesLevel);
    });
  }

  function setupSearch() {
    const input = document.querySelector('[data-product-search]');
    if (input) input.addEventListener('input', applyProductFilters);
  }

  function setupUniformLevels() {
    const picker = document.getElementById('uniformLevels');
    const catalog = document.getElementById('uniformCatalog');
    if (!picker || !catalog) return;

    const titles = {
      college: 'College uniforms',
      jhs: 'Junior High School uniforms',
      shs: 'Senior High School uniforms'
    };

    document.querySelectorAll('[data-uniform-choice]').forEach(button => {
      button.addEventListener('click', function () {
        const level = button.dataset.uniformChoice;
        catalog.dataset.activeLevel = level;
        document.getElementById('selectedLevelTitle').textContent = titles[level];
        const search = document.querySelector('[data-product-search]');
        if (search) search.value = '';
        picker.hidden = true;
        catalog.hidden = false;
        applyProductFilters();
        catalog.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });

    document.getElementById('changeLevel').addEventListener('click', function () {
      catalog.hidden = true;
      catalog.dataset.activeLevel = '';
      picker.hidden = false;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  function setupAnnouncements() {
    const tabs = document.querySelectorAll('[data-announcement-level]');
    if (!tabs.length) return;

    tabs.forEach(tab => {
      tab.addEventListener('click', function () {
        const selectedLevel = tab.dataset.announcementLevel;
        tabs.forEach(button => {
          const isActive = button === tab;
          button.classList.toggle('active', isActive);
          button.setAttribute('aria-selected', String(isActive));
        });
        document.querySelectorAll('[data-announcement-panel]').forEach(panel => {
          panel.hidden = panel.dataset.announcementPanel !== selectedLevel;
        });
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-year]').forEach(element => { element.textContent = new Date().getFullYear(); });
    document.querySelectorAll('.add-cart').forEach(button => button.addEventListener('click', () => addToCart(button)));
    document.getElementById('cartItems')?.addEventListener('click', event => changeCartItem(event.target));
    document.querySelector('.menu-toggle')?.addEventListener('click', () => document.querySelector('.sidebar')?.classList.toggle('open'));
    const dateInput = document.getElementById('claimDate');
    if (dateInput) dateInput.min = new Date().toISOString().split('T')[0];
    updateCartCount();
    renderCart();
    renderCheckout();
    setupForms();
    setupUniformLevels();
    setupAnnouncements();
    setupSearch();
  });
})();
