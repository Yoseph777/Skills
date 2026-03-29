"use strict";

/* API CONFIGURATION - Single unified API file */
const API_FILE = 'api.php';

/* STATE */
const state = {
  user: null,
  records: [],
  accounts: [],
  categories: [],
  ui: {
    mode: 'expense',
    fromAccount: null,
    toAccount: null,
    category: null,
    newCategoryType: 'expense',
    recordFilter: 'all',
    selectMode: 'from'
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
  recordDescription: $("recordDescription")
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
  dom.display.value = "";
  dom.fromText.textContent = "—";
  dom.toText.textContent = "—";
  dom.toLabel.innerHTML = 'Category: <span id="toText">—</span>';
  dom.recordDescription.value = "";
  setError();

  document.querySelectorAll("[data-mode]").forEach(btn => {
    btn.classList.toggle("active", btn.dataset.mode === 'expense');
  });
  
  dom.selectCategoryBtn.style.display = 'block';
  updateModeUI();
}

function updateModeUI() {
  const mode = state.ui.mode;
  if (mode === 'transfer') {
    dom.selectCategoryBtn.style.display = 'none';
    dom.toLabel.innerHTML = 'To: <span id="toText">—</span>';
  } else {
    dom.selectCategoryBtn.style.display = 'block';
    dom.toLabel.innerHTML = 'Category: <span id="toText">—</span>';
  }
}

function showLoading(element, message = 'Loading...') {
  element.innerHTML = `<li class="loading">${message}</li>`;
}

function showMessage(element, message) {
  element.innerHTML = `<li class="empty-message">${message}</li>`;
}

/* API FUNCTIONS - Using unified api.php */
async function apiCall(action, method = 'GET', data = null) {
  let url = `${API_FILE}?action=${action}`;
  
  const options = {
    method,
    headers: {
      'Content-Type': 'application/json'
    },
    credentials: 'include'
  };

  // For GET and DELETE, add data as query params
  if ((method === 'GET' || method === 'DELETE') && data) {
    Object.keys(data).forEach(key => {
      url += `&${key}=${encodeURIComponent(data[key])}`;
    });
  }
  
  // For POST, add body
  if (method === 'POST' && data) {
    options.body = JSON.stringify(data);
  }

  try {
    const response = await fetch(url, options);
    const result = await response.json();
    
    if (!response.ok && response.status === 401) {
      window.location.href = 'login.html';
      return null;
    }
    
    return result;
  } catch (error) {
    console.error('API Error:', error);
    return { success: false, message: 'Connection error. Is the server running?' };
  }
}

/* AUTH FUNCTIONS */
async function checkAuth() {
  try {
    const result = await apiCall('check');
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
    await apiCall('logout');
    window.location.href = 'login.html';
  } catch (error) {
    console.error('Logout failed:', error);
  }
}

/* DATA FETCHING */
async function fetchAccounts() {
  try {
    const result = await apiCall('getAccounts');
    if (result && result.success) {
      state.accounts = result.data.accounts;
      renderAccounts();
    } else {
      showMessage(dom.accountsList, 'Failed to load accounts');
    }
  } catch (error) {
    console.error('Failed to fetch accounts:', error);
    showMessage(dom.accountsList, 'Failed to load accounts');
  }
}

async function fetchCategories() {
  try {
    const result = await apiCall('getCategories');
    if (result && result.success) {
      state.categories = result.data.categories;
    }
  } catch (error) {
    console.error('Failed to fetch categories:', error);
  }
}

async function fetchRecords() {
  try {
    showLoading(dom.recordList, 'Loading records...');
    
    let action = 'getRecords';
    if (state.ui.recordFilter !== 'all') {
      action += `&type=${state.ui.recordFilter}`;
    }
    
    const result = await apiCall(action);
    
    if (result && result.success) {
      state.records = result.data.records;
      renderRecords();
      updateSummary(result.data.summary);
    } else {
      showMessage(dom.recordList, 'Failed to load records');
    }
  } catch (error) {
    console.error('Failed to fetch records:', error);
    showMessage(dom.recordList, 'Failed to load records');
  }
}

async function fetchSummary() {
  try {
    const result = await apiCall('getSummary');
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
    
    li.innerHTML = `
      <div class="record-info">
        <span class="record-category">${r.category_name || r.description || r.type}</span>
        <span class="record-date">${date}</span>
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
        ${acc.account_type === 'cash' ? '💰' : '🏦'} ${acc.name}
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
      description: dom.recordDescription.value.trim() || null,
      date: new Date().toISOString().split('T')[0]
    };

    if (mode === 'transfer') {
      data.to_account_id = toAccount;
      data.category_id = null;
    }

    const result = await apiCall('addRecord', 'POST', data);
    
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
    const result = await apiCall('deleteRecord', 'DELETE', { id: recordId });
    
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
    const result = await apiCall('addAccount', 'POST', {
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
    const result = await apiCall('addCategory', 'POST', { name, type });

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

/* EVENT HANDLERS */
document.addEventListener("click", async (e) => {

  const toggle = e.target.closest("[data-toggle]");
  if (toggle) {
    toggleOverlay(toggle.dataset.toggle, true);
    
    if (toggle.dataset.toggle === 'accountsOverlay') {
      state.ui.selectMode = state.ui.mode === 'transfer' && state.ui.fromAccount ? 'to' : 'from';
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
      state.ui.toAccount = accountId;
      const acc = state.accounts.find(a => a.id === accountId);
      $("toText").textContent = acc?.name || "—";
      toggleOverlay("accountsOverlay", false);
      toggleOverlay("transferToOverlay", false);
    } else if (state.ui.mode === 'transfer' && !state.ui.fromAccount) {
      state.ui.fromAccount = accountId;
      const acc = state.accounts.find(a => a.id === accountId);
      dom.fromText.textContent = acc?.name || "—";
      toggleOverlay("accountsOverlay", false);
      renderTransferToOptions();
      toggleOverlay("transferToOverlay", true);
    } else {
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
    fetchRecords()
  ]);
  
  await fetchSummary();
}

// Start the app
init();