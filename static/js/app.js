"use strict";

/* API CONFIGURATION */
const API_BASE = 'api';

/* STATE */
const state = {
  user: null,
  records: [],
  accounts: [],
  categories: [],
  transferCategories: [],
  debts: [],
  ui: {
    mode: 'expense',
    fromAccount: null,
    toAccount: null,
    category: null,
    transferCategory: null,
    newCategoryType: 'expense',
    recordFilter: 'all',
    selectMode: 'from' // 'from', 'to', 'category'
  }
};

/* DOM HELPERS */
const $ = (id) => document.getElementById(id);

const dom = {
  recordList: $("recordList"),
  accountsList: $("accountsList"),
  accountOptions: $("accountOptions"),
  categoryOptions: $("categoryOptions"),
  incomeAmount: $("incomeAmount"),
  expenseAmount: $("expenseAmount"),
  totalAmount: $("totalAmount"),
  display: $("amountDisplay"),
  fromText: $("fromText"),
  toText: $("toText"),
  toLabel: $("toLabel"),
  error: $("formError"),
  recordFilter: $("recordTypeFilter"),
  userGreeting: $("userGreeting"),
  selectAccountBtn: $("selectAccountBtn"),
  selectCategoryBtn: $("selectCategoryBtn"),
  selectTransferCategoryBtn: $("selectTransferCategoryBtn"),
  recordDescription: $("recordDescription"),
  // Debt DOM references
  debtsList: $("debtsList"),
  debtSummary: $("debtSummary"),
  debtTotalAmount: $("debtTotalAmount"),
  debtPaidAmount: $("debtPaidAmount"),
  debtRemainingAmount: $("debtRemainingAmount"),
  // Transfer category options
  transferCategoryOptions: $("transferCategoryOptions")
};

/* UTILITY FUNCTIONS */
function toggleOverlay(id, show) {
  const el = $(id);
  if (el) el.hidden = !show;
}

function setError(message = "") {
  dom.error.textContent = message;
  dom.error.classList.toggle("hidden", !message);
}

