function setText(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value;
}

function drawDoughnut(canvas, labels, values, colors) {
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  if (!ctx) return;

  const w = canvas.width || canvas.clientWidth || 300;
  const h = canvas.height || canvas.clientHeight || 180;
  canvas.width = w;
  canvas.height = h;

  const total = values.reduce((a, b) => a + (Number.isFinite(b) ? b : 0), 0);

  ctx.clearRect(0, 0, w, h);

  const cx = Math.floor(w / 2);
  const cy = Math.floor(h / 2);
  const outer = Math.min(w, h) * 0.42;
  const inner = outer * 0.62;

  const bg = '#e9edf5';
  ctx.beginPath();
  ctx.arc(cx, cy, outer, 0, Math.PI * 2);
  ctx.arc(cx, cy, inner, 0, Math.PI * 2, true);
  ctx.fillStyle = bg;
  ctx.fill();

  if (total <= 0) {
    ctx.fillStyle = '#6c757d';
    ctx.font = '600 14px var(--font-th, system-ui)';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('ไม่มีข้อมูล', cx, cy);
    return;
  }

  let start = -Math.PI / 2;
  for (let i = 0; i < values.length; i++) {
    const v = Number.isFinite(values[i]) ? values[i] : 0;
    if (v <= 0) continue;
    const slice = (v / total) * Math.PI * 2;
    const end = start + slice;

    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.arc(cx, cy, outer, start, end);
    ctx.closePath();
    ctx.fillStyle = colors[i] || '#adb5bd';
    ctx.fill();

    start = end;
  }

  ctx.globalCompositeOperation = 'destination-out';
  ctx.beginPath();
  ctx.arc(cx, cy, inner, 0, Math.PI * 2);
  ctx.fill();
  ctx.globalCompositeOperation = 'source-over';

  ctx.fillStyle = '#0b2a5b';
  ctx.font = '700 16px var(--font-th, system-ui)';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(String(total), cx, cy - 2);

  ctx.fillStyle = '#6c757d';
  ctx.font = '600 12px var(--font-th, system-ui)';
  ctx.fillText('คน', cx, cy + 16);
}

function initDoughnuts() {
  document.querySelectorAll('canvas[data-doughnut]').forEach((canvas) => {
    const raw = canvas.getAttribute('data-doughnut') || '';
    if (!raw) return;
    let payload;
    try {
      payload = JSON.parse(raw);
    } catch {
      return;
    }
    const labels = Array.isArray(payload.labels) ? payload.labels : [];
    const data = Array.isArray(payload.data) ? payload.data.map((n) => Number(n) || 0) : [];
    const colors = Array.isArray(payload.colors) ? payload.colors : [];
    drawDoughnut(canvas, labels, data, colors);
  });
}

