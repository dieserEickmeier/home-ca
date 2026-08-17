'use strict';

const appEl = document.getElementById('app');

let state = {
  loggedIn: false,
  caExists: false,
  view: 'dashboard',
  issueTab: 'generate',
  certsFilter: 'issued',
  caInfo: null,
  certs: [],
  lastGenerated: null,
  modal: null,
  loginError: '',
};

// ---------- API helpers ----------

async function api(action, { method = 'GET', body = null, params = {} } = {}) {
  const url = new URL('api.php', window.location.href);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  const opts = { method: body !== null && method === 'GET' ? 'POST' : method, headers: {} };
  if (body !== null) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  const res = await fetch(url, opts);
  let payload = null;
  try { payload = await res.json(); } catch (e) { /* no body */ }
  if (!res.ok) {
    throw new Error((payload && payload.error) || `Fehler (${res.status})`);
  }
  return payload;
}

async function downloadFile(action, params = {}) {
  const url = new URL('api.php', window.location.href);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  const res = await fetch(url);
  if (!res.ok) {
    let msg = 'Download fehlgeschlagen.';
    try { const j = await res.json(); msg = j.error || msg; } catch (e) {}
    throw new Error(msg);
  }
  const blob = await res.blob();
  const disposition = res.headers.get('Content-Disposition') || '';
  const match = disposition.match(/filename="(.+?)"/);
  triggerDownload(blob, match ? match[1] : 'download');
}

function downloadBlob(content, filename, mime) {
  triggerDownload(new Blob([content], { type: mime }), filename);
}

function triggerDownload(blob, filename) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

async function safeDownload(fn) {
  try { await fn(); } catch (err) { toast(err.message, 'error'); }
}

// ---------- Utilities ----------

function esc(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function fmtDate(iso) {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString('de-DE', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
  } catch (e) { return iso; }
}

const OPENSSL_MONTHS = { Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5, Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11 };

function parseOpensslDate(str) {
  if (!str) return null;
  const m = String(str).match(/^(\w{3})\s+(\d{1,2})\s+(\d{2}):(\d{2}):(\d{2})\s+(\d{4})\s+GMT$/);
  if (!m || !(m[1] in OPENSSL_MONTHS)) return null;
  return new Date(Date.UTC(Number(m[6]), OPENSSL_MONTHS[m[1]], Number(m[2]), Number(m[3]), Number(m[4]), Number(m[5])));
}

function daysUntilExpiry(opensslDate) {
  const d = parseOpensslDate(opensslDate);
  if (!d) return null;
  return Math.floor((d.getTime() - Date.now()) / 86400000);
}

function renderExpiryBanner() {
  const issued = state.certs.filter(c => c.status === 'issued');
  const soon = issued
    .map(c => ({ ...c, daysLeft: daysUntilExpiry(c.expires_at) }))
    .filter(c => c.daysLeft !== null && c.daysLeft <= 14);
  if (soon.length === 0) return '';

  const risky = soon.filter(c => !c.auto_renew);
  const covered = soon.filter(c => c.auto_renew);
  let html = '';
  if (risky.length > 0) {
    const names = risky.map(c => `${esc(c.common_name)} (${c.daysLeft}d)`).join(', ');
    html += `<div class="notice error" style="margin-bottom:16px"><strong>${risky.length} Zertifikat${risky.length > 1 ? 'e' : ''} ohne Auto-Renew läuft/laufen bald ab:</strong> ${names}</div>`;
  }
  if (covered.length > 0) {
    const names = covered.map(c => esc(c.common_name)).join(', ');
    html += `<div class="notice warn" style="margin-bottom:16px">${covered.length} mit Auto-Renew läuft/laufen bald ab, wird automatisch erneuert: ${names}</div>`;
  }
  return html;
}

function labelForStatus(s) {
  return { pending: 'Ausstehend', issued: 'Ausgestellt', rejected: 'Abgelehnt', revoked: 'Widerrufen' }[s] || s;
}

function caInitials() {
  if (!state.caInfo || !state.caInfo.subject) return 'CA';
  const m = state.caInfo.subject.match(/CN\s*=\s*([^,]+)/);
  if (!m) return 'CA';
  const words = m[1].trim().split(/\s+/).slice(0, 2);
  return words.map(w => w[0]).join('').toUpperCase();
}

function setButtonBusy(btn, label) {
  if (!btn.dataset.originalHtml) {
    btn.dataset.originalHtml = btn.innerHTML;
  }
  btn.disabled = true;
  btn.innerHTML = `<span class="spinner"></span>${esc(label)}`;
}

function restoreButton(btn) {
  if (!document.body.contains(btn)) return;
  btn.disabled = false;
  if (btn.dataset.originalHtml) {
    btn.innerHTML = btn.dataset.originalHtml;
    delete btn.dataset.originalHtml;
  }
}

function toast(msg, type = 'ok') {
  let wrap = document.querySelector('.toast-wrap');
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.className = 'toast-wrap';
    document.body.appendChild(wrap);
  }
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.textContent = msg;
  wrap.appendChild(el);
  setTimeout(() => el.remove(), 4000);
}

// ---------- Data ----------

async function refreshAll() {
  if (state.caExists) {
    try { state.caInfo = await api('ca_info'); } catch (e) { state.caInfo = null; }
    try { state.npmConfig = await api('npm_settings_get'); } catch (e) { state.npmConfig = null; }
    try { state.notifyConfig = await api('notify_settings_get'); } catch (e) { state.notifyConfig = null; }
  }
  try { state.certs = await api('csr_list'); } catch (e) { state.certs = []; }
}

// ---------- DataTables ----------

