const state = {
  period: 'monthly',
  page: 'dashboard',
  trendChart: null,
  expenseChart: null,
  reportChart: null,
  txDir: 'all',
  limits: null,
  products: [],
};

const SOURCE_FA = {
  manual: 'ثبت دستی',
  sales: 'فروش',
  cogs: 'بهای تمام‌شده کالا',
  ops: 'هزینه‌های عملیاتی',
  payroll: 'حقوق و دستمزد',
  excel: 'ورود از اکسل',
};

function token() {
  return localStorage.getItem('finlytics_token');
}

function ensureAuth() {
  if (!token()) {
    location.href = '/login';
    return false;
  }
  return true;
}

function toFaDigits(value) {
  return String(value ?? '').replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
}

function toEnDigits(value) {
  return String(value ?? '')
    .replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
    .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
}

/** Convert Persian/Arabic digits to English and strip thousand separators for storage. */
function normalizeNumberInput(raw) {
  let text = toEnDigits(raw).trim();
  text = text.replace(/٫/g, '.');
  text = text.replace(/[\s,٬'’]/g, '');
  return text;
}

function formatFaNumber(v, { decimals = 0 } = {}) {
  const n = Number(v || 0);
  const fixed = decimals > 0 ? n.toFixed(decimals) : String(Math.round(n));
  const [intPart, decPart] = fixed.split('.');
  const withSep = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return toFaDigits(decPart !== undefined && decimals > 0 ? `${withSep}.${decPart}` : withSep);
}

function money(v) {
  return `${formatFaNumber(v)} ریال`;
}

function formatCardValue(label, v) {
  if (String(label).includes('%') || String(label).includes('٪')) {
    return `${toFaDigits(String(Number(v || 0)))}٪`;
  }
  return money(v);
}

function formatCompactFa(v) {
  const n = Number(v || 0);
  const abs = Math.abs(n);
  if (abs >= 1e9) return toFaDigits((n / 1e9).toFixed(1)) + ' میلیارد';
  if (abs >= 1e6) return toFaDigits((n / 1e6).toFixed(1)) + ' میلیون';
  if (abs >= 1e3) return toFaDigits((n / 1e3).toFixed(1)) + ' هزار';
  return formatFaNumber(n);
}


/** Gregorian YYYY-MM-DD or Date → Jalali display */
function toJalali(input) {
  let gy;
  let gm;
  let gd;
  if (input instanceof Date) {
    gy = input.getFullYear();
    gm = input.getMonth() + 1;
    gd = input.getDate();
  } else {
    const parts = String(input || '').slice(0, 10).split('-').map(Number);
    if (parts.length < 3 || parts.some((n) => !n && n !== 0)) return String(input || '');
    [gy, gm, gd] = parts;
  }
  const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
  let jy;
  if (gy > 1600) {
    jy = 979;
    gy -= 1600;
  } else {
    jy = 0;
    gy -= 621;
  }
  const gy2 = gm > 2 ? gy + 1 : gy;
  let days =
    365 * gy +
    Math.floor((gy2 + 3) / 4) -
    Math.floor((gy2 + 99) / 100) +
    Math.floor((gy2 + 399) / 400) -
    80 +
    gd +
    g_d_m[gm - 1];
  jy += 33 * Math.floor(days / 12053);
  days %= 12053;
  jy += 4 * Math.floor(days / 1461);
  days %= 1461;
  if (days > 365) {
    jy += Math.floor((days - 1) / 365);
    days = (days - 1) % 365;
  }
  let jm;
  let jd;
  if (days < 186) {
    jm = 1 + Math.floor(days / 31);
    jd = 1 + (days % 31);
  } else {
    jm = 7 + Math.floor((days - 186) / 30);
    jd = 1 + ((days - 186) % 30);
  }
  return toFaDigits(`${jy}/${String(jm).padStart(2, '0')}/${String(jd).padStart(2, '0')}`);
}

function sourceLabel(src) {
  return SOURCE_FA[src] || src || 'ثبت دستی';
}

function todayISO() {
  return new Date().toISOString().slice(0, 10);
}

async function api(path, options = {}) {
  const headers = Object.assign({}, options.headers || {});
  const isForm = options.body instanceof FormData;
  if (!isForm) {
    headers['Content-Type'] = headers['Content-Type'] || 'application/json';
  } else {
    delete headers['Content-Type'];
  }
  headers['Authorization'] = `Bearer ${token()}`;
  const res = await fetch(path, { ...options, headers });
  if (res.status === 401) {
    localStorage.clear();
    location.href = '/login';
    throw new Error('نشست منقضی شده');
  }
  const ct = res.headers.get('content-type') || '';
  const data = ct.includes('application/json') ? await res.json() : await res.blob();
  if (!res.ok) {
    const detail = data && data.detail ? data.detail : 'خطای سرور';
    throw new Error(typeof detail === 'string' ? detail : JSON.stringify(detail));
  }
  return data;
}

async function setAuthenticatedAudio(url) {
  const res = await fetch(url, { headers: { Authorization: `Bearer ${token()}` } });
  if (!res.ok) throw new Error('دریافت فایل صوتی ناموفق بود');
  const blob = await res.blob();
  const audio = document.getElementById('voiceAudio');
  audio.src = URL.createObjectURL(blob);
}

function closeMenu() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('overlay').classList.remove('show');
}

function closeModal() {
  const root = document.getElementById('modalRoot');
  root.className = 'hidden';
  root.innerHTML = '';
}

function openModal(title, bodyHtml, onSubmit) {
  const root = document.getElementById('modalRoot');
  root.className = 'modal-backdrop';
  root.innerHTML = `
    <div class="modal" role="dialog" aria-modal="true">
      <h3>${title}</h3>
      <div id="modalError" class="error-box hidden"></div>
      <form id="modalForm">${bodyHtml}
        <div class="modal-actions">
          <button type="submit" class="btn">ذخیره</button>
          <button type="button" class="btn btn-ghost" id="modalCancel">انصراف</button>
        </div>
      </form>
    </div>`;
  root.querySelector('#modalCancel').onclick = closeModal;
  root.onclick = (e) => {
    if (e.target === root) closeModal();
  };
  bindPersianNumberInputs(root);
  root.querySelector('#modalForm').onsubmit = async (e) => {
    e.preventDefault();
    const err = root.querySelector('#modalError');
    err.classList.add('hidden');
    try {
      await onSubmit(new FormData(e.target));
      closeModal();
    } catch (ex) {
      err.textContent = ex.message || 'خطا در ذخیره';
      err.classList.remove('hidden');
    }
  };
}

function parseAmount(raw) {
  const text = normalizeNumberInput(raw);
  const n = Number(text);
  if (text === '' || Number.isNaN(n)) throw new Error('مبلغ باید عدد معتبر باشد');
  if (n < 0) throw new Error('مبلغ نمی‌تواند منفی باشد');
  if (state.limits && n > state.limits.max_transaction_amount) {
    throw new Error(
      `مبلغ از سقف مجاز (${money(state.limits.max_transaction_amount)}) بیشتر است`
    );
  }
  return n;
}

function bindPersianNumberInputs(root) {
  root.querySelectorAll('input.num-fa').forEach((input) => {
    input.addEventListener('blur', () => {
      const n = Number(normalizeNumberInput(input.value));
      if (!Number.isNaN(n) && input.value.trim() !== '') {
        input.value = formatFaNumber(n);
      }
    });
    input.addEventListener('focus', () => {
      input.value = normalizeNumberInput(input.value);
    });
  });
}

function validateSalaryClient(raw) {
  const n = parseAmount(raw);
  if (!state.limits) return n;
  if (n > state.limits.max_monthly_salary) {
    throw new Error(
      `حقوق ماهانه از سقف حقوق (${money(state.limits.max_monthly_salary)}) بیشتر است`
    );
  }
  if (n < state.limits.min_monthly_salary) {
    throw new Error(
      `حقوق ماهانه کمتر از حداقل مجاز (${money(state.limits.min_monthly_salary)}) است`
    );
  }
  return n;
}

function setPage(page) {
  state.page = page;
  document.querySelectorAll('.nav-item').forEach((el) => {
    el.classList.toggle('active', el.dataset.page === page);
  });
  document.querySelectorAll('main > section').forEach((sec) => sec.classList.add('hidden'));
  const view = document.getElementById(`view-${page}`);
  if (view) view.classList.remove('hidden');

  const titles = {
    dashboard: ['داشبورد مالی', 'وضعیت عملکردی سازمان'],
    reports: ['گزارش‌های دوره‌ای', 'تحلیل روزانه، ماهانه و سال مالی شمسی'],
    transactions: ['ورودی و خروجی', 'مدیریت تراکنش‌های مالی'],
    catalog: ['محصولات و فروش', 'ویرایش کاتالوگ و ثبت فروش'],
    personnel: ['پرسنل و حقوق', 'ویرایش حقوق با رعایت سقف'],
    excel: ['ورود اطلاعات از اکسل', 'قالب پیشنهادی Finlytics'],
    voice: ['ویس گزارش مدیرعامل', 'گزارش عملکردی روزانه فارسی + پیش‌بینی فردا'],
    ai: ['پرسش‌وپاسخ هوش مصنوعی', 'سقف محدود سؤال روزانه درباره وضعیت مالی'],
  };
  const [t, m] = titles[page] || ['پنل', ''];
  document.getElementById('pageTitle').textContent = t;
  document.getElementById('pageMeta').textContent = m;
  document.getElementById('periodTabs').style.display =
    page === 'dashboard' || page === 'reports' ? 'flex' : 'none';

  if (page === 'dashboard') loadDashboard();
  if (page === 'reports') loadReports();
  if (page === 'transactions') loadTransactions();
  if (page === 'catalog') loadCatalog();
  if (page === 'personnel') loadEmployees();
  if (page === 'ai') loadAiChat();
}

function renderCards(targetId, cards) {
  const el = document.getElementById(targetId);
  el.innerHTML = cards
    .map((c) => {
      const delta =
        c.change_pct === null || c.change_pct === undefined
          ? ''
          : `<div class="delta ${c.change_pct < 0 ? 'down' : ''}">${c.change_pct > 0 ? '+' : ''}${toFaDigits(c.change_pct)}٪ نسبت به دوره قبل</div>`;
      return `<article class="card"><div class="label">${c.label}</div><div class="value">${formatCardValue(c.label, c.value)}</div>${delta}</article>`;
    })
    .join('');
}

function renderForecast(elId, forecast) {
  const el = document.getElementById(elId);
  if (!el) return;
  const f = forecast;
  if (!f) {
    el.textContent = '';
    return;
  }
  const title = f.target_label || 'دوره بعد';
  const range =
    f.forecast_start && f.forecast_end && f.forecast_start !== f.forecast_end
      ? `${toJalali(f.forecast_start)} تا ${toJalali(f.forecast_end)}`
      : toJalali(f.forecast_date);
  el.innerHTML = `
    <strong>پیش‌بینی ${title} (${range})</strong>
    <div class="forecast-metrics">
      <span>درآمد: <b>${money(f.predicted_income)}</b></span>
      <span>هزینه: <b>${money(f.predicted_expense)}</b></span>
      <span>سود: <b>${money(f.predicted_profit)}</b></span>
    </div>
    <div>${toFaDigits(f.narrative || '')}</div>
    <span style="color:var(--muted);font-size:.85rem">${toFaDigits(f.confidence_note || '')}</span>
  `;
}

function upsertChart(ref, canvasId, config) {
  if (state[ref]) state[ref].destroy();
  const ctx = document.getElementById(canvasId);
  if (!ctx) return;
  state[ref] = new Chart(ctx, config);
}

function isMobile() {
  return window.matchMedia('(max-width: 860px)').matches;
}

function chartOptions() {
  return {
    responsive: true,
    maintainAspectRatio: false,
    resizeDelay: 50,
    layout: { padding: 4 },
    plugins: {
      legend: {
        labels: {
          color: '#9db6c4',
          boxWidth: 10,
          font: { family: 'Vazirmatn', size: isMobile() ? 10 : 12 },
        },
      },
    },
    scales: {
      x: {
        ticks: {
          color: '#9db6c4',
          maxRotation: 0,
          autoSkip: true,
          maxTicksLimit: isMobile() ? 6 : 12,
          font: { size: isMobile() ? 9 : 11 },
        },
        grid: { color: 'rgba(168,214,214,.08)' },
      },
      y: {
        ticks: {
          color: '#9db6c4',
          callback: (v) => formatCompactFa(v),
          font: { size: isMobile() ? 9 : 11 },
        },
        grid: { color: 'rgba(168,214,214,.08)' },
      },
    },
  };
}

function doughnutOptions() {
  return {
    responsive: true,
    maintainAspectRatio: false,
    layout: { padding: isMobile() ? 8 : 12 },
    plugins: {
      legend: {
        position: 'bottom',
        align: 'center',
        labels: {
          color: '#9db6c4',
          boxWidth: 10,
          padding: isMobile() ? 8 : 12,
          font: { family: 'Vazirmatn', size: isMobile() ? 10 : 12 },
        },
      },
    },
  };
}

async function loadDashboard() {
  const data = await api(`/api/dashboard?period=${state.period}`);
  document.getElementById('pageMeta').textContent = data.period;
  renderForecast('dashForecast', data.forecast || data.tomorrow);
  renderCards('summaryCards', data.cards);

  upsertChart('trendChart', 'trendChart', {
    type: 'line',
    data: {
      labels: data.trend.map((p) => toFaDigits(p.label)),
      datasets: [
        {
          label: 'درآمد',
          data: data.trend.map((p) => p.income),
          borderColor: '#1fb8a6',
          backgroundColor: 'rgba(31,184,166,.15)',
          tension: 0.35,
          fill: true,
        },
        {
          label: 'هزینه',
          data: data.trend.map((p) => p.expense),
          borderColor: '#ff6b6b',
          backgroundColor: 'rgba(255,107,107,.08)',
          tension: 0.35,
          fill: true,
        },
        {
          label: 'سود',
          data: data.trend.map((p) => p.profit),
          borderColor: '#f0b429',
          tension: 0.35,
        },
      ],
    },
    options: chartOptions(),
  });

  upsertChart('expenseChart', 'expenseChart', {
    type: 'doughnut',
    data: {
      labels: data.expense_breakdown.map((x) => x.category),
      datasets: [
        {
          data: data.expense_breakdown.map((x) => x.amount),
          backgroundColor: ['#1fb8a6', '#f0b429', '#5b8def', '#ff6b6b', '#3ddc97', '#94a3b8', '#fb923c', '#67e8f9'],
          borderWidth: 0,
        },
      ],
    },
    options: doughnutOptions(),
  });

  document.getElementById('topProductsBody').innerHTML = data.top_products
    .map(
      (p) =>
        `<tr><td>${p.name}</td><td>${toFaDigits(p.quantity)}</td><td>${money(p.revenue)}</td><td>${money(p.profit)}</td></tr>`
    )
    .join('');
  document.getElementById('topProductsCards').innerHTML = data.top_products
    .map(
      (p) =>
        `<div class="mobile-card-item"><div class="title">${p.name}</div><div class="meta"><div>مقدار: <b>${toFaDigits(p.quantity)}</b></div><div>درآمد: <b>${money(p.revenue)}</b></div><div>سود: <b>${money(p.profit)}</b></div></div></div>`
    )
    .join('') || '<div class="hint">موردی نیست</div>';

  document.getElementById('recentBody').innerHTML = data.recent_transactions
    .map(
      (t) =>
        `<tr><td>${toJalali(t.txn_date)}</td><td><span class="badge ${t.direction}">${t.direction === 'income' ? 'ورودی' : 'خروجی'}</span></td><td>${t.title}</td><td>${money(t.amount)}</td></tr>`
    )
    .join('');
  document.getElementById('recentCards').innerHTML = data.recent_transactions
    .map(
      (t) =>
        `<div class="mobile-card-item"><div class="title">${t.title}</div><div class="meta"><div>${toJalali(t.txn_date)} · ${t.direction === 'income' ? 'ورودی' : 'خروجی'}</div><div><b>${money(t.amount)}</b></div></div></div>`
    )
    .join('');
}

async function loadReports() {
  const data = await api(`/api/reports/${state.period}`);
  document.getElementById('reportLabel').textContent = data.label;
  document.getElementById('reportNarrative').textContent = toFaDigits(data.narrative);
  const f = data.forecast || data.tomorrow;
  renderForecast('reportForecast', f);
  const cards = [
    { label: 'درآمد', value: data.total_income },
    { label: 'هزینه', value: data.total_expense },
    { label: 'سود خالص', value: data.net_profit },
    { label: 'حاشیه سود ٪', value: data.margin_pct },
    { label: 'بهای تمام‌شده کالا', value: data.cogs_cost },
    { label: 'هزینه‌های عملیاتی', value: data.opex_cost },
    { label: 'میانگین روزانه درآمد', value: data.daily_avg_income },
    { label: 'هزینه حقوق', value: data.payroll_cost },
  ];
  if (f) {
    const t = f.target_label || 'دوره بعد';
    cards.push(
      { label: `پیش‌بینی درآمد ${t}`, value: f.predicted_income },
      { label: `پیش‌بینی هزینه ${t}`, value: f.predicted_expense },
      { label: `پیش‌بینی سود ${t}`, value: f.predicted_profit }
    );
  }
  renderCards('reportCards', cards);
  upsertChart('reportChart', 'reportChart', {
    type: 'bar',
    data: {
      labels: data.trend.map((p) => toFaDigits(p.label)),
      datasets: [
        { label: 'درآمد', data: data.trend.map((p) => p.income), backgroundColor: 'rgba(31,184,166,.75)' },
        { label: 'هزینه', data: data.trend.map((p) => p.expense), backgroundColor: 'rgba(255,107,107,.65)' },
      ],
    },
    options: chartOptions(),
  });
}

function txnFormFields(t = {}) {
  return `
    <div class="form-grid">
      <div class="field"><label>تاریخ</label><input name="txn_date" type="date" required value="${t.txn_date || todayISO()}" /></div>
      <div class="field"><label>نوع</label>
        <select name="direction" required>
          <option value="income" ${t.direction === 'income' ? 'selected' : ''}>ورودی (درآمد)</option>
          <option value="expense" ${t.direction === 'expense' ? 'selected' : ''}>خروجی (هزینه)</option>
        </select>
      </div>
      <div class="field span-2"><label>دسته</label><input name="category" required maxlength="80" value="${t.category || ''}" placeholder="مثال: هزینه‌های عملیاتی" /></div>
      <div class="field span-2"><label>عنوان</label><input name="title" required maxlength="200" value="${t.title || ''}" /></div>
      <div class="field span-2"><label>مبلغ (ریال)</label><input class="num-fa" name="amount" inputmode="decimal" required value="${t.amount != null ? formatFaNumber(t.amount) : ''}" /></div>
      <div class="field span-2"><label>توضیح</label><textarea name="note">${t.note || ''}</textarea></div>
    </div>`;
}

async function loadTransactions() {
  const q = state.txDir === 'all' ? '' : `?direction=${state.txDir}`;
  const rows = await api(`/api/transactions${q}`);
  document.getElementById('txBody').innerHTML = rows
    .map(
      (t) =>
        `<tr>
          <td>${toJalali(t.txn_date)}</td>
          <td><span class="badge ${t.direction}">${t.direction === 'income' ? 'ورودی' : 'خروجی'}</span></td>
          <td>${t.category}</td>
          <td>${t.title}</td>
          <td>${t.source_label || sourceLabel(t.source)}</td>
          <td>${money(t.amount)}</td>
          <td class="row-actions">
            <button class="btn btn-ghost btn-sm" data-edit-txn="${t.id}">ویرایش</button>
            <button class="btn btn-danger btn-sm" data-del-txn="${t.id}">حذف</button>
          </td>
        </tr>`
    )
    .join('');
  document.getElementById('txCards').innerHTML = rows
    .map(
      (t) =>
        `<div class="mobile-card-item">
          <div class="title">${t.title}</div>
          <div class="meta">
            <div>${toJalali(t.txn_date)} · ${t.direction === 'income' ? 'ورودی' : 'خروجی'} · ${t.category}</div>
            <div>${t.source_label || sourceLabel(t.source)}</div>
            <div><b>${money(t.amount)}</b></div>
          </div>
          <div class="row-actions" style="margin-top:10px">
            <button class="btn btn-ghost btn-sm" data-edit-txn="${t.id}">ویرایش</button>
            <button class="btn btn-danger btn-sm" data-del-txn="${t.id}">حذف</button>
          </div>
        </div>`
    )
    .join('');

  document.querySelectorAll('[data-edit-txn]').forEach((btn) => {
    btn.onclick = () => {
      const row = rows.find((r) => String(r.id) === btn.dataset.editTxn);
      if (!row) return;
      openModal('ویرایش تراکنش', txnFormFields(row), async (fd) => {
        const amount = parseAmount(fd.get('amount'));
        await api(`/api/transactions/${row.id}`, {
          method: 'PUT',
          body: JSON.stringify({
            txn_date: fd.get('txn_date'),
            direction: fd.get('direction'),
            category: fd.get('category'),
            title: fd.get('title'),
            amount,
            note: fd.get('note') || null,
          }),
        });
        await loadTransactions();
      });
    };
  });
  document.querySelectorAll('[data-del-txn]').forEach((btn) => {
    btn.onclick = async () => {
      if (!confirm('این تراکنش حذف شود؟')) return;
      await api(`/api/transactions/${btn.dataset.delTxn}`, { method: 'DELETE' });
      await loadTransactions();
    };
  });
}

async function loadCatalog() {
  const [products, sales] = await Promise.all([
    api('/api/products'),
    api('/api/sales?limit=80'),
  ]);
  state.products = products;

  document.getElementById('productBody').innerHTML = products
    .map(
      (p) =>
        `<tr>
          <td>${p.code}</td><td>${p.name}</td><td>${p.kind_label || (p.kind === 'product' ? 'محصول' : 'خدمت')}</td>
          <td>${money(p.unit_price)}</td><td>${money(p.unit_cost)}</td>
          <td>${p.is_active ? 'فعال' : 'غیرفعال'}</td>
          <td class="row-actions">
            <button class="btn btn-ghost btn-sm" data-edit-product="${p.id}">ویرایش</button>
            <button class="btn btn-danger btn-sm" data-del-product="${p.id}">حذف</button>
          </td>
        </tr>`
    )
    .join('');
  document.getElementById('productCards').innerHTML = products
    .map(
      (p) =>
        `<div class="mobile-card-item">
          <div class="title">${p.name}</div>
          <div class="meta">
            <div>${p.code} · ${p.kind_label || ''}</div>
            <div>قیمت: <b>${money(p.unit_price)}</b></div>
            <div>بهای تمام‌شده: <b>${money(p.unit_cost)}</b></div>
          </div>
          <div class="row-actions" style="margin-top:10px">
            <button class="btn btn-ghost btn-sm" data-edit-product="${p.id}">ویرایش</button>
            <button class="btn btn-danger btn-sm" data-del-product="${p.id}">حذف</button>
          </div>
        </div>`
    )
    .join('');

  document.getElementById('saleBody').innerHTML = sales
    .map(
      (s) =>
        `<tr>
          <td>${toJalali(s.sale_date)}</td><td>${s.item_name}</td><td>${toFaDigits(s.quantity)}</td>
          <td>${money(s.revenue)}</td><td>${money(s.profit)}</td>
          <td class="row-actions">
            <button class="btn btn-danger btn-sm" data-del-sale="${s.id}">حذف</button>
          </td>
        </tr>`
    )
    .join('');
  document.getElementById('saleCards').innerHTML = sales
    .map(
      (s) =>
        `<div class="mobile-card-item">
          <div class="title">${s.item_name}</div>
          <div class="meta">
            <div>${toJalali(s.sale_date)} · مقدار ${toFaDigits(s.quantity)}</div>
            <div>درآمد: <b>${money(s.revenue)}</b> · سود: <b>${money(s.profit)}</b></div>
          </div>
          <div class="row-actions" style="margin-top:10px">
            <button class="btn btn-danger btn-sm" data-del-sale="${s.id}">حذف</button>
          </div>
        </div>`
    )
    .join('');

  document.querySelectorAll('[data-edit-product]').forEach((btn) => {
    btn.onclick = () => {
      const p = products.find((x) => String(x.id) === btn.dataset.editProduct);
      if (!p) return;
      openProductModal(p);
    };
  });
  document.querySelectorAll('[data-del-product]').forEach((btn) => {
    btn.onclick = async () => {
      if (!confirm('حذف / غیرفعال‌سازی این آیتم؟')) return;
      await api(`/api/products/${btn.dataset.delProduct}`, { method: 'DELETE' });
      await loadCatalog();
    };
  });
  document.querySelectorAll('[data-del-sale]').forEach((btn) => {
    btn.onclick = async () => {
      if (!confirm('این فروش حذف شود؟')) return;
      await api(`/api/sales/${btn.dataset.delSale}`, { method: 'DELETE' });
      await loadCatalog();
    };
  });
}

function openProductModal(p = null) {
  openModal(
    p ? 'ویرایش محصول/خدمت' : 'افزودن محصول/خدمت',
    `
    <div class="form-grid">
      <div class="field"><label>کد</label><input name="code" required maxlength="30" value="${p?.code || ''}" /></div>
      <div class="field"><label>نوع</label>
        <select name="kind">
          <option value="product" ${p?.kind === 'product' ? 'selected' : ''}>محصول</option>
          <option value="service" ${p?.kind === 'service' ? 'selected' : ''}>خدمت</option>
        </select>
      </div>
      <div class="field span-2"><label>نام</label><input name="name" required maxlength="150" value="${p?.name || ''}" /></div>
      <div class="field"><label>قیمت فروش</label><input class="num-fa" name="unit_price" inputmode="decimal" required value="${p?.unit_price != null ? formatFaNumber(p.unit_price) : ''}" /></div>
      <div class="field"><label>بهای تمام‌شده واحد</label><input class="num-fa" name="unit_cost" inputmode="decimal" required value="${p?.unit_cost != null ? formatFaNumber(p.unit_cost) : ''}" /></div>
    </div>`,
    async (fd) => {
      const unit_price = parseAmount(fd.get('unit_price'));
      const unit_cost = parseAmount(fd.get('unit_cost'));
      if (unit_cost > unit_price * 3 && unit_price > 0) {
        throw new Error('بهای تمام‌شده واحد نباید بیش از ۳ برابر قیمت فروش باشد');
      }
      const body = {
        code: fd.get('code'),
        name: fd.get('name'),
        kind: fd.get('kind'),
        unit_price,
        unit_cost,
        is_active: true,
      };
      if (p) await api(`/api/products/${p.id}`, { method: 'PUT', body: JSON.stringify(body) });
      else await api('/api/products', { method: 'POST', body: JSON.stringify(body) });
      await loadCatalog();
    }
  );
}

function openSaleModal() {
  const options = state.products
    .filter((p) => p.is_active)
    .map((p) => `<option value="${p.id}">${p.code} — ${p.name}</option>`)
    .join('');
  openModal(
    'ثبت فروش',
    `
    <div class="form-grid">
      <div class="field span-2"><label>محصول / خدمت</label><select name="item_id" required>${options}</select></div>
      <div class="field"><label>تاریخ</label><input type="date" name="sale_date" required value="${todayISO()}" /></div>
      <div class="field"><label>مقدار</label><input class="num-fa" name="quantity" inputmode="decimal" required value="${formatFaNumber(1)}" /></div>
      <div class="field span-2"><label>کانال</label><input name="channel" value="فروش مستقیم" /></div>
    </div>`,
    async (fd) => {
      const quantity = parseAmount(fd.get('quantity'));
      if (quantity <= 0) throw new Error('مقدار باید بزرگ‌تر از صفر باشد');
      await api('/api/sales', {
        method: 'POST',
        body: JSON.stringify({
          item_id: Number(fd.get('item_id')),
          sale_date: fd.get('sale_date'),
          quantity,
          channel: fd.get('channel') || 'فروش مستقیم',
          sync_transactions: true,
        }),
      });
      await loadCatalog();
    }
  );
}

async function loadEmployees() {
  const rows = await api('/api/employees');
  const cap = state.limits
    ? `سقف حقوق ماهانه: ${money(state.limits.max_monthly_salary)} · حداقل: ${money(state.limits.min_monthly_salary)}`
    : '';
  document.getElementById('salaryCapHint').textContent = cap;

  document.getElementById('empBody').innerHTML = rows
    .map(
      (e) =>
        `<tr>
          <td>${toFaDigits(e.code)}</td><td>${e.full_name}</td><td>${e.role}</td><td>${e.department}</td>
          <td>${money(e.monthly_salary)}</td>
          <td><button class="btn btn-ghost btn-sm" data-edit-emp="${e.id}">ویرایش</button></td>
        </tr>`
    )
    .join('');
  document.getElementById('empCards').innerHTML = rows
    .map(
      (e) =>
        `<div class="mobile-card-item">
          <div class="title">${e.full_name}</div>
          <div class="meta">
            <div>${e.code} · ${e.role}</div>
            <div>${e.department}</div>
            <div><b>${money(e.monthly_salary)}</b></div>
          </div>
          <div class="row-actions" style="margin-top:10px">
            <button class="btn btn-ghost btn-sm" data-edit-emp="${e.id}">ویرایش حقوق</button>
          </div>
        </div>`
    )
    .join('');

  document.querySelectorAll('[data-edit-emp]').forEach((btn) => {
    btn.onclick = () => {
      const e = rows.find((r) => String(r.id) === btn.dataset.editEmp);
      if (!e) return;
      openModal(
        'ویرایش پرسنل',
        `
        <div class="form-grid">
          <div class="field span-2"><label>نام</label><input name="full_name" required value="${e.full_name}" /></div>
          <div class="field span-2"><label>سمت</label><input name="role" required value="${e.role}" /></div>
          <div class="field span-2"><label>حقوق ماهانه (ریال)</label><input class="num-fa" name="monthly_salary" inputmode="decimal" required value="${formatFaNumber(e.monthly_salary)}" /></div>
        </div>
        <div class="hint">${cap}</div>`,
        async (fd) => {
          const monthly_salary = validateSalaryClient(fd.get('monthly_salary'));
          await api(`/api/employees/${e.id}`, {
            method: 'PUT',
            body: JSON.stringify({
              full_name: fd.get('full_name'),
              role: fd.get('role'),
              monthly_salary,
            }),
          });
          await loadEmployees();
        }
      );
    };
  });
}

async function generateVoice() {
  const btn = document.getElementById('generateVoiceBtn');
  btn.disabled = true;
  btn.textContent = 'در حال تولید...';
  try {
    const data = await api('/api/voice/daily?force=true', { method: 'POST' });
    document.getElementById('voiceScript').textContent = data.script_text;
    document.getElementById('voiceMeta').textContent =
      `تاریخ ${toJalali(data.report_date)} | حالت تولید: ${data.generation_mode} | مدت تقریبی: ${data.duration_hint}`;
    if (data.audio_url) await setAuthenticatedAudio(`${data.audio_url}?t=${Date.now()}`);
  } catch (err) {
    document.getElementById('voiceMeta').textContent = err.message;
  } finally {
    btn.disabled = false;
    btn.textContent = 'تولید ویس گزارش امروز';
  }
}

async function loadLimits() {
  try {
    state.limits = await api('/api/settings/limits');
    document.getElementById('limitsHint').textContent =
      `سقف حقوق: ${money(state.limits.max_monthly_salary)}`;
  } catch {
    state.limits = null;
  }
}

function updateAiQuotaPill(quota) {
  const pill = document.getElementById('aiQuotaPill');
  if (!pill || !quota) return;
  pill.textContent = `باقی‌مانده امروز: ${toFaDigits(quota.remaining)} از ${toFaDigits(quota.limit)}`;
}

function renderAiThread(rows) {
  const thread = document.getElementById('aiThread');
  if (!rows?.length) {
    thread.innerHTML =
      '<div class="hint">هنوز سؤالی نپرسیده‌اید. از پیشنهادها استفاده کنید یا سؤال خود را بنویسید.</div>';
    return;
  }
  const ordered = [...rows].reverse();
  thread.innerHTML = ordered
    .map(
      (r) => `
      <div class="chat-bubble user"><div class="who">شما</div>${escapeHtml(toFaDigits(r.question))}</div>
      <div class="chat-bubble bot"><div class="who">دستیار (${r.mode === 'openai' ? 'AI' : 'خودکار'})</div>${escapeHtml(toFaDigits(r.answer))}</div>
    `
    )
    .join('');
  thread.scrollTop = thread.scrollHeight;
}

function escapeHtml(text) {
  return String(text || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

async function loadAiChat() {
  const err = document.getElementById('aiError');
  err.classList.add('hidden');
  try {
    const [quota, history] = await Promise.all([
      api('/api/ai/quota'),
      api('/api/ai/history'),
    ]);
    updateAiQuotaPill(quota);
    const box = document.getElementById('aiSuggestions');
    box.innerHTML = (quota.suggestions || [])
      .map((s) => `<button type="button" class="suggest-chip">${escapeHtml(s)}</button>`)
      .join('');
    box.querySelectorAll('.suggest-chip').forEach((btn) => {
      btn.addEventListener('click', () => {
        document.getElementById('aiQuestionInput').value = btn.textContent;
        document.getElementById('aiAskForm').requestSubmit();
      });
    });
    const askBtn = document.getElementById('aiAskBtn');
    askBtn.disabled = quota.remaining <= 0;
    renderAiThread(history);
  } catch (e) {
    err.textContent = e.message;
    err.classList.remove('hidden');
  }
}

async function submitAiQuestion(question) {
  const err = document.getElementById('aiError');
  const btn = document.getElementById('aiAskBtn');
  err.classList.add('hidden');
  btn.disabled = true;
  btn.textContent = 'در حال پاسخ...';
  try {
    const data = await api('/api/ai/ask', {
      method: 'POST',
      body: JSON.stringify({ question }),
    });
    updateAiQuotaPill({ remaining: data.remaining, limit: data.limit });
    document.getElementById('aiQuestionInput').value = '';
    await loadAiChat();
  } catch (e) {
    err.textContent = e.message;
    err.classList.remove('hidden');
  } finally {
    btn.textContent = 'بپرس';
    const quota = await api('/api/ai/quota').catch(() => null);
    if (quota) {
      updateAiQuotaPill(quota);
      btn.disabled = quota.remaining <= 0;
    } else {
      btn.disabled = false;
    }
  }
}

function bindUi() {
  const user = JSON.parse(localStorage.getItem('finlytics_user') || '{}');
  document.getElementById('userName').textContent = user.full_name || 'کاربر';
  document.getElementById('userPhone').textContent = toFaDigits(user.phone || '');

  document.querySelectorAll('.nav-item').forEach((el) => {
    el.addEventListener('click', (e) => {
      e.preventDefault();
      setPage(el.dataset.page);
      closeMenu();
    });
  });

  document.querySelectorAll('#periodTabs .chip').forEach((chip) => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('#periodTabs .chip').forEach((c) => c.classList.remove('active'));
      chip.classList.add('active');
      state.period = chip.dataset.period;
      if (state.page === 'dashboard') loadDashboard();
      if (state.page === 'reports') loadReports();
    });
  });

  document.querySelectorAll('[data-dir]').forEach((chip) => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('[data-dir]').forEach((c) => c.classList.remove('active'));
      chip.classList.add('active');
      state.txDir = chip.dataset.dir;
      loadTransactions();
    });
  });

  document.getElementById('logoutBtn').addEventListener('click', () => {
    localStorage.clear();
    document.cookie = 'access_token=; path=/; max-age=0';
    location.href = '/login';
  });

  document.getElementById('menuBtn').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('show');
  });
  document.getElementById('overlay').addEventListener('click', closeMenu);

  document.getElementById('addTxnBtn')?.addEventListener('click', () => {
    openModal('افزودن تراکنش', txnFormFields(), async (fd) => {
      const amount = parseAmount(fd.get('amount'));
      await api('/api/transactions', {
        method: 'POST',
        body: JSON.stringify({
          txn_date: fd.get('txn_date'),
          direction: fd.get('direction'),
          category: fd.get('category'),
          title: fd.get('title'),
          amount,
          note: fd.get('note') || null,
          source: 'manual',
        }),
      });
      await loadTransactions();
    });
  });
  document.getElementById('addProductBtn')?.addEventListener('click', () => openProductModal());
  document.getElementById('addSaleBtn')?.addEventListener('click', openSaleModal);

  document.getElementById('downloadTemplateBtn').addEventListener('click', async () => {
    const res = await fetch('/api/excel/template', {
      headers: { Authorization: `Bearer ${token()}` },
    });
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'finlytics_transactions_template.xlsx';
    a.click();
    URL.revokeObjectURL(url);
  });

  document.getElementById('uploadExcelBtn').addEventListener('click', async () => {
    const file = document.getElementById('excelFile').files[0];
    const box = document.getElementById('importResult');
    if (!file) {
      box.textContent = 'ابتدا فایل اکسل را انتخاب کنید.';
      return;
    }
    const fd = new FormData();
    fd.append('file', file);
    try {
      const data = await api('/api/excel/import', { method: 'POST', body: fd });
      box.textContent =
        `وارد شد: ${data.imported} | رد شد: ${data.skipped}` +
        (data.errors?.length ? ` | خطاها: ${data.errors.join(' ؛ ')}` : '');
    } catch (err) {
      box.textContent = err.message;
    }
  });

  document.getElementById('generateVoiceBtn').addEventListener('click', generateVoice);

  document.getElementById('aiAskForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const q = document.getElementById('aiQuestionInput').value.trim();
    if (!q) return;
    await submitAiQuestion(q);
  });

  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (state.page === 'dashboard') loadDashboard();
      if (state.page === 'reports') loadReports();
    }, 250);
  });
}

document.addEventListener('DOMContentLoaded', async () => {
  if (!ensureAuth()) return;
  bindUi();
  await loadLimits();
  const hash = (location.hash || '#dashboard').replace('#', '');
  setPage(hash || 'dashboard');
});