function initDataTables() {
  document.querySelectorAll('[data-dt]').forEach((wrap) => {
    const table = wrap.querySelector('[data-dt-table]');
    if (!table) return;
    const tbody = table.tBodies && table.tBodies[0] ? table.tBodies[0] : null;
    if (!tbody) return;

    const searchEl = wrap.querySelector('[data-dt-search]');
    const lengthEl = wrap.querySelector('[data-dt-length]');
    const infoEl = wrap.querySelector('[data-dt-info]');
    const pagerEl = wrap.querySelector('[data-dt-pager]');

    let rows = Array.from(tbody.querySelectorAll('tr'));
    let query = '';
    let page = 1;
    let pageSize = lengthEl ? parseInt(lengthEl.value, 10) || 25 : 25;
    let sortIndex = -1;
    let sortDir = 'asc';

    function getCellSortValue(tr, idx) {
      const td = tr.children && tr.children[idx] ? tr.children[idx] : null;
      if (!td) return '';
      const ds = td.getAttribute('data-sort');
      if (ds !== null && ds !== undefined && String(ds).trim() !== '') return String(ds).trim();
      return (td.textContent || '').trim();
    }

    function normalize(str) {
      return (str || '').toString().toLowerCase();
    }

    function applyFilter(list) {
      if (!query) return list;
      const q = normalize(query);
      return list.filter((tr) => normalize(tr.textContent).includes(q));
    }

    function applySort(list) {
      if (sortIndex < 0) return list;
      const dir = sortDir === 'desc' ? -1 : 1;
      const sorted = [...list];
      sorted.sort((a, b) => {
        const av = getCellSortValue(a, sortIndex);
        const bv = getCellSortValue(b, sortIndex);
        if (av === bv) return 0;
        return av > bv ? dir : -dir;
      });
      return sorted;
    }

    function renderPager(totalRows) {
      if (!pagerEl) return;
      pagerEl.innerHTML = '';
      const totalPages = Math.max(1, Math.ceil(totalRows / pageSize));
      if (page > totalPages) page = totalPages;

      function addBtn(label, targetPage, disabled) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-light';
        btn.textContent = label;
        btn.disabled = !!disabled;
        btn.addEventListener('click', () => {
          page = targetPage;
          render();
        });
        pagerEl.appendChild(btn);
      }

      addBtn('ก่อนหน้า', Math.max(1, page - 1), page <= 1);

      const maxButtons = 7;
      let start = Math.max(1, page - Math.floor(maxButtons / 2));
      let end = Math.min(totalPages, start + maxButtons - 1);
      start = Math.max(1, end - maxButtons + 1);

      for (let p = start; p <= end; p++) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm ' + (p === page ? 'btn-gov' : 'btn-light');
        btn.textContent = String(p);
        btn.addEventListener('click', () => {
          page = p;
          render();
        });
        pagerEl.appendChild(btn);
      }

      addBtn('ถัดไป', Math.min(totalPages, page + 1), page >= totalPages);
    }

    function renderInfo(startIdx, endIdx, totalFiltered, totalAll) {
      if (!infoEl) return;
      if (totalAll === 0) {
        infoEl.textContent = 'ไม่พบข้อมูล';
        return;
      }
      infoEl.textContent = `แสดง ${startIdx}-${endIdx} จาก ${totalFiltered} รายการ`;
    }

    function render() {
      pageSize = lengthEl ? parseInt(lengthEl.value, 10) || pageSize : pageSize;
      const filtered = applySort(applyFilter(rows));
      const total = filtered.length;
      const totalPages = Math.max(1, Math.ceil(total / pageSize));
      if (page > totalPages) page = totalPages;
      if (page < 1) page = 1;

      const start = (page - 1) * pageSize;
      const end = Math.min(start + pageSize, total);
      const visible = filtered.slice(start, end);

      const frag = document.createDocumentFragment();
      visible.forEach((tr) => frag.appendChild(tr));
      tbody.innerHTML = '';
      tbody.appendChild(frag);

      renderPager(total);
      renderInfo(total === 0 ? 0 : start + 1, end, total, rows.length);
    }

    if (searchEl) {
      searchEl.addEventListener('input', () => {
        query = searchEl.value || '';
        page = 1;
        render();
      });
    }

    if (lengthEl) {
      lengthEl.addEventListener('change', () => {
        pageSize = parseInt(lengthEl.value, 10) || pageSize;
        page = 1;
        render();
      });
    }

    table.querySelectorAll('thead th[data-dt-sort]').forEach((th, idx) => {
      th.style.cursor = 'pointer';
      th.addEventListener('click', () => {
        if (sortIndex === idx) {
          sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
          sortIndex = idx;
          sortDir = 'asc';
        }
        render();
      });
    });

    render();
  });
}

async function captureGPS(latId, lngId, statusId) {
  setText(statusId, 'กำลังขอพิกัด...');
  if (!navigator.geolocation) {
    setText(statusId, 'อุปกรณ์ไม่รองรับ GPS');
    return;
  }
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;
      const latEl = document.getElementById(latId);
      const lngEl = document.getElementById(lngId);
      if (latEl) latEl.value = lat;
      if (lngEl) lngEl.value = lng;
      setText(statusId, `บันทึกพิกัดแล้ว: ${lat.toFixed(6)}, ${lng.toFixed(6)}`);
    },
    (err) => {
      setText(statusId, 'ไม่สามารถดึงพิกัดได้: ' + err.message);
    },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
  );
}

document.addEventListener('DOMContentLoaded', () => {
  initDoughnuts();
  initDataTables();
  document.querySelectorAll('[data-gps]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const latId = btn.getAttribute('data-lat');
      const lngId = btn.getAttribute('data-lng');
      const statusId = btn.getAttribute('data-status');
      captureGPS(latId, lngId, statusId);
    });
  });
});