const DT_LANG_DE = {
  emptyTable: 'Keine Einträge.',
  info: '_START_–_END_ von _TOTAL_',
  infoEmpty: '0 von 0',
  infoFiltered: '(gefiltert aus _MAX_)',
  lengthMenu: '_MENU_ pro Seite',
  loadingRecords: 'Wird geladen…',
  processing: 'Bitte warten…',
  search: 'Suchen:',
  zeroRecords: 'Keine Treffer.',
  paginate: { first: 'Erste', last: 'Letzte', next: 'Weiter', previous: 'Zurück' },
  aria: {
    orderable: ': Spalte sortieren',
    orderableReverse: ': Sortierung umkehren',
  },
};

const DT_OPTIONS = {
  language: DT_LANG_DE,
  pageLength: 10,
  order: [],
  columnDefs: [{ targets: -1, orderable: false, searchable: false }],
};

let queueDataTable = null;
let certsDataTable = null;

function initDataTables() {
  try { if (queueDataTable) queueDataTable.destroy(); } catch (e) { /* node already gone */ }
  try { if (certsDataTable) certsDataTable.destroy(); } catch (e) { /* node already gone */ }
  queueDataTable = null;
  certsDataTable = null;

  if (typeof DataTable === 'undefined') return;

  const queueEl = document.getElementById('queue-table');
  if (queueEl) queueDataTable = new DataTable('#queue-table', DT_OPTIONS);

  const certsEl = document.getElementById('certs-table');
  if (certsEl) certsDataTable = new DataTable('#certs-table', DT_OPTIONS);
}

// ---------- Render ----------

function render() {
  if (!state.loggedIn) {
    appEl.innerHTML = renderLogin();
    return;
  }
  if (!state.caExists) state.view = 'setup';
  appEl.innerHTML = renderShell();
  initDataTables();
}

function renderLogin() {
  return `
    <div class="login-screen">
      <div class="login-card">
        <div class="seal" data-initials="CA"></div>
        <div class="login-title">Home CA</div>
        <div class="login-sub">Lokale Zertifizierungsstelle</div>
        <form data-form="login">
          <div class="field">
            <label for="login-password">Admin-Passwort</label>
            <input type="password" id="login-password" name="password" autofocus required style="width:100%">
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center">Anmelden</button>
          <div class="login-error">${esc(state.loginError)}</div>
        </form>
      </div>
    </div>
  `;
}

function renderShell() {
  const pendingCount = state.certs.filter(c => c.status === 'pending').length;
  const nav = [
    ['dashboard', 'CA-Übersicht', 0],
    ['issue', 'Zertifikat beantragen', 0],
    ['queue', 'Warteschlange', pendingCount],
    ['certs', 'Zertifikate', 0],
  ];
  const navHtml = nav.map(([id, label, count]) => `
    <button class="nav-item ${state.view === id ? 'active' : ''}" data-action="nav" data-view="${id}" ${!state.caExists && id !== 'dashboard' ? 'disabled' : ''}>
      <span>${esc(label)}</span>
      ${count ? `<span class="nav-count">${count}</span>` : ''}
    </button>
  `).join('');

  return `
    <div class="shell">
      <div class="sidebar">
        <div class="brand">
          <div class="seal sm" data-initials="${esc(caInitials())}"></div>
          <div class="brand-name">Home CA<small>Lokale PKI</small></div>
        </div>
        <nav>${navHtml}</nav>
        <div class="sidebar-footer">
          <button class="btn btn-ghost btn-sm" data-action="logout" style="width:100%">Abmelden</button>
        </div>
      </div>
      <div class="main">${renderView()}</div>
    </div>
    ${renderModal()}
  `;
}

function renderView() {
  switch (state.view) {
    case 'setup': return renderSetup();
    case 'issue': return renderIssue();
    case 'queue': return renderQueue();
    case 'certs': return renderCerts();
    case 'dashboard':
    default: return renderDashboard();
  }
}

function renderSetup() {
  return `
    <h1 class="view-title">CA einrichten</h1>
    <p class="view-sub">Root-Zertifikat für dein Heimnetz erzeugen. Einmalig — Subject und Schlüssel sind danach fix.</p>
    <div class="panel panel-narrow">
      <form data-form="ca-create">
        <div class="field"><label>Common Name (CN)</label><input name="cn" required placeholder="Home Lab Root CA" style="width:100%"></div>
        <div class="field-row">
          <div class="field"><label>Organisation (O)</label><input name="o" placeholder="optional" style="width:100%"></div>
          <div class="field"><label>Land (C)</label><input name="c" placeholder="z.B. DE" maxlength="2" style="width:100%"></div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Schlüsseltyp</label>
            <select name="key_algo" data-role="algo-select" style="width:100%">
              <option value="RSA">RSA</option>
              <option value="EC">EC (elliptisch)</option>
            </select>
          </div>
          <div class="field">
            <label>Schlüsselparameter</label>
            <select name="key_param" data-role="param-select" style="width:100%">
              <option value="4096">4096 Bit</option>
              <option value="3072">3072 Bit</option>
              <option value="2048">2048 Bit</option>
            </select>
          </div>
        </div>
        <div class="field"><label>Gültigkeit (Tage)</label><input name="validity_days" type="number" value="3650"></div>
        <button type="submit" class="btn btn-primary">CA erzeugen</button>
      </form>
    </div>
  `;
}