function formatCurrency(amount) {
  return '$' + Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function resetRecordForm() {
  state.ui.mode = 'expense';
  state.ui.fromAccount = null;
  state.ui.toAccount = null;
  state.ui.category = null;
  state.ui.transferCategory = null;
  dom.display.value = "";
  dom.fromText.textContent = "\u2014";
  dom.toLabel.innerHTML = 'Category: <span id="toText">\u2014</span>';
  // Refresh toText DOM reference after innerHTML replacement
  dom.toText = $("toText");
  dom.recordDescription.value = "";
  setError();

  document.querySelectorAll("[data-mode]").forEach(btn => {
    btn.classList.toggle("active", btn.dataset.mode === 'expense');
  });
  
  // Show category button, hide for transfer; reset transfer category
  dom.selectCategoryBtn.style.display = 'block';
  dom.selectTransferCategoryBtn.style.display = 'none';
  dom.selectTransferCategoryBtn.textContent = 'Transfer Category';
}

function updateModeUI() {
  const mode = state.ui.mode;
  if (mode === 'transfer') {
    dom.selectCategoryBtn.style.display = 'none';
    dom.selectTransferCategoryBtn.style.display = 'block';
    dom.toLabel.innerHTML = 'To: <span id="toText">—</span>';
  } else {
    dom.selectCategoryBtn.style.display = 'block';
    dom.selectTransferCategoryBtn.style.display = 'none';
    dom.toLabel.innerHTML = 'Category: <span id="toText">—</span>';
  }
  // Refresh toText DOM reference after innerHTML replacement
  dom.toText = $("toText");
}

function showLoading(element, message = 'Loading...') {
  element.innerHTML = `<li class="loading">${message}</li>`;
}

function showMessage(element, message) {
  element.innerHTML = `<li class="empty-message">${message}</li>`;
}

/* API FUNCTIONS */
async function apiCall(endpoint, method = 'GET', data = null) {
  const options = {
    method,
    headers: {
      'Content-Type': 'application/json'
    },
    credentials: 'include'
  };

  if (data && method !== 'GET') {
    options.body = JSON.stringify(data);
  }

  const response = await fetch(`${API_BASE}/${endpoint}`, options);
  const result = await response.json();

  if (!response.ok && response.status === 401) {
    // Not authenticated, redirect to login
    window.location.href = 'login.html';
    return null;
  }

  return result;
}

/* AUTH FUNCTIONS */
async function checkAuth() {
  try {
    const result = await apiCall('auth.php?action=check');
    if (result && result.success && result.data.authenticated) {
      state.user = result.data.user;
      dom.userGreeting.textContent = `Hello, ${state.user.username}!`;
      return true;
    }
    return false;
  } catch (error) {
    console.error('Auth check failed:', error);
    return false;
  }
}

async function logout() {
  try {
    await apiCall('auth.php?action=logout');
    window.location.href = 'login.html';
  } catch (error) {
    console.error('Logout failed:', error);
  }
}

/* DATA FETCHING */
async function fetchAccounts() {
  try {
    const result = await apiCall('accounts.php');
    if (result && result.success) {
      state.accounts = result.data.accounts;
      renderAccounts();
    }
  } catch (error) {
    console.error('Failed to fetch accounts:', error);
    showMessage(dom.accountsList, 'Failed to load accounts');
  }
}

async function fetchCategories() {
  try {
    const result = await apiCall('categories.php');
    if (result && result.success) {
      state.categories = result.data.categories;
    }
  } catch (error) {
    console.error('Failed to fetch categories:', error);
  }
}


async function fetchTransferCategories() {
  try {
    const result = await apiCall('transfer-categories.php');
    if (result && result.success) {
      state.transferCategories = result.data.categories;
    }
  } catch (error) {
    console.error('Failed to fetch transfer categories:', error);
  }
}

async function fetchDebts() {
  try {
    showLoading(dom.debtsList, 'Loading debts...');
    const result = await apiCall('debts.php');
    if (result && result.success) {
      state.debts = result.data.debts;
      renderDebts();
      updateDebtSummary(result.data.summary);
    }
  } catch (error) {
    console.error('Failed to fetch debts:', error);
    showMessage(dom.debtsList, 'Failed to load debts');
  }
}

async function fetchRecords() {
  try {
    showLoading(dom.recordList, 'Loading records...');
    
    const type = state.ui.recordFilter === 'all' ? '' : `type=${state.ui.recordFilter}`;
    const result = await apiCall(`records.php${type ? '?' + type : ''}`);
    
    if (result && result.success) {
      state.records = result.data.records;
      renderRecords();
      updateSummary(result.data.summary);
    }
  } catch (error) {
    console.error('Failed to fetch records:', error);
    showMessage(dom.recordList, 'Failed to load records');
  }
}

async function fetchSummary() {
  try {
    const result = await apiCall('summary.php');
    if (result && result.success) {
      updateSummary(result.data.summary);
      renderCharts(result.data.expense_breakdown, result.data.income_breakdown);
    }
  } catch (error) {
    console.error('Failed to fetch summary:', error);
  }
}

/* RENDERING */
function renderRecords() {
  dom.recordList.innerHTML = "";

  if (!state.records.length) {
    dom.recordList.classList.add("empty");
    dom.recordList.innerHTML = "<li class='empty-message'>No records yet. Add your first record!</li>";
    return;
  }

  dom.recordList.classList.remove("empty");

  state.records.forEach(r => {
    const li = document.createElement("li");
    li.className = `record ${r.type}`;
    li.dataset.id = r.id;
    
    const date = new Date(r.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    const sign = r.type === "expense" ? "-" : "+";
    
    const transferCatLabel = r.type === 'transfer' && r.transfer_category_name 
      ? '<span class="transfer-category-label">' + r.transfer_category_name + '</span>' : '';
    li.innerHTML = `
      <div class="record-info">
        <span class="record-category">${r.category_name || r.transfer_category_name || r.description || r.type}</span>
        <span class="record-date">${date}</span>
        ${transferCatLabel}
      </div>
      <div class="record-amount">
        <strong>${sign}${formatCurrency(r.amount)}</strong>
        <button class="delete-btn" data-delete="${r.id}" title="Delete">×</button>
      </div>
    `;
    dom.recordList.appendChild(li);
  });
}

function renderAccounts() {
  dom.accountsList.innerHTML = "";
  dom.accountOptions.innerHTML = "";

  if (!state.accounts.length) {
    dom.accountsList.innerHTML = "<li class='empty-message'>No accounts. Add one to get started!</li>";
    return;
  }

  state.accounts.forEach(acc => {
    // Render in accounts list
    const li = document.createElement("li");
    li.className = "account";
    li.innerHTML = `
      <span class="account-name">
        ${acc.icon === 'wallet' ? '💰' : '🏦'} ${acc.name}
        ${acc.is_default ? '<span class="default-badge">Default</span>' : ''}
      </span>
      <strong>${formatCurrency(acc.balance)}</strong>
    `;
    dom.accountsList.appendChild(li);

    // Render in account options
    const btn = document.createElement("button");
    btn.textContent = acc.name;
    btn.dataset.accountId = acc.id;
    btn.dataset.balance = acc.balance;
    dom.accountOptions.appendChild(btn);
  });
}

function renderCategories() {
  dom.categoryOptions.innerHTML = "";

  const filtered = state.categories.filter(c => c.type === state.ui.mode);
  
  if (!filtered.length) {
    dom.categoryOptions.innerHTML = "<li class='empty-message'>No categories. Add one from the menu!</li>";
    return;
  }

  filtered.forEach(c => {
    const btn = document.createElement("button");
    btn.textContent = c.name;
    btn.dataset.categoryId = c.id;
    dom.categoryOptions.appendChild(btn);
  });
}

function renderDebts() {
  dom.debtsList.innerHTML = "";

  if (!state.debts.length) {
    dom.debtsList.classList.add("empty");
    dom.debtsList.innerHTML = "<li class='empty-message'>No debts yet. Add a debt or loan to track!</li>";
    dom.debtSummary.hidden = true;
    return;
  }

  dom.debtsList.classList.remove("empty");
  dom.debtSummary.hidden = false;

  state.debts.forEach(debt => {
    const li = document.createElement("li");
    const isPaidOff = debt.remaining <= 0;
    li.className = "debt-item" + (isPaidOff ? " paid-off" : "");
    li.dataset.id = debt.id;
    
    const dueDate = debt.due_date ? new Date(debt.due_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
    const progress = debt.amount > 0 ? Math.round((debt.paid / debt.amount) * 100) : 0;
    
    li.innerHTML = `
      <div class="debt-info">
        <span class="debt-name">${debt.name}</span>
        <span class="debt-account">${debt.account_name || 'Unknown account'}</span>
        ${dueDate ? '<span class="debt-due">Due: ' + dueDate + '</span>' : ''}
      </div>
      <div class="debt-amounts">
        <span class="debt-remaining-amount">${formatCurrency(debt.remaining)}</span>
        <span class="debt-progress">${formatCurrency(debt.paid)} / ${formatCurrency(debt.amount)} (${progress}%)</span>
      </div>
      <div class="debt-actions">
        <button class="debt-action-btn pay-btn" data-action="pay-debt" data-debt-id="${debt.id}" title="Record payment">↑</button>
        <button class="debt-action-btn edit-btn" data-action="edit-debt" data-debt-id="${debt.id}" title="Edit">✎</button>
        <button class="debt-action-btn delete-btn" data-action="delete-debt" data-debt-id="${debt.id}" title="Delete">×</button>
      </div>
    `;
    dom.debtsList.appendChild(li);
  });
}

function updateDebtSummary(summary) {
  if (!summary) return;
  dom.debtTotalAmount.textContent = formatCurrency(summary.total_debt || 0);
  dom.debtPaidAmount.textContent = formatCurrency(summary.total_paid || 0);
  dom.debtRemainingAmount.textContent = formatCurrency(summary.total_remaining || 0);
}

function renderTransferCategories() {
  if (!dom.transferCategoryOptions) return;
  dom.transferCategoryOptions.innerHTML = "";

  if (!state.transferCategories.length) {
    dom.transferCategoryOptions.innerHTML = "<li class='empty-message'>No transfer categories</li>";
    return;
  }

  state.transferCategories.forEach(tc => {
    const btn = document.createElement("button");
    btn.textContent = tc.name;
    btn.dataset.transferCategoryId = tc.id;
    dom.transferCategoryOptions.appendChild(btn);
  });
}

function populateDebtAccountSelect() {
  const select = document.getElementById("debtAccountSelect");
  if (!select) return;
  select.innerHTML = '<option value="">Select account</option>';
  state.accounts.forEach(acc => {
    const option = document.createElement("option");
    option.value = acc.id;
    option.textContent = acc.name + ' (' + formatCurrency(acc.balance) + ')';
    select.appendChild(option);
  });
}

function renderTransferToOptions() {
  const transferToOptions = $("transferToOptions");
  transferToOptions.innerHTML = "";

  const filtered = state.accounts.filter(a => a.id !== state.ui.fromAccount);
  
  if (!filtered.length) {
    transferToOptions.innerHTML = "<li class='empty-message'>No other accounts available</li>";
    return;
  }

  filtered.forEach(acc => {
    const btn = document.createElement("button");
    btn.textContent = `${acc.name} (${formatCurrency(acc.balance)})`;
    btn.dataset.accountId = acc.id;
    transferToOptions.appendChild(btn);
  });
}

function updateSummary(summary) {
  dom.incomeAmount.textContent = formatCurrency(summary.total_income || 0);
  dom.expenseAmount.textContent = formatCurrency(summary.total_expense || 0);
  dom.totalAmount.textContent = formatCurrency(summary.net_amount || 0);
  
  // Update total card color based on balance
  const totalCard = dom.totalAmount.closest('.card');
  if (summary.net_amount >= 0) {
    totalCard.classList.remove('negative');
    totalCard.classList.add('positive');
  } else {
    totalCard.classList.remove('positive');
    totalCard.classList.add('negative');
  }
}

/* CHART.JS */
let expenseChart, incomeChart;

function initCharts() {
  const chartOptions = {
    responsive: true,
    maintainAspectRatio: true,
    plugins: {
      legend: {
        position: "bottom",
        labels: {
          color: '#f2f2f2',
          padding: 15
        }
      }
    }
  };

  expenseChart = new Chart(document.getElementById("expenseChart").getContext("2d"), {
    type: "doughnut",
    data: { labels: [], datasets: [{ data: [], backgroundColor: [] }] },
    options: chartOptions
  });

  incomeChart = new Chart(document.getElementById("incomeChart").getContext("2d"), {
    type: "doughnut",
    data: { labels: [], datasets: [{ data: [], backgroundColor: [] }] },
    options: chartOptions
  });
}

function renderCharts(expenseData, incomeData) {
  const colors = ["#ef4444","#f97316","#f59e0b","#eab308","#10b981","#06b6d4","#3b82f6","#7c3aed","#ec4899","#64748b"];

  // Update expense chart
  if (expenseData && expenseData.length) {
    expenseChart.data.labels = expenseData.map(d => d.name);
    expenseChart.data.datasets[0].data = expenseData.map(d => d.total_amount);
    expenseChart.data.datasets[0].backgroundColor = expenseData.map((_, i) => colors[i % colors.length]);
  } else {
    expenseChart.data.labels = ['No data'];
    expenseChart.data.datasets[0].data = [1];
    expenseChart.data.datasets[0].backgroundColor = ['#4a4950'];
  }
  expenseChart.update();

  // Update income chart
  if (incomeData && incomeData.length) {
    incomeChart.data.labels = incomeData.map(d => d.name);
    incomeChart.data.datasets[0].data = incomeData.map(d => d.total_amount);
    incomeChart.data.datasets[0].backgroundColor = incomeData.map((_, i) => colors[i % colors.length]);
  } else {
    incomeChart.data.labels = ['No data'];
    incomeChart.data.datasets[0].data = [1];
    incomeChart.data.datasets[0].backgroundColor = ['#4a4950'];
  }
  incomeChart.update();
}

/* CRUD OPERATIONS */
async function addRecord(amount) {
  const { mode, fromAccount, toAccount, category } = state.ui;

  if (!fromAccount || !amount) {
    setError("Please select an account and enter an amount.");
    return;
  }

  if (mode === "transfer" && !toAccount) {
    setError("Please select a destination account for the transfer.");
    return;
  }

  // Check sufficient funds for expense
  if (mode === "expense" || mode === "transfer") {
    const acc = state.accounts.find(a => a.id === fromAccount);
    if (acc && acc.balance < amount) {
      setError("Insufficient funds in the selected account.");
      return;
    }
  }

  try {
    const data = {
      type: mode,
      amount: amount,
      from_account_id: fromAccount,
      category_id: category?.id || null,
      transfer_category_id: mode === 'transfer' ? (state.ui.transferCategory?.id || null) : null,
      description: dom.recordDescription.value.trim() || null,
      date: new Date().toISOString().split('T')[0]
    };

    if (mode === 'transfer') {
      data.to_account_id = toAccount;
      data.category_id = null;
    }

    const result = await apiCall('records.php', 'POST', data);
    
    if (result && result.success) {
      state.records.unshift(result.data.record);
      state.accounts = result.data.accounts;
      renderRecords();
      renderAccounts();
      await fetchSummary();
      toggleOverlay("addRecordOverlay", false);
      resetRecordForm();
    } else {
      setError(result?.message || "Failed to save record.");
    }
  } catch (error) {
    console.error('Add record error:', error);
    setError("Failed to save record. Please try again.");
  }
}

async function deleteRecord(recordId) {
  if (!confirm('Are you sure you want to delete this record?')) return;
  
  try {
    const result = await apiCall(`records.php?id=${recordId}`, 'DELETE');
    
    if (result && result.success) {
      state.accounts = result.data.accounts;
      await fetchRecords();
      await fetchSummary();
    } else {
      alert(result?.message || 'Failed to delete record.');
    }
  } catch (error) {
    console.error('Delete record error:', error);
    alert('Failed to delete record.');
  }
}

async function addAccount() {
  const name = $("accountNameInput").value.trim();
  const balance = parseFloat($("accountBalanceInput").value) || 0;
  const type = $("accountType").value;

  if (!name) {
    alert("Please enter an account name.");
    return;
  }

  try {
    const result = await apiCall('accounts.php', 'POST', {
      name,
      balance,
      account_type: type
    });

    if (result && result.success) {
      state.accounts.push(result.data.account);
      renderAccounts();
      toggleOverlay("addAccountOverlay", false);
      $("accountNameInput").value = "";
      $("accountBalanceInput").value = "";
    } else {
      alert(result?.message || 'Failed to add account.');
    }
  } catch (error) {
    console.error('Add account error:', error);
    alert('Failed to add account.');
  }
}

async function addCategory() {
  const name = $("categoryNameInput").value.trim();
  const type = state.ui.newCategoryType;

  if (!name) {
    alert("Please enter a category name.");
    return;
  }

  try {
    const result = await apiCall('categories.php', 'POST', { name, type });

    if (result && result.success) {
      state.categories.push(result.data.category);
      renderCategories();
      toggleOverlay("addCategoryOverlay", false);
      $("categoryNameInput").value = "";
    } else {
      alert(result?.message || 'Failed to add category.');
    }
  } catch (error) {
    console.error('Add category error:', error);
    alert('Failed to add category.');
  }
}

/* DEBT CRUD OPERATIONS */
async function addDebt() {
  const name = document.getElementById("debtNameInput").value.trim();
  const amount = parseFloat(document.getElementById("debtAmountInput").value) || 0;
  const accountId = document.getElementById("debtAccountSelect").value;
  const dueDate = document.getElementById("debtDueDateInput").value;
  const notes = document.getElementById("debtNotesInput").value.trim();

  if (!name) {
    alert("Please enter a debt name.");
    return;
  }
  if (amount <= 0) {
    alert("Please enter a valid amount.");
    return;
  }
  if (!accountId) {
    alert("Please select an account to credit the borrowed amount.");
    return;
  }

  try {
    const result = await apiCall('debts.php', 'POST', {
      name,
      amount,
      account_id: accountId,
      due_date: dueDate || null,
      notes: notes || null
    });

    if (result && result.success) {
      state.debts = [result.data.debt, ...state.debts.filter(d => d.id !== result.data.debt.id)];
      state.accounts = result.data.accounts;
      renderDebts();
      renderAccounts();
      toggleOverlay("addDebtOverlay", false);
      // Clear form
      document.getElementById("debtNameInput").value = "";
      document.getElementById("debtAmountInput").value = "";
      document.getElementById("debtDueDateInput").value = "";
      document.getElementById("debtNotesInput").value = "";
    } else {
      alert(result?.message || 'Failed to add debt.');
    }
  } catch (error) {
    console.error('Add debt error:', error);
    alert('Failed to add debt.');
  }
}

async function updateDebt() {
  const debtId = document.getElementById("editDebtId").value;
  const paid = parseFloat(document.getElementById("editDebtPaidInput").value) || 0;
  const name = document.getElementById("editDebtNameInput").value.trim();
  const dueDate = document.getElementById("editDebtDueDateInput").value;
  const notes = document.getElementById("editDebtNotesInput").value.trim();

  if (!debtId) return;

  try {
    const data = { id: debtId };
    if (name) data.name = name;
    if (paid >= 0) data.paid = paid;
    if (dueDate !== undefined) data.due_date = dueDate || null;
    if (notes !== undefined) data.notes = notes || null;

    const result = await apiCall('debts.php', 'PUT', data);

    if (result && result.success) {
      state.debts = state.debts.map(d => d.id === debtId ? result.data.debt : d);
      state.accounts = result.data.accounts;
      renderDebts();
      renderAccounts();
      toggleOverlay("editDebtOverlay", false);
    } else {
      alert(result?.message || 'Failed to update debt.');
    }
  } catch (error) {
    console.error('Update debt error:', error);
    alert('Failed to update debt.');
  }
}

async function deleteDebt(debtId) {
  if (!confirm('Are you sure you want to delete this debt? The remaining balance will be reversed from the linked account.')) return;

  try {
    const result = await apiCall('debts.php?id=' + debtId, 'DELETE');
    if (result && result.success) {
      state.accounts = result.data.accounts;
      await fetchDebts();
      renderAccounts();
    } else {
      alert(result?.message || 'Failed to delete debt.');
    }
  } catch (error) {
    console.error('Delete debt error:', error);
    alert('Failed to delete debt.');
  }
}

function openEditDebtModal(debtId) {
  const debt = state.debts.find(d => d.id === debtId);
  if (!debt) return;

  document.getElementById("editDebtId").value = debt.id;
  document.getElementById("editDebtNameInput").value = debt.name || '';
  document.getElementById("editDebtPaidInput").value = debt.paid || 0;
  document.getElementById("editDebtDueDateInput").value = debt.due_date || '';
  document.getElementById("editDebtNotesInput").value = debt.notes || '';

  toggleOverlay("editDebtOverlay", true);
}

function openPayDebtModal(debtId) {
  const debt = state.debts.find(d => d.id === debtId);
  if (!debt) return;

  // Quick pay: just increment the paid amount
  const remaining = debt.amount - debt.paid;
  const paymentStr = prompt('Enter payment amount (Remaining: ' + formatCurrency(remaining) + '):', '');
  if (paymentStr === null) return;
  const payment = parseFloat(paymentStr);
  if (isNaN(payment) || payment <= 0) {
    alert('Please enter a valid payment amount.');
    return;
  }

  const newPaid = Math.min(debt.paid + payment, debt.amount);

  apiCall('debts.php', 'PUT', { id: debtId, paid: newPaid })
    .then(result => {
      if (result && result.success) {
        state.debts = state.debts.map(d => d.id === debtId ? result.data.debt : d);
        state.accounts = result.data.accounts;
        renderDebts();
        renderAccounts();
      } else {
        alert(result?.message || 'Failed to record payment.');
      }
    })
    .catch(error => {
      console.error('Pay debt error:', error);
      alert('Failed to record payment.');
    });
}

/* EXPORT RECORDS */
async function exportRecords() {
  try {
    // Use fetch directly to get CSV as blob (not JSON)
    const response = await fetch('api/records.php?action=export', {
      credentials: 'include'
    });
    
    if (!response.ok) {
      throw new Error('Export failed');
    }
    
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'pennywise_records_' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
  } catch (error) {
    console.error('Export error:', error);
    alert('Failed to export records. Please try again.');
  }
}

/* EVENT HANDLERS */
document.addEventListener("click", async (e) => {

  const toggle = e.target.closest("[data-toggle]");
  if (toggle) {
    toggleOverlay(toggle.dataset.toggle, true);
    
    // Handle specific overlay needs
    if (toggle.dataset.toggle === 'accountsOverlay') {
      state.ui.selectMode = state.ui.mode === 'transfer' && state.ui.fromAccount ? 'to' : 'from';
    }
    if (toggle.dataset.toggle === 'addDebtOverlay') {
      populateDebtAccountSelect();
    }
    if (toggle.dataset.toggle === 'transferCategoryOverlay') {
      renderTransferCategories();
    }
    return;
  }

  if (e.target.matches("[data-close]")) {
    const overlay = e.target.closest(".overlay");
    if (overlay) toggleOverlay(overlay.id, false);
    return;
  }

  if (e.target.dataset.mode) {
    state.ui.mode = e.target.dataset.mode;
    state.ui.category = null;
    document.querySelectorAll("[data-mode]").forEach(btn =>
      btn.classList.toggle("active", btn === e.target)
    );
    updateModeUI();
    renderCategories();
    return;
  }

  if (e.target.dataset.accountId) {
    const accountId = e.target.dataset.accountId;
    
    if (state.ui.mode === 'transfer' && state.ui.fromAccount && state.ui.selectMode !== 'from') {
      // Selecting destination account for transfer
      state.ui.toAccount = accountId;
      const acc = state.accounts.find(a => a.id === accountId);
      $("toText").textContent = acc?.name || "—";
      toggleOverlay("accountsOverlay", false);
      toggleOverlay("transferToOverlay", false);
    } else if (state.ui.mode === 'transfer' && !state.ui.fromAccount) {
      // Selecting source account for transfer
      state.ui.fromAccount = accountId;
      const acc = state.accounts.find(a => a.id === accountId);
      dom.fromText.textContent = acc?.name || "—";
      toggleOverlay("accountsOverlay", false);
      // Now show destination selection
      renderTransferToOptions();
      toggleOverlay("transferToOverlay", true);
    } else {
      // Regular selection
      state.ui.fromAccount = accountId;
      const acc = state.accounts.find(a => a.id === accountId);
      dom.fromText.textContent = acc?.name || "—";
      toggleOverlay("accountsOverlay", false);
    }
    return;
  }

  // Handle transfer destination selection
  if (e.target.closest("#transferToOptions") && e.target.dataset.accountId) {
    state.ui.toAccount = e.target.dataset.accountId;
    const acc = state.accounts.find(a => a.id === state.ui.toAccount);
    $("toText").textContent = acc?.name || "—";
    toggleOverlay("transferToOverlay", false);
    return;
  }

  if (e.target.dataset.categoryId) {
    state.ui.category = state.categories.find(c => c.id === e.target.dataset.categoryId);
    $("toText").textContent = state.ui.category?.name || "—";
    toggleOverlay("categoriesOverlay", false);
    return;
  }

  if (e.target.dataset.transferCategoryId) {
    state.ui.transferCategory = state.transferCategories.find(tc => tc.id === e.target.dataset.transferCategoryId);
    if (dom.selectTransferCategoryBtn) {
      dom.selectTransferCategoryBtn.textContent = state.ui.transferCategory?.name || "Transfer Category";
    }
    toggleOverlay("transferCategoryOverlay", false);
    return;
  }

  if (e.target.dataset.categoryType) {
    state.ui.newCategoryType = e.target.dataset.categoryType;
    document.querySelectorAll("[data-category-type]").forEach(btn =>
      btn.classList.toggle("active", btn === e.target)
    );
    return;
  }

  if (e.target.dataset.action === "save-category") {
    await addCategory();
    return;
  }

  // Debt actions
  if (e.target.dataset.action === "save-debt") {
    await addDebt();
    return;
  }

  if (e.target.dataset.action === "update-debt") {
    await updateDebt();
    return;
  }

  if (e.target.dataset.action === "edit-debt") {
    openEditDebtModal(e.target.dataset.debtId);
    return;
  }

  if (e.target.dataset.action === "delete-debt") {
    await deleteDebt(e.target.dataset.debtId);
    return;
  }

  if (e.target.dataset.action === "pay-debt") {
    openPayDebtModal(e.target.dataset.debtId);
    return;
  }

  // Export action
  if (e.target.dataset.action === "export") {
    toggleOverlay('menuOverlay', false);
    await exportRecords();
    return;
  }

  if (e.target.dataset.key) {
    dom.display.value += e.target.dataset.key;
    return;
  }

  if (e.target.dataset.action === "clear") {
    dom.display.value = "";
    return;
  }

  if (e.target.dataset.action === "calculate") {
    try {
      dom.display.value = Function(`return ${dom.display.value}`)();
    } catch {
      setError("Invalid calculation");
    }
    return;
  }

  if (e.target.dataset.action === "save-record") {
    const amount = parseFloat(dom.display.value);
    if (isNaN(amount) || amount <= 0) {
      setError("Please enter a valid amount.");
      return;
    }
    await addRecord(amount);
    return;
  }

  if (e.target.dataset.action === "save-account") {
    await addAccount();
    return;
  }

  if (e.target.dataset.delete) {
    await deleteRecord(e.target.dataset.delete);
    return;
  }

  // Menu actions
  if (e.target.dataset.action === 'addCategory') {
    toggleOverlay('menuOverlay', false);
    toggleOverlay('addCategoryOverlay', true);
    return;
  }

  if (e.target.dataset.action === 'logout') {
    await logout();
    return;
  }
});

/* FILTER EVENT */
dom.recordFilter.addEventListener("change", async (e) => {
  state.ui.recordFilter = e.target.value;
  await fetchRecords();
});

/* INITIALIZATION */
async function init() {
  // Check authentication
  const isAuth = await checkAuth();
  if (!isAuth) {
    window.location.href = 'login.html';
    return;
  }

  // Initialize charts
  initCharts();

  // Load initial data
  showLoading(dom.recordList, 'Loading records...');
  showLoading(dom.accountsList, 'Loading accounts...');
  
  await Promise.all([
    fetchAccounts(),
    fetchCategories(),
    fetchTransferCategories(),
    fetchRecords(),
    fetchDebts()
  ]);
  
  await fetchSummary();
}

// Start the app
init();