"use strict";

/* STATE */
const state = {
  records: [],
  accounts: [
    { id: crypto.randomUUID(), name: "Cash", balance: 0 }
  ],
  categories: [
    { id: crypto.randomUUID(), name: "Salary", type: "income" },
    { id: crypto.randomUUID(), name: "Food", type: "expense" }
  ],
  ui: {
    mode: null,
    fromAccount: null,
    toAccount: null,
    category: null,
    newCategoryType: null,
    recordFilter: "all"
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
  display: document.querySelector(".display-num"),
  fromText: $("fromText"),
  toText: $("toText"),
  error: $("formError"),
  recordFilter: $("recordTypeFilter")
};

/* UTILITIES */
function toggleOverlay(id, show) {
  const el = $(id);
  if (el) el.hidden = !show;
}

function setError(message = "") {
  dom.error.textContent = message;
  dom.error.classList.toggle("hidden", !message);
}

function resetRecordForm() {
  state.ui.mode = null;
  state.ui.fromAccount = null;
  state.ui.toAccount = null;
  state.ui.category = null;
  dom.display.value = "";
  dom.fromText.textContent = "—";
  dom.toText.textContent = "—";
  setError();

  document.querySelectorAll("[data-mode]").forEach(btn =>
    btn.classList.remove("active")
  );
}

/* RENDERING */
function renderRecords() {
  dom.recordList.innerHTML = "";

  const filtered =
    state.ui.recordFilter === "all"
      ? state.records
      : state.records.filter(r => r.type === state.ui.recordFilter);

  if (!filtered.length) {
    dom.recordList.classList.add("empty");
    dom.recordList.innerHTML = "<li>No records yet</li>";
    return;
  }

  dom.recordList.classList.remove("empty");

  filtered.forEach(r => {
    const li = document.createElement("li");
    li.className = `record ${r.type}`;
    li.innerHTML = `
      <span>${r.label}</span>
      <strong>${r.type === "expense" ? "-" : "+"}$${r.amount}</strong>
    `;
    dom.recordList.appendChild(li);
  });
}

function renderAccounts() {
  dom.accountsList.innerHTML = "";
  dom.accountOptions.innerHTML = "";

  state.accounts.forEach(acc => {
    const li = document.createElement("li");
    li.className = "account";
    li.innerHTML = `<span>${acc.name}</span><strong>$${acc.balance}</strong>`;
    dom.accountsList.appendChild(li);

    const btn = document.createElement("button");
    btn.textContent = acc.name;
    btn.dataset.accountId = acc.id;
    dom.accountOptions.appendChild(btn);
  });
}

function renderCategories() {
  dom.categoryOptions.innerHTML = "";

  state.categories
    .filter(c => c.type === state.ui.mode)
    .forEach(c => {
      const btn = document.createElement("button");
      btn.textContent = c.name;
      btn.dataset.categoryId = c.id;
      dom.categoryOptions.appendChild(btn);
    });
}

function updateSummary() {
  let income = 0;
  let expense = 0;

  state.records.forEach(r => {
    if (r.type === "income") income += r.amount;
    if (r.type === "expense") expense += r.amount;
  });

  dom.incomeAmount.textContent = `$${income}`;
  dom.expenseAmount.textContent = `$${expense}`;
  dom.totalAmount.textContent = `$${income - expense}`;
}

/* BUSINESS LOGIC */
function addRecord(amount) {
  const { mode, fromAccount, toAccount, category } = state.ui;

  if (!mode || !fromAccount || !amount) {
    setError("Missing required fields");
    return;
  }

  const fromm = state.accounts.find(a => a.id === fromAccount);
  if (!fromm) {
    setError("Source account not found");
    return;
  }

  if (mode === "transfer" && !toAccount) {
    setError("Select destination account");
    return;
  }

  // Validate sufficient funds before creating the record
  if (mode === "expense") {
    if (fromm.balance < amount) {
      alert("You lack the funds!");
      setError("Insufficient funds for expense");
      return;
    }
  }

  const record = {
    id: crypto.randomUUID(),
    type: mode,
    amount,
    label: mode === "transfer"
      ? "Transfer"
      : category?.name || "Uncategorized"
  };

  state.records.push(record);

  const from = state.accounts.find(a => a.id === fromAccount);
  if (mode === "income") from.balance += amount;
  if (mode === "expense") from.balance -= amount;

  if (mode === "transfer") {
    const to = state.accounts.find(a => a.id === toAccount);
    from.balance -= amount;
    to.balance += amount;
  }

  renderRecords();
  renderAccounts();
  updateSummary();
  updateCharts();

  toggleOverlay("addRecordOverlay", false);
  resetRecordForm();
  renderCategories();
}

/* EVENTS */
document.addEventListener("click", (e) => {

  const toggle = e.target.closest("[data-toggle]");
  if (toggle) {
    toggleOverlay(toggle.dataset.toggle, true);
    return;
  }

  if (e.target.matches("[data-close]")) {
    toggleOverlay(e.target.closest(".overlay").id, false);
    return;
  }

  if (e.target.dataset.mode) {
    state.ui.mode = e.target.dataset.mode;
    document.querySelectorAll("[data-mode]").forEach(btn =>
      btn.classList.toggle("active", btn === e.target)
    );
    renderCategories();
    return;
  }

  if (e.target.dataset.accountId) {
    if (!state.ui.fromAccount) {
      state.ui.fromAccount = e.target.dataset.accountId;
      dom.fromText.textContent = e.target.textContent;
    } else {
      state.ui.toAccount = e.target.dataset.accountId;
      dom.toText.textContent = e.target.textContent;
    }
    toggleOverlay("accountsOverlay", false);
    return;
  }

  if (e.target.dataset.categoryId) {
    state.ui.category = state.categories.find(
      c => c.id === e.target.dataset.categoryId
    );
    dom.toText.textContent = state.ui.category.name;
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
    const name = $("categoryNameInput").value.trim();
    const type = state.ui.newCategoryType;
    if (!name || !type) return;

    state.categories.push({ id: crypto.randomUUID(), name, type });
    $("categoryNameInput").value = "";
    state.ui.newCategoryType = null;

    renderCategories();
    toggleOverlay("addCategoryOverlay", false);
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
    addRecord(Number(dom.display.value));
    return;
  }

  if (e.target.dataset.action === "save-account") {
    const name = $("accountNameInput").value.trim();
    const balance = Number($("accountBalanceInput").value || 0);
    if (!name) return;

    state.accounts.push({ id: crypto.randomUUID(), name, balance });
    renderAccounts();
    toggleOverlay("addAccountOverlay", false);
  }
});

/* FILTER EVENT */
dom.recordFilter.addEventListener("change", (e) => {
  state.ui.recordFilter = e.target.value;
  renderRecords();
});

/* INIT */
renderAccounts();
renderRecords();
updateSummary();

/* CHART.JS SETUP */
const expenseDonutChart = new Chart(document.getElementById("expenseChart").getContext("2d"), {
  type: "doughnut",
  data: { labels: [], datasets: [{ data: [], backgroundColor: [] }] },
  options: { responsive: true, plugins: { legend: { position: "bottom" } } }
});

const incomeDonutChart = new Chart(document.getElementById("incomeChart").getContext("2d"), {
  type: "doughnut",
  data: { labels: [], datasets: [{ data: [], backgroundColor: [] }] },
  options: { responsive: true, plugins: { legend: { position: "bottom" } } }
});


function updateCharts() {
  // Aggregate by category
  const incomeData = {};
  const expenseData = {};

  state.records.forEach(r => {
    const key = r.label || "Uncategorized";
    if (r.type === "income") incomeData[key] = (incomeData[key] || 0) + r.amount;
    if (r.type === "expense") expenseData[key] = (expenseData[key] || 0) + r.amount;
  });

  const colors = ["#ef4444","#f97316","#f59e0b","#eab308","#10b981","#06b6d4","#3b82f6","#7c3aed","#ec4899","#64748b"];

  // Update expense chart
  expenseDonutChart.data.labels = Object.keys(expenseData);
  expenseDonutChart.data.datasets[0].data = Object.values(expenseData);
  expenseDonutChart.data.datasets[0].backgroundColor = Object.keys(expenseData).map((_,i) => colors[i % colors.length]);
  expenseDonutChart.update();

  // Update income chart
  incomeDonutChart.data.labels = Object.keys(incomeData);
  incomeDonutChart.data.datasets[0].data = Object.values(incomeData);
  incomeDonutChart.data.datasets[0].backgroundColor = Object.keys(incomeData).map((_,i) => colors[i % colors.length]);
  incomeDonutChart.update();


}