function renderDashboard() {
  if (!state.caExists) return renderSetup();
  const info = state.caInfo;
  if (!info) return `<h1 class="view-title">CA-Übersicht</h1><p class="view-sub">Lädt…</p>`;
  const npm = state.npmConfig || {};
  const notify = state.notifyConfig || {};
  const cnMatch = (info.subject || '').match(/CN\s*=\s*([^,]+)/);
  const cn = cnMatch ? cnMatch[1].trim() : '—';

  return `
    <h1 class="view-title">CA-Übersicht</h1>
    <p class="view-sub">Root-Zertifikat und Bestand.</p>

    ${renderExpiryBanner()}

    <div class="dashboard-grid">
      <div class="panel">
        <div style="display:flex; gap:18px; align-items:center; margin-bottom:20px">
          <div class="seal" data-initials="${esc(caInitials())}"></div>
          <div>
            <div style="font-family:var(--font-mono); font-weight:600; font-size:15px">${esc(cn)}</div>
            <div style="color:var(--text-muted); font-size:12px">${esc(info.subject)}</div>
          </div>
        </div>
        <dl class="kv">
          <dt>Gültig ab</dt><dd>${esc(info.not_before)}</dd>
          <dt>Gültig bis</dt><dd>${esc(info.not_after)}</dd>
          <dt>Schlüssel</dt><dd>${esc(info.key_algo)} / ${esc(info.key_param)}</dd>
          <dt>SHA-256 Fingerprint</dt><dd>${esc(info.fingerprint_sha256)}</dd>
          <dt>Erstellt</dt><dd>${esc(fmtDate(info.created_at))}</dd>
        </dl>
        <div style="margin-top:18px; display:flex; gap:8px; flex-wrap:wrap">
          <button class="btn" data-action="download-ca" data-format="pem">Root-Zertifikat (PEM)</button>
          <button class="btn" data-action="download-ca" data-format="der">Root-Zertifikat (DER)</button>
          <button class="btn" data-action="download-backup">Backup (.zip)</button>
        </div>
      </div>

      <div class="panel">
        <div class="panel-title">Widerruf — CRL &amp; OCSP</div>
        <dl class="kv">
          <dt>CRL zuletzt erzeugt</dt><dd>${esc(info.crl ? info.crl.last_update : '—')}</dd>
          <dt>Nächste Aktualisierung</dt><dd>${esc(info.crl ? info.crl.next_update : '—')}</dd>
          <dt>Widerrufen (in CRL)</dt><dd>${info.crl ? info.crl.revoked_count : 0}</dd>
          <dt>OCSP-Responder</dt><dd>
            ${info.ocsp_reachable
              ? `<span class="status-dot ok"></span>erreichbar (Port 2560)`
              : `<span class="status-dot off"></span>nicht erreichbar (Port 2560)`}
          </dd>
        </dl>
        <div style="margin-top:14px; display:flex; gap:8px">
          <button class="btn btn-sm" data-action="download-crl" data-format="pem">CRL (PEM)</button>
          <button class="btn btn-sm" data-action="download-crl" data-format="der">CRL (DER)</button>
        </div>

        <form data-form="urls" style="margin-top:20px; padding-top:18px; border-top:1px solid var(--border)">
          <div class="field">
            <label>CRL-URL (wird in neue Zertifikate eingebettet)</label>
            <input name="crl_url" value="${esc(info.crl_url || '')}" placeholder="http://192.168.1.10:8443/api.php?action=crl_download" style="width:100%">
          </div>
          <div class="field">
            <label>OCSP-URL (wird in neue Zertifikate eingebettet)</label>
            <input name="ocsp_url" value="${esc(info.ocsp_url || '')}" placeholder="http://192.168.1.10:2560" style="width:100%">
          </div>
          <div class="hint" style="margin-bottom:12px">Gilt nur für Zertifikate, die ab jetzt genehmigt werden. Host/IP wählen, den Clients im Netz erreichen — nicht 127.0.0.1.</div>
          <button type="submit" class="btn btn-primary btn-sm">URLs speichern</button>
        </form>
      </div>

      <div class="panel">
        <div class="panel-title">NPM-Integration (Auto-Renew-Ziel)</div>
        <p class="hint" style="margin-top:-4px; margin-bottom:14px">Zugangsdaten für Nginx Proxy Manager, damit erneuerte Zertifikate automatisch dorthin gepusht werden können.</p>
        <form data-form="npm-settings">
          <div class="field"><label>NPM Basis-URL</label><input name="base_url" value="${esc(npm.base_url || '')}" placeholder="http://192.168.1.10:81" style="width:100%"></div>
          <div class="field"><label>Login (E-Mail)</label><input name="identity" value="${esc(npm.identity || '')}" placeholder="admin@example.com" style="width:100%"></div>
          <div class="field">
            <label>Passwort${npm.secret_set ? ' — gesetzt, leer lassen zum Beibehalten' : ''}</label>
            <input name="secret" type="password" placeholder="${npm.secret_set ? '••••••••' : ''}" style="width:100%">
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap">
            <button type="submit" class="btn btn-primary btn-sm">Speichern</button>
            <button type="button" class="btn btn-sm" data-action="npm-test">Verbindung testen</button>
            <button type="button" class="btn btn-sm" data-action="open-npm-import">Von NPM importieren</button>
          </div>
        </form>
      </div>

      <div class="panel">
        <div class="panel-title">Benachrichtigungen (Pushover)</div>
        <p class="hint" style="margin-top:-4px; margin-bottom:14px">Meldet Renewal-/Push-Fehler und OCSP-Ausfälle. App + User Key auf pushover.net anlegen.</p>
        <form data-form="notify-settings">
          <div class="field">
            <label style="text-transform:none; letter-spacing:normal; font-size:13px; color:var(--text)">
              <input type="checkbox" name="enabled" ${notify.enabled ? 'checked' : ''} style="width:auto; margin-right:6px; vertical-align:middle">
              Benachrichtigungen aktiv
            </label>
          </div>
          <div class="field"><label>Pushover User Key</label><input name="pushover_user" value="${esc(notify.pushover_user || '')}" placeholder="u1a2b3c4..." style="width:100%"></div>
          <div class="field">
            <label>Pushover API-Token${notify.token_set ? ' — gesetzt, leer lassen zum Beibehalten' : ''}</label>
            <input name="pushover_token" type="password" placeholder="${notify.token_set ? '••••••••' : 'a1b2c3d4...'}" style="width:100%">
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap">
            <button type="submit" class="btn btn-primary btn-sm">Speichern</button>
            <button type="button" class="btn btn-sm" data-action="notify-test">Test senden</button>
          </div>
        </form>
      </div>
    </div>
  `;
}

