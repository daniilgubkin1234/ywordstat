const $btn     = document.getElementById('btnCollect');
const $bar     = document.getElementById('bar');
const $stat    = document.getElementById('status');
const $filters = document.querySelector('.filters');
const $pager   = document.querySelector('nav.pager');
const $export  = document.getElementById('btnExport');
const $tbody   = document.querySelector('.table-sticky tbody');
const $lastRun = document.getElementById('lastRun');

function setProgress(done, total, text) {
  const pct = total > 0 ? Math.round((done * 100) / total) : 0;
  if ($bar)  $bar.style.width = pct + '%';
  if ($stat) $stat.textContent = text ?? `${done}/${total}…`;
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function safeJson(resp) {
  const ct = resp.headers.get('content-type') || '';
  const text = await resp.text();
  try { return ct.includes('application/json') ? JSON.parse(text) : JSON.parse(text); }
  catch { return null; }
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
  
  if ($pager) {
    if (lock) $pager.classList.add('is-disabled');
    else $pager.classList.remove('is-disabled');
  }
  
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

  if ($btn) {
    $btn.disabled = !!lock;
  }
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

    if (newTbody && $tbody) {
      $tbody.innerHTML = newTbody.innerHTML;
    }
    if ($pager && newPager) {
      $pager.innerHTML = newPager.innerHTML;
    
      $pager.classList.remove('is-disabled');
    }
    if ($lastRun && newLast) {                            
      $lastRun.textContent = newLast.textContent;
    }
    if ($stat) $stat.textContent = 'Готово! Данные обновлены.';
  } catch (e) {
    console.error('Не удалось  обновить таблицу, делаю reload:', e);
    location.reload(); 
  }
}

$btn?.addEventListener('click', async () => {
  try {
    lockUI(true);
    setProgress(0, 0, 'Инициализация…');

    let r = await fetch('/collect.php', { method: 'POST', credentials: 'same-origin' });
    if (!r.ok) throw new Error('Ошибка инициализации');
    let init = await safeJson(r);
    if (!init || init.ok === false) throw new Error(init?.error || 'init');

    const total = Number(init.total ?? 0);
    let done = init.resume ? Number(init.done ?? 0) : 0;
    setProgress(done, total, `Обработка ${done}/${total}…`);

    while (done < total) {
      const resp = await fetch('/collect.php', { method: 'POST', credentials: 'same-origin' });
      if (!resp.ok) throw new Error('Сбой шага сбора');
      const data = await safeJson(resp);
      if (!data || data.ok === false) throw new Error(data?.error || 'step');

      const tail = data.phrase ? ` — ${data.phrase}` : '';
      done = Number(data.done ?? (done + 1));
      setProgress(done, total, `Обработка ${done}/${total}${tail}`);

      if (data.finished) break;
      await sleep(60); 
    }

    if (done >= total) {
      setProgress(total, total, 'Готово! Данные обновляются…');
      await refreshTableAndPager();
    }
  } catch (err) {
    console.error(err);
    alert(String(err.message || err));
  } finally {
    lockUI(false);
  }
});
