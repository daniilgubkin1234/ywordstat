const $btn     = document.getElementById('btnCollect');
const $bar     = document.getElementById('bar');
const $stat    = document.getElementById('status');
const $filters = document.querySelector('.filters');
const $pager   = document.querySelector('nav.pager');
const $export  = document.getElementById('btnExport');
const $tbody   = document.querySelector('.table-sticky tbody');
const $lastRun = document.getElementById('lastRun');

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content?.trim() || '';

let collecting = false; 

function setStatus(text, isError = false) {
  if (!$stat) return;
  $stat.textContent = text;
  $stat.classList.toggle('error', !!isError);
}

function setProgress(done, total, text) {
  const pct = total > 0 ? Math.round((done * 100) / total) : 0;
  if ($bar) $bar.style.width = pct + '%';
  setStatus(text ?? `${done}/${total}…`);
}

function lockUI(lock) {
  if ($filters) {
    $filters.querySelectorAll('input, select, button, a.btn').forEach(el => {
      if (lock) {
        el.setAttribute('data-prev-disabled', el.disabled ? '1' : '');
        el.disabled = true;
      } else {
        if (el.getAttribute('data-prev-disabled') === '') el.disabled = false;
        el.removeAttribute('data-prev-disabled');
      }
    });
  }

  if ($pager)  $pager.classList.toggle('is-disabled', !!lock);

  if ($export) {
    if (lock) {
      $export.setAttribute('aria-disabled', 'true');
      $export.style.pointerEvents = 'none';
      $export.style.opacity = '0.55';
    } else {
      $export.removeAttribute('aria-disabled');
      $export.style.pointerEvents = '';
      $export.style.opacity = '';
    }
  }

  if ($btn) $btn.disabled = !!lock;
}

function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}
window.addEventListener('error', (e) => {
  setStatus(`Неожиданная ошибка: ${e.message}`, true);
  console.error('window.error:', e);
});

window.addEventListener('unhandledrejection', (e) => {
  const msg = e.reason?.message || String(e.reason || 'Неизвестная ошибка');
  setStatus(`Ошибка выполнения: ${msg}`, true);
  console.error('unhandledrejection:', e.reason);
});

async function parseJson(resp) {
  const txt = await resp.text();
  if (!txt) return null;
  try { return JSON.parse(txt); } catch { return null; }
}

async function apiFetch(url, options = {}) {
  const opts = {
    method: 'GET',
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' },
    redirect: 'follow',
    ...options,
  };
  if (opts.method && opts.method.toUpperCase() !== 'GET') {
    opts.headers['X-CSRF-Token'] = CSRF;
  }

  let resp;
  try {
    resp = await fetch(url, opts);
  } catch (networkErr) {
    throw new Error(`[NETWORK] ${networkErr.message || networkErr}`);
  }

  const data = await parseJson(resp);

  if (!resp.ok || (data && data.ok === false)) {
    const code = data?.code ? `[${data.code}] ` : '';
    const msg  = (data?.message || data?.error || `HTTP ${resp.status}`);
    const rid  = data?.requestId ? ` (requestId: ${data.requestId})` : '';
    throw new Error(`${code}${msg}${rid}`);
  }

  return data ?? { ok: true };
}
async function refreshTableAndPager() {
  try {
    const url = new URL(window.location.href);
    url.searchParams.set('_', Date.now()); 
    const resp = await fetch(url.toString(), { credentials: 'same-origin' });
    if (!resp.ok) throw new Error('Ошибка обновления таблицы');
    const html = await resp.text();
    const doc  = new DOMParser().parseFromString(html, 'text/html');

    const newTbody = doc.querySelector('.table-sticky tbody');
    const newPager = doc.querySelector('nav.pager');
    const newLast  = doc.getElementById('lastRun');

    if (newTbody && $tbody) $tbody.innerHTML = newTbody.innerHTML;
    if ($pager && newPager) {
      $pager.innerHTML = newPager.innerHTML;
      $pager.classList.remove('is-disabled');
    }
    if ($lastRun && newLast) $lastRun.textContent = newLast.textContent;

    setStatus('Готово! Данные обновлены.');
  } catch (e) {
    console.error('Не удалось обновить таблицу, перезагружаю страницу:', e);
    location.reload();
  }
}

async function runCollect() {
  if (collecting) return; 
  collecting = true;

  try {
    lockUI(true);
    setProgress(0, 0, 'Инициализация…');

    const init = await apiFetch('/collect.php', { method: 'POST' });
    const total = Number(init.total ?? 0);
    let done = init.resume ? Number(init.done ?? 0) : 0;

    if (!total || total < 0) {
      throw new Error('[EMPTY_TOTAL] Нет задач для сбора. Проверьте справочники.');
    }
    setProgress(done, total, `Обработка ${done}/${total}…`);

    let attempts = 0;
    while (done < total) {
      try {
        const data = await apiFetch('/collect.php', { method: 'POST' });
        done = Number(data.done ?? (done + 1));
        const tail = data.phrase ? ` — ${data.phrase}` : '';
        setProgress(done, total, `Обработка ${done}/${total}${tail}`);
        if (data.finished) break;

        attempts = 0;         
        await sleep(60);      
      } catch (stepErr) {
        attempts++;
        const delay = Math.min(2000 * attempts, 10000); // 2s, 4s, 6s… до 10s
        setStatus(`Временная ошибка шага: ${stepErr.message}. Повтор через ${delay/1000} с…`);
        await sleep(delay);
        if (attempts >= 5) throw stepErr; 
      }
    }
    if (done >= total) {
      setProgress(total, total, 'Готово! Данные обновляются…');
      await refreshTableAndPager();
    }
  } catch (err) {
    setStatus(String(err.message || err), true);
    console.error('runCollect fatal:', err);
  } finally {
    lockUI(false);
    collecting = false;
  }
}

$btn?.addEventListener('click', (e) => {
  e.preventDefault();
  runCollect();
});