function renderIssue() {
  const tab = state.issueTab;
  return `
    <h1 class="view-title">Zertifikat beantragen</h1>
    <p class="view-sub">CSR auf dem Server erzeugen oder ein vorhandenes CSR einreichen.</p>
    <div class="tabbar">
      <button class="tab ${tab === 'generate' ? 'active' : ''}" data-action="issue-tab" data-tab="generate">CSR erzeugen</button>
      <button class="tab ${tab === 'upload' ? 'active' : ''}" data-action="issue-tab" data-tab="upload">CSR einreichen</button>
    </div>
    ${tab === 'generate' ? renderIssueGenerate() : renderIssueUpload()}
  `;
}

function renderIssueGenerate() {
  const form = `
    <div class="panel panel-narrow">
      <form data-form="csr-generate">
        <div class="field"><label>Common Name (CN)</label><input name="cn" required placeholder="nas.home.lan" style="width:100%"></div>
        <div class="field">
          <label>Subject Alternative Names</label>
          <textarea name="sans" rows="3" style="width:100%" placeholder="nas.home.lan, 192.168.1.50, nas"></textarea>
          <div class="hint">Kommagetrennt. Hostnamen und IPs werden automatisch erkannt.</div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Schlüsseltyp</label>
            <select name="key_algo" data-role="algo-select" style="width:100%">
              <option value="RSA">RSA</option>
              <option value="EC">EC (elliptisch)</option>
            </select>
          </div>
          <div class="field">
            <label>Schlüsselparameter</label>
            <select name="key_param" data-role="param-select" style="width:100%">
              <option value="2048">2048 Bit</option>
              <option value="3072">3072 Bit</option>
              <option value="4096">4096 Bit</option>
            </select>
          </div>
        </div>
        <div class="notice warn">Privater Schlüssel entsteht auf diesem Server. Nach Erzeugung sofort sichern — danach vom Server löschen (Button erscheint unten).</div>
        <button type="submit" class="btn btn-primary">CSR erzeugen</button>
      </form>
    </div>
  `;

  const result = state.lastGenerated ? `
    <div class="panel panel-narrow">
      <div class="panel-title">Erzeugt — Eintrag #${state.lastGenerated.id}</div>
      <div class="notice ok">CSR angelegt, wartet auf Genehmigung in der Warteschlange.</div>
      <div class="field"><label>Privater Schlüssel</label><pre class="pem-box">${esc(state.lastGenerated.private_key_pem)}</pre></div>
      <div style="display:flex; gap:8px; flex-wrap:wrap">
        <button class="btn btn-primary btn-sm" data-action="save-key">Schlüssel speichern (.pem)</button>
        <button class="btn btn-sm" data-action="save-csr">CSR speichern (.pem)</button>
        <button class="btn btn-ghost btn-sm" data-action="dismiss-generated">Schließen</button>
      </div>
    </div>
  ` : '';

  return form + result;
}

function renderIssueUpload() {
  return `
    <div class="panel">
      <form data-form="csr-upload">
        <div class="field">
          <label>CSR (PEM)</label>
          <textarea name="csr_pem" rows="10" style="width:100%; font-family:var(--font-mono)" placeholder="-----BEGIN CERTIFICATE REQUEST-----" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary">CSR einreichen</button>
      </form>
    </div>
  `;
}

