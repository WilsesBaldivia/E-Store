/* CSCQC E-Store administrator prototype. Replace sample records with MySQL data later. */
(function () {
  function safeText(value) {
    return String(value).replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[character]);
  }

  function loadRegisteredUsers() {
    const tableBody = document.getElementById('usersTableBody');
    if (!tableBody) return;
    let users = [];
    try { users = JSON.parse(localStorage.getItem('cscqcUsers')) || []; } catch (error) { users = []; }
    const levelNames = { college: 'College', shs: 'Senior High School', jhs: 'Junior High School' };

    users.slice().reverse().forEach(user => {
      const fullName = user.firstName + ' ' + user.lastName;
      const initials = (user.firstName.charAt(0) + user.lastName.charAt(0)).toUpperCase();
      tableBody.insertAdjacentHTML('afterbegin', '<tr data-admin-row data-level="' + safeText(user.level) + '" data-status="active" data-search="' + safeText(fullName + ' ' + user.studentId + ' ' + user.email) + '"><td><div class="user-cell"><span class="user-avatar">' + safeText(initials) + '</span><div><strong>' + safeText(fullName) + '</strong><span class="subtext">' + safeText(user.email) + '</span></div></div></td><td>' + safeText(user.studentId) + '</td><td>' + safeText(levelNames[user.level] || user.level) + '<span class="subtext">New registration</span></td><td>' + safeText(user.registered) + '</td><td>0 orders</td><td><span class="badge active" data-user-badge>Active</span></td><td><button class="table-action user-action" type="button">Suspend</button></td></tr>');
    });
    const total = document.querySelector('[data-user-total]');
    if (total) total.textContent = String(190 + users.length);
  }

  function showToast(message) {
    const toast = document.querySelector('.toast');
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('show');
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => toast.classList.remove('show'), 2200);
  }

  function filterRows() {
    const searchInput = document.querySelector('[data-admin-search]');
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
    const status = document.querySelector('[data-status-filter]')?.value || 'all';
    const category = document.querySelector('[data-category-filter]')?.value || 'all';
    const level = document.querySelector('[data-level-filter]')?.value || 'all';
    let visibleCount = 0;

    document.querySelectorAll('[data-admin-row]').forEach(row => {
      const matchesSearch = (row.dataset.search || '').toLowerCase().includes(query);
      const matchesStatus = status === 'all' || row.dataset.status === status;
      const matchesCategory = category === 'all' || row.dataset.category === category;
      const matchesLevel = level === 'all' || row.dataset.level === level;
      const visible = matchesSearch && matchesStatus && matchesCategory && matchesLevel;
      row.hidden = !visible;
      if (visible) visibleCount += 1;
    });

    const empty = document.querySelector('[data-empty-result]');
    if (empty) empty.hidden = visibleCount !== 0;
  }

  function updateReservation(button) {
    const row = button.closest('[data-admin-row]');
    const badge = row.querySelector('[data-row-status]');
    if (row.dataset.status === 'pending') {
      row.dataset.status = 'ready';
      badge.className = 'badge ready';
      badge.textContent = 'Ready';
      button.textContent = 'Mark claimed';
      showToast('Reservation marked ready for claiming');
    } else if (row.dataset.status === 'ready') {
      row.dataset.status = 'claimed';
      badge.className = 'badge claimed';
      badge.textContent = 'Claimed';
      button.textContent = 'View';
      button.classList.remove('reservation-action');
      showToast('Reservation marked as claimed');
    }
    filterRows();
  }

  function updateStock(row, amount) {
    const input = row.querySelector('[data-stock]');
    const badge = row.querySelector('[data-stock-badge]');
    input.value = Math.max(0, Number(input.value) + amount);
    const isLow = Number(input.value) <= 5;
    row.dataset.status = isLow ? 'low' : 'normal';
    badge.className = isLow ? 'badge low' : 'badge normal';
    badge.textContent = isLow ? 'Low stock' : 'Normal';
    filterRows();
  }

  function toggleUser(button) {
    const row = button.closest('[data-admin-row]');
    const badge = row.querySelector('[data-user-badge]');
    const activate = row.dataset.status === 'suspended';
    row.dataset.status = activate ? 'active' : 'suspended';
    badge.className = activate ? 'badge active' : 'badge suspended';
    badge.textContent = activate ? 'Active' : 'Suspended';
    button.textContent = activate ? 'Suspend' : 'Activate';
    showToast(activate ? 'Student account activated' : 'Student account suspended');
    filterRows();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-admin-year]').forEach(element => { element.textContent = new Date().getFullYear(); });
    loadRegisteredUsers();
    document.querySelector('.admin-menu')?.addEventListener('click', () => document.querySelector('.admin-sidebar')?.classList.toggle('open'));

    const loginForm = document.getElementById('adminLoginForm');
    if (loginForm) loginForm.addEventListener('submit', function (event) {
      event.preventDefault();
      window.location.href = 'dashboard.html';
    });

    document.querySelectorAll('[data-admin-search], [data-status-filter], [data-category-filter], [data-level-filter]').forEach(control => {
      control.addEventListener(control.tagName === 'INPUT' ? 'input' : 'change', filterRows);
    });

    document.querySelectorAll('.reservation-action').forEach(button => button.addEventListener('click', () => updateReservation(button)));
    document.querySelectorAll('.stock-action').forEach(button => button.addEventListener('click', () => {
      updateStock(button.closest('[data-admin-row]'), 10);
      showToast('Product stock increased by 10');
    }));
    document.querySelectorAll('[data-stock]').forEach(input => input.addEventListener('change', () => {
      const row = input.closest('[data-admin-row]');
      updateStock(row, 0);
      showToast('Stock quantity updated');
    }));
    document.querySelectorAll('.user-action').forEach(button => button.addEventListener('click', () => toggleUser(button)));

    document.getElementById('exportButton')?.addEventListener('click', () => showToast('Reservation report prepared for export'));
    document.getElementById('exportUsersButton')?.addEventListener('click', () => showToast('Student list prepared for export'));
    document.getElementById('addProductButton')?.addEventListener('click', () => showToast('Product form will connect to MySQL later'));
  });
})();