function renderQueue() {
  const pending = state.certs.filter(c => c.status === 'pending');
  return `
    <h1 class="view-title">Warteschlange</h1>
    <p class="view-sub">Eingereichte CSRs, die auf Genehmigung warten.</p>
    ${pending.length > 0 ? `
      <div style="margin-bottom:14px">
        <button class="btn btn-primary btn-sm" data-action="approve-all">Alle genehmigen (${pending.length})</button>
      </div>
    ` : ''}
    <div class="panel">
      <table id="queue-table" class="display ledger" style="width:100%">
        <thead><tr><th>CN</th><th>Quelle</th><th>Schlüssel</th><th>Eingereicht</th><th></th></tr></thead>
        <tbody>
          ${pending.map(rowToPendingTr).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function rowToPendingTr(c) {
  return `
    <tr>
      <td>
        <div class="cn">${esc(c.common_name)}</div>
        ${c.sans ? `<div class="sans">${esc(c.sans)}</div>` : ''}
      </td>
      <td>${c.source === 'generated' ? 'Erzeugt' : 'Hochgeladen'}</td>
      <td>${esc(c.key_algo || '—')} ${esc(c.key_param || '')}</td>
      <td data-order="${esc(c.created_at || '')}">${esc(fmtDate(c.created_at))}</td>
      <td class="actions">
        <div class="actions-inner">
          <button class="btn btn-sm btn-primary" data-action="open-approve" data-id="${c.id}">Genehmigen</button>
          <button class="btn btn-sm btn-danger" data-action="open-reject" data-id="${c.id}">Ablehnen</button>
        </div>
      </td>
    </tr>
  `;
}

function renderCerts() {
  const filter = state.certsFilter;
  const rows = state.certs.filter(c => c.status === filter);
  const tabs = ['issued', 'revoked', 'rejected'];
  return `
    <h1 class="view-title">Zertifikate</h1>
    <p class="view-sub">Ausgestellte, widerrufene und abgelehnte Zertifikate.</p>
    <div style="margin-bottom:14px">
      <button class="btn btn-sm" data-action="export-all">Alle exportieren (.zip)</button>
    </div>
    <div class="tabbar">
      ${tabs.map(t => `<button class="tab ${filter === t ? 'active' : ''}" data-action="certs-tab" data-tab="${t}">${labelForStatus(t)}</button>`).join('')}
    </div>
    <div class="panel">
      <table id="certs-table" class="display ledger" style="width:100%">
        <thead><tr><th>CN</th><th>Seriennummer</th><th>Gültig bis</th><th>Status</th><th></th></tr></thead>
        <tbody>
          ${rows.map(rowToCertTr).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function rowToCertTr(c) {
  const canExport = c.status === 'issued';
  return `
    <tr>
      <td><div class="cn">${esc(c.common_name)}</div>${c.sans ? `<div class="sans">${esc(c.sans)}</div>` : ''}</td>
      <td style="font-family:var(--font-mono); font-size:12px">${esc(c.serial || '—')}</td>
      <td data-order="${esc(c.expires_at || '')}">${esc(fmtDate(c.expires_at))}</td>
      <td>
        <div class="status-cell">
          <span class="stamp ${c.status}">${labelForStatus(c.status)}</span>
          ${c.auto_renew ? `<span class="stamp" style="color:var(--issued); border-color:var(--issued)">AUTO</span>` : ''}
        </div>
      </td>
      <td class="actions">
        <div class="actions-inner">
          ${canExport ? `<button class="btn btn-sm" data-action="open-export" data-id="${c.id}">Export</button>` : ''}
          ${canExport ? `<button class="btn btn-sm" data-action="open-autorenew" data-id="${c.id}">Auto-Renew</button>` : ''}
          ${canExport ? `<button class="btn btn-sm btn-danger" data-action="revoke" data-id="${c.id}">Widerrufen</button>` : ''}
        </div>
      </td>
    </tr>
  `;
}

function renderModal() {
  const m = state.modal;
  if (!m) return '';

  if (m.type === 'approve') {
    return `
      <div class="modal-backdrop" data-action="modal-backdrop">
        <div class="modal">
          <h3>CSR #${m.row.id} genehmigen</h3>
          <dl class="kv" style="margin-bottom:16px">
            <dt>CN</dt><dd>${esc(m.row.common_name)}</dd>
            <dt>SANs</dt><dd>${esc(m.row.sans || '—')}</dd>
          </dl>
          <form data-form="approve" data-id="${m.row.id}">
            <div class="field"><label>Gültigkeit (Tage)</label><input name="validity_days" type="number" value="397"></div>
            <div class="field">
              <label>SANs überschreiben (optional)</label>
              <textarea name="sans" rows="2" style="width:100%" placeholder="${esc(m.row.sans || '')}"></textarea>
            </div>
            <div class="modal-actions">
              <button type="button" class="btn btn-ghost" data-action="close-modal">Abbrechen</button>
              <button type="submit" class="btn btn-primary">Genehmigen &amp; signieren</button>
            </div>
          </form>
        </div>
      </div>
    `;
  }

  if (m.type === 'reject') {
    return `
      <div class="modal-backdrop" data-action="modal-backdrop">
        <div class="modal">
          <h3>CSR #${m.row.id} ablehnen</h3>
          <form data-form="reject" data-id="${m.row.id}">
            <div class="field"><label>Grund (optional)</label><textarea name="reason" rows="3" style="width:100%"></textarea></div>
            <div class="modal-actions">
              <button type="button" class="btn btn-ghost" data-action="close-modal">Abbrechen</button>
              <button type="submit" class="btn btn-danger">Ablehnen</button>
            </div>
          </form>
        </div>
      </div>
    `;
  }

  if (m.type === 'export') {
    const hasKey = !!m.row.has_private_key;
    return `
      <div class="modal-backdrop" data-action="modal-backdrop">
        <div class="modal">
          <h3>Zertifikat #${m.row.id} exportieren</h3>
          <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:18px">
            <button class="btn" data-action="do-export" data-id="${m.row.id}" data-format="pem">PEM (nur Zertifikat)</button>
            <button class="btn" data-action="do-export" data-id="${m.row.id}" data-format="chain">PEM-Chain (Zertifikat + Root)</button>
            <button class="btn" data-action="do-export" data-id="${m.row.id}" data-format="der">DER</button>
            ${hasKey ? `<button class="btn" data-action="do-export" data-id="${m.row.id}" data-format="key">Privater Schlüssel (PEM)</button>` : ''}
          </div>
          ${hasKey ? `
            <div class="field">
              <label>PFX / PKCS12 Passwort</label>
              <input type="password" data-role="pfx-password" placeholder="Passwort für .pfx" style="width:100%">
            </div>
            <button class="btn btn-primary" data-action="do-export-pfx" data-id="${m.row.id}">Als .pfx exportieren</button>
          ` : `<div class="notice warn">Kein privater Schlüssel mehr auf dem Server — .pfx nicht möglich.</div>`}
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" data-action="close-modal">Schließen</button>
          </div>
        </div>
      </div>
    `;
  }

  if (m.type === 'autorenew') {
    const npmCerts = (m.npmCerts || []).filter(c => c.provider === 'other');
    const options = npmCerts.map(c => `
      <option value="${c.id}" ${String(c.id) === String(m.row.npm_cert_id) ? 'selected' : ''}>${esc(c.nice_name)} (#${c.id})</option>
    `).join('');
    return `
      <div class="modal-backdrop" data-action="modal-backdrop">
        <div class="modal">
          <h3>Auto-Renew — ${esc(m.row.common_name)}</h3>
          ${m.npmError ? `<div class="notice error">NPM nicht erreichbar: ${esc(m.npmError)}. Zugangsdaten im Dashboard-Panel "NPM-Integration" prüfen.</div>` : ''}
          <form data-form="autorenew" data-id="${m.row.id}">
            <div class="field">
              <label style="text-transform:none; letter-spacing:normal; font-size:13px; color:var(--text)">
                <input type="checkbox" name="auto_renew" ${m.row.auto_renew ? 'checked' : ''} style="width:auto; margin-right:6px; vertical-align:middle">
                Automatisch erneuern
              </label>
            </div>
            <div class="field"><label>Erneuern (Tage vor Ablauf)</label><input name="renew_before_days" type="number" value="${m.row.renew_before_days || 30}"></div>
            <div class="field">
              <label>NPM-Zertifikat (Upload-Ziel)</label>
              <select name="npm_cert_id" style="width:100%">
                <option value="">— keins verknüpft —</option>
                ${options}
              </select>
              <div class="hint">Nur "Custom"-Zertifikate aus NPM wählbar, nicht Let's-Encrypt-Slots.</div>
            </div>
            <div class="modal-actions">
              <button type="button" class="btn btn-ghost" data-action="close-modal">Abbrechen</button>
              <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
          </form>
          <div style="margin-top:16px; padding-top:16px; border-top:1px solid var(--border); display:flex; gap:8px; flex-wrap:wrap">
            <button class="btn btn-sm" data-action="renew-now" data-id="${m.row.id}">Jetzt erneuern &amp; pushen</button>
            <button class="btn btn-sm" data-action="push-now" data-id="${m.row.id}">Nur zu NPM pushen (ohne erneut zu signieren)</button>
          </div>
          ${renderRenewalHistory(m.history)}
        </div>
      </div>
    `;
  }

  if (m.type === 'npm-import') {
    const items = (m.npmCerts || []).filter(c => c.provider === 'other');
    const rows = items.map(c => `
      <div style="border:1px solid var(--border); border-radius:var(--radius); padding:10px; margin-bottom:8px">
        <label style="display:flex; align-items:center; gap:8px; text-transform:none; letter-spacing:normal; font-size:13px; color:var(--text); margin-bottom:8px">
          <input type="checkbox" data-import-select="${c.id}" checked style="width:auto">
          <strong>${esc(c.nice_name)}</strong> <span class="hint">(NPM #${c.id})</span>
        </label>
        <div class="field" style="margin-bottom:6px"><label>CN</label><input data-import-cn="${c.id}" value="${esc((c.domain_names || [])[0] || '')}" style="width:100%"></div>
        <div class="field" style="margin-bottom:0"><label>SANs</label><input data-import-sans="${c.id}" value="${esc((c.domain_names || []).join(', '))}" style="width:100%"></div>
      </div>
    `).join('');
    return `
      <div class="modal-backdrop" data-action="modal-backdrop">
        <div class="modal" style="width:520px">
          <h3>Von NPM importieren</h3>
          <p class="hint" style="margin-top:-6px; margin-bottom:14px">
            Legt pro ausgewähltem NPM-Zertifikat ein neues CSR an (CN/SANs aus NPM übernommen), verknüpft es direkt mit der jeweiligen NPM-Zertifikat-ID und aktiviert Auto-Renew.
            Danach in der Warteschlange genehmigen, dann "Nur zu NPM pushen" — dieselbe NPM-ID wird wiederbefüllt, keine Proxy-Host-Änderung in NPM nötig.
          </p>
          ${rows || '<div class="hint">Keine Custom-Zertifikate in NPM gefunden.</div>'}
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" data-action="close-modal">Abbrechen</button>
            <button type="button" class="btn btn-primary" data-action="npm-import-submit">CSRs erzeugen</button>
          </div>
        </div>
      </div>
    `;
  }

  return '';
}

function renewPushToastMessage(pushed, liveCheck) {
  if (!pushed) return 'Erneuert (kein NPM verknüpft).';
  if (!liveCheck) return 'Gepusht (Live-Check nicht verfügbar).';
  return liveCheck.ok ? 'Gepusht — Live-Check bestätigt neue Serial.' : `Gepusht, aber Live-Check zeigt Abweichung: ${liveCheck.note}`;
}

function renderRenewalHistory(history) {
  if (!history) {
    return `<div class="hint" style="margin-top:14px">Verlauf wird geladen…</div>`;
  }
  if (history.length === 0) {
    return `<div class="hint" style="margin-top:14px">Noch keine Erneuerung protokolliert.</div>`;
  }
  const rows = history.map(h => {
    let pushInfo = '—';
    if (h.pushed_to_npm == 1) {
      pushInfo = h.live_check_ok == 1
        ? '<span style="color:var(--issued)">gepusht, live bestätigt</span>'
        : `<span style="color:var(--rejected)">gepusht, live-Check fehlgeschlagen</span>`;
    }
    return `
      <tr>
        <td style="font-family:var(--font-mono); font-size:11px">${esc(fmtDate(h.renewed_at))}</td>
        <td style="font-family:var(--font-mono); font-size:11px">${esc((h.old_serial || '—').slice(-10))} → ${esc((h.new_serial || '—').slice(-10))}</td>
        <td style="font-size:11px">${pushInfo}</td>
      </tr>
    `;
  }).join('');
  return `
    <div style="margin-top:16px; padding-top:16px; border-top:1px solid var(--border)">
      <div class="panel-title" style="margin-bottom:10px">Verlauf</div>
      <table class="ledger" style="width:100%">
        <thead><tr><th>Wann</th><th>Serial (Ausschnitt)</th><th>NPM-Push</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  `;
}

function closeModal() {
  state.modal = null;
  render();
}

// ---------- Event handling ----------

appEl.addEventListener('click', onAppClick);
appEl.addEventListener('submit', onAppSubmit);
appEl.addEventListener('change', onAppChange);

function onAppChange(e) {
  const t = e.target;
  if (t.matches('select[data-role="algo-select"]')) {
    const form = t.closest('form');
    const paramSelect = form.querySelector('select[data-role="param-select"]');
    paramSelect.innerHTML = t.value === 'EC'
      ? `<option value="prime256v1">P-256</option><option value="secp384r1">P-384</option>`
      : `<option value="2048">2048 Bit</option><option value="3072">3072 Bit</option><option value="4096">4096 Bit</option>`;
  }
}

async function onAppClick(e) {
  const backdrop = e.target.closest('[data-action="modal-backdrop"]');
  if (backdrop && e.target === backdrop) { closeModal(); return; }

  const btn = e.target.closest('[data-action]');
  if (!btn) return;
  const action = btn.dataset.action;

  switch (action) {
    case 'nav':
      state.view = btn.dataset.view;
      render();
      break;

    case 'logout':
      await api('logout', { method: 'POST' }).catch(() => {});
      state = { loggedIn: false, caExists: false, view: 'dashboard', issueTab: 'generate', certsFilter: 'issued', caInfo: null, certs: [], lastGenerated: null, modal: null, loginError: '' };
      render();
      break;

    case 'download-ca':
      await safeDownload(() => downloadFile('ca_download', { format: btn.dataset.format }));
      break;

    case 'download-crl':
      await safeDownload(() => downloadFile('crl_download', { format: btn.dataset.format }));
      break;

    case 'download-backup':
      await safeDownload(() => downloadFile('ca_backup'));
      break;

    case 'export-all':
      await safeDownload(() => downloadFile('certs_export_all'));
      break;

    case 'issue-tab':
      state.issueTab = btn.dataset.tab;
      render();
      break;

    case 'certs-tab':
      state.certsFilter = btn.dataset.tab;
      render();
      break;

    case 'dismiss-generated':
      state.lastGenerated = null;
      render();
      break;

    case 'save-key':
      downloadBlob(state.lastGenerated.private_key_pem, `csr-${state.lastGenerated.id}.key.pem`, 'application/x-pem-file');
      break;

    case 'save-csr':
      downloadBlob(state.lastGenerated.csr_pem, `csr-${state.lastGenerated.id}.csr.pem`, 'application/x-pem-file');
      break;

    case 'open-approve': {
      const row = state.certs.find(c => String(c.id) === btn.dataset.id);
      state.modal = { type: 'approve', row };
      render();
      break;
    }

    case 'open-reject': {
      const row = state.certs.find(c => String(c.id) === btn.dataset.id);
      state.modal = { type: 'reject', row };
      render();
      break;
    }

    case 'open-export':
      try {
        const row = await api('csr_get', { params: { id: btn.dataset.id } });
        state.modal = { type: 'export', row };
        render();
      } catch (err) { toast(err.message, 'error'); }
      break;

    case 'open-autorenew': {
      const row = state.certs.find(c => String(c.id) === btn.dataset.id);
      let npmCerts = [];
      let npmError = null;
      try {
        npmCerts = await api('npm_list_certificates');
      } catch (err) {
        npmError = err.message;
      }
      state.modal = { type: 'autorenew', row, npmCerts, npmError, history: null };
      render();
      try {
        const history = await api('cert_renewal_history', { params: { id: btn.dataset.id } });
        if (state.modal && state.modal.type === 'autorenew') {
          state.modal.history = history;
          render();
        }
      } catch (err) { /* Verlauf ist optional, Modal bleibt trotzdem nutzbar */ }
      break;
    }

    case 'renew-now': {
      setButtonBusy(btn, 'Wird geprüft…');
      try {
        const result = await api('cert_renew_now', { method: 'POST', body: { id: Number(btn.dataset.id) } });
        toast(renewPushToastMessage(result.pushed_to_npm, result.live_check), result.live_check && result.live_check.ok === false ? 'error' : 'ok');
        closeModal();
        await refreshAll();
        render();
      } catch (err) {
        toast(err.message, 'error');
        restoreButton(btn);
      }
      break;
    }

    case 'push-now': {
      setButtonBusy(btn, 'Wird geprüft…');
      try {
        const result = await api('cert_push_npm', { method: 'POST', body: { id: Number(btn.dataset.id) } });
        toast(renewPushToastMessage(true, result.live_check), result.live_check && result.live_check.ok === false ? 'error' : 'ok');
        closeModal();
        await refreshAll();
        render();
      } catch (err) {
        toast(err.message, 'error');
        restoreButton(btn);
      }
      break;
    }

    case 'npm-test':
      try {
        const result = await api('npm_test');
        toast(`NPM erreichbar (${result.certificate_count} Zertifikate gefunden).`, 'ok');
      } catch (err) { toast(err.message, 'error'); }
      break;

    case 'approve-all': {
      const pendingCount = state.certs.filter(c => c.status === 'pending').length;
      if (!confirm(`${pendingCount} CSR(s) mit Standard-Gültigkeit (397 Tage) genehmigen?`)) return;
      try {
        const result = await api('csr_approve_all', { method: 'POST', body: {} });
        const okCount = result.results.filter(r => r.ok).length;
        const failCount = result.results.length - okCount;
        toast(`${okCount} genehmigt${failCount ? `, ${failCount} Fehler` : ''}.`, failCount ? 'error' : 'ok');
        await refreshAll();
        render();
      } catch (err) { toast(err.message, 'error'); }
      break;
    }

    case 'notify-test':
      try {
        await api('notify_test');
        toast('Testnachricht gesendet — Pushover prüfen.', 'ok');
      } catch (err) { toast(err.message, 'error'); }
      break;

    case 'open-npm-import': {
      let npmCerts = [];
      try {
        npmCerts = await api('npm_list_certificates');
      } catch (err) {
        toast(err.message, 'error');
        break;
      }
      state.modal = { type: 'npm-import', npmCerts };
      render();
      break;
    }

    case 'npm-import-submit': {
      const modal = document.querySelector('.modal');
      const items = [];
      (state.modal.npmCerts || []).filter(c => c.provider === 'other').forEach(c => {
        const checkbox = modal.querySelector(`[data-import-select="${c.id}"]`);
        if (!checkbox || !checkbox.checked) return;
        const cnInput = modal.querySelector(`[data-import-cn="${c.id}"]`);
        const sansInput = modal.querySelector(`[data-import-sans="${c.id}"]`);
        items.push({
          npm_cert_id: c.id,
          cn: cnInput ? cnInput.value : '',
          sans: sansInput ? sansInput.value : '',
        });
      });
      if (items.length === 0) { toast('Nichts ausgewählt.', 'error'); break; }
      try {
        const result = await api('npm_import_generate', { method: 'POST', body: { items } });
        const okCount = result.results.filter(r => r.ok).length;
        const failCount = result.results.length - okCount;
        toast(`${okCount} CSR(s) erzeugt${failCount ? `, ${failCount} Fehler` : ''}.`, failCount ? 'error' : 'ok');
        closeModal();
        await refreshAll();
        state.view = 'queue';
        render();
      } catch (err) { toast(err.message, 'error'); }
      break;
    }

    case 'close-modal':
      closeModal();
      break;

    case 'revoke':
      if (!confirm('Zertifikat wirklich widerrufen?')) return;
      try {
        await api('cert_revoke', { method: 'POST', body: { id: Number(btn.dataset.id) } });
        toast('Zertifikat widerrufen.', 'ok');
        await refreshAll();
        render();
      } catch (err) { toast(err.message, 'error'); }
      break;

    case 'do-export':
      await safeDownload(() => downloadFile('cert_export', { id: btn.dataset.id, format: btn.dataset.format }));
      break;

    case 'do-export-pfx': {
      const input = document.querySelector('.modal [data-role="pfx-password"]');
      const password = input ? input.value : '';
      if (!password) { toast('Passwort erforderlich.', 'error'); return; }
      await safeDownload(() => downloadFile('cert_export', { id: btn.dataset.id, format: 'pfx', password }));
      break;
    }
  }
}

async function onAppSubmit(e) {
  e.preventDefault();
  const form = e.target;
  const formType = form.dataset.form;
  if (!formType) return;
  const data = Object.fromEntries(new FormData(form).entries());

  try {
    if (formType === 'login') {
      await api('login', { method: 'POST', body: { password: data.password } });
      const s = await api('status');
      state.loggedIn = s.loggedIn;
      state.caExists = s.caExists;
      state.loginError = '';
      await refreshAll();
      render();
      return;
    }

    if (formType === 'ca-create') {
      await api('ca_create', {
        method: 'POST',
        body: {
          cn: data.cn, o: data.o, c: data.c,
          validity_days: Number(data.validity_days || 3650),
          key_algo: data.key_algo, key_param: data.key_param,
        },
      });
      state.caExists = true;
      state.view = 'dashboard';
      await refreshAll();
      toast('CA erzeugt.', 'ok');
      render();
      return;
    }

    if (formType === 'csr-generate') {
      const result = await api('csr_generate', {
        method: 'POST',
        body: { cn: data.cn, sans: data.sans, key_algo: data.key_algo, key_param: data.key_param },
      });
      state.lastGenerated = result;
      await refreshAll();
      toast('CSR erzeugt, wartet auf Genehmigung.', 'ok');
      render();
      return;
    }

    if (formType === 'csr-upload') {
      await api('csr_upload', { method: 'POST', body: { csr_pem: data.csr_pem } });
      await refreshAll();
      state.view = 'queue';
      toast('CSR eingereicht.', 'ok');
      render();
      return;
    }

    if (formType === 'approve') {
      await api('csr_approve', {
        method: 'POST',
        body: { id: Number(form.dataset.id), validity_days: Number(data.validity_days || 397), sans: data.sans || undefined },
      });
      closeModal();
      await refreshAll();
      toast('Zertifikat ausgestellt.', 'ok');
      render();
      return;
    }

    if (formType === 'reject') {
      await api('csr_reject', { method: 'POST', body: { id: Number(form.dataset.id), reason: data.reason || '' } });
      closeModal();
      await refreshAll();
      toast('CSR abgelehnt.', 'ok');
      render();
      return;
    }

    if (formType === 'urls') {
      await api('ca_update_urls', { method: 'POST', body: { crl_url: data.crl_url || '', ocsp_url: data.ocsp_url || '' } });
      await refreshAll();
      toast('URLs gespeichert.', 'ok');
      render();
      return;
    }

    if (formType === 'npm-settings') {
      await api('npm_settings_save', {
        method: 'POST',
        body: { base_url: data.base_url || '', identity: data.identity || '', secret: data.secret || '' },
      });
      await refreshAll();
      toast('NPM-Einstellungen gespeichert.', 'ok');
      render();
      return;
    }

    if (formType === 'notify-settings') {
      await api('notify_settings_save', {
        method: 'POST',
        body: {
          pushover_user: data.pushover_user || '',
          pushover_token: data.pushover_token || '',
          enabled: !!data.enabled,
        },
      });
      await refreshAll();
      toast('Benachrichtigungs-Einstellungen gespeichert.', 'ok');
      render();
      return;
    }

    if (formType === 'autorenew') {
      await api('cert_set_renew', {
        method: 'POST',
        body: {
          id: Number(form.dataset.id),
          auto_renew: !!data.auto_renew,
          renew_before_days: Number(data.renew_before_days || 30),
          npm_cert_id: data.npm_cert_id || '',
        },
      });
      closeModal();
      await refreshAll();
      toast('Auto-Renew gespeichert.', 'ok');
      render();
      return;
    }
  } catch (err) {
    if (formType === 'login') {
      state.loginError = err.message;
      render();
    } else {
      toast(err.message, 'error');
    }
  }
}

// ---------- Init ----------

async function init() {
  try {
    const s = await api('status');
    state.loggedIn = s.loggedIn;
    state.caExists = s.caExists;
    if (state.loggedIn) await refreshAll();
  } catch (e) { /* server not reachable yet */ }
  render();
}

init();
