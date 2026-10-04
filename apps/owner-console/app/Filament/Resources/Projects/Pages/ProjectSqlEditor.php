<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Services\ControlPlane\CpAccess;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 20F real SQL Editor (CodeMirror, vendored in-repo).
 * Read-only by default; write mode needs permission + password re-auth and
 * expires. Destructive statements need the project slug typed. All execution
 * flows through ProjectSqlController (classified, bounded, audited).
 */
class ProjectSqlEditor extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    /**
     * 0.6.0 Phase A (§A3): an intentional wide surface — the shell stays
     * ~1440px everywhere else, but this visualization needs the room.
     */
    public function getMaxContentWidth(): string|\Filament\Support\Enums\Width|null
    {
        return \Filament\Support\Enums\Width::Full;
    }

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return __('labels.sql_editor');
    }

    public function getBreadcrumbs(): array
    {
        return [static::projectUrl($this->project(), 'database') => 'Database', 'SQL Editor'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'sql.execute_read');
    }

    public function content(Schema $schema): Schema
    {
        $p = $this->project();
        $canWrite = CpAccess::allows(auth()->user(), 'sql.execute_write');

        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--sql'])
            ->components([$this->subnavSection('sql'), Html::make($this->editorHtml($p, $canWrite))]);
    }

    protected function editorHtml(Project $p, bool $canWrite): string
    {
        $html = <<<'HTML'
<link rel="stylesheet" href="/vendor/codemirror/codemirror.min.css">
<div class="cp-reference__sql" data-cp-sql="1"
  data-run="__RUN__" data-history="__HISTORY__" data-saved="__SAVED__"
  data-save="__SAVE__" data-unsave="__UNSAVE__" data-writemode="__WRITEMODE__"
  data-writestatus="__WRITESTATUS__" data-slug="__SLUG__" data-csrf="__CSRF__"
  data-canwrite="__CANWRITE__">
  <div class="cp-sqlwork__status">
    <span class="cp-badge is-warning" data-writestate>READ ONLY</span>
    <span class="cp-sqlwork__hint" title="Write mode requires the sql.execute_write permission plus password confirmation, and expires after 10 minutes. Destructive statements additionally require typing the project slug. Every execution is audit logged.">ⓘ Write mode requires elevated permission</span>
    <button class="cp-btn cp-btn--sm" data-act="writemode" style="__WMSTYLE__">Write mode</button>
  </div>
  <div class="cp-sqlwork__bar" role="group" aria-label="Query actions">
    <button type="button" class="cp-btn is-primary" data-act="run">Run ▶ <kbd>Ctrl+Enter</kbd></button>
    <button type="button" class="cp-btn" data-act="newtab">+ New query</button>
    <div class="cp-tabs cp-sqlwork__tabs" data-tabs aria-label="Open queries"></div>
    <button class="cp-btn cp-btn--sm" data-act="toggle-saved">Saved</button>
    <button class="cp-btn cp-btn--sm" data-act="toggle-history">History</button>
    <button class="cp-btn cp-btn--sm" data-act="explain">Explain</button>
    <button class="cp-btn cp-btn--sm" data-act="format">Format</button>
    <button class="cp-btn cp-btn--sm" data-act="save">Save</button>
  </div>
  <div class="cp-sql__editor cp-sqlwork__editor"><textarea data-editor aria-label="SQL query">-- __SLUG__ · read-only mode
select * from users limit 100;</textarea></div>
  <section class="cp-sqlwork__results" aria-label="Query results">
    <h3 class="cp-sqlwork__results-h">Results</h3>
    <div class="cp-sql__meta" data-meta role="status" aria-live="polite">Ready when you are. Run a query to inspect its results and duration.</div>
    <div data-result><div class="cp-empty cp-sqlwork__empty"><h4 class="cp-empty__title">No results yet</h4><p class="cp-empty__hint">Run a query above, or load a saved query. Explain shows the query plan without executing the underlying statement.</p></div></div>
  </section>
  <div class="cp-sql__panels" style="display:none" data-panel-saved>
    <div><h4 class="cp-reference__section-title">Saved queries</h4><div class="cp-list" data-savedlist></div></div>
    <div><h4 class="cp-reference__section-title">About</h4><p style="font-size:.75rem;color:var(--cp-text-dim)">Saved queries are per-project. History stores redacted SQL only (literals replaced).</p></div>
  </div>
  <div class="cp-sql__panels" style="display:none" data-panel-history>
    <div><h4 class="cp-reference__section-title">Query history</h4><div class="cp-list" data-historylist></div></div>
    <div><h4 class="cp-reference__section-title">About</h4><p style="font-size:.75rem;color:var(--cp-text-dim)">Latest 50 executions with status, duration and redacted text. Full audit trail lives in Audit Log.</p></div>
  </div>
  <div data-modal></div>
</div>
<script src="/vendor/codemirror/codemirror.min.js"></script>
<script src="/vendor/codemirror/sql.min.js"></script>
<script>
(function(){
  var root = document.querySelector('[data-cp-sql]');
  if (!root || root.dataset.init) return; root.dataset.init = '1';
  var csrf = root.dataset.csrf, slug = root.dataset.slug;
  var canWrite = root.dataset.canwrite === '1';
  function api(url, opts){
    opts = opts || {};
    opts.headers = Object.assign({'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, opts.headers || {});
    return fetch(url, opts).then(function(r){ return r.json().then(function(j){ return {status: r.status, body: j}; }); });
  }
  var cm = CodeMirror.fromTextArea(root.querySelector('[data-editor]'), {
    mode: 'text/x-pgsql', lineNumbers: true, indentWithTabs: false, tabSize: 2
  });
  cm.addKeyMap({'Ctrl-Enter': function(){ run(false); }});
  // Tabs (localStorage per project).
  var store = 'cpsql.' + slug, tabs = [{name: 'Query 1', sql: cm.getValue()}], active = 0;
  try { var saved = JSON.parse(localStorage.getItem(store) || 'null'); if (saved && saved.tabs) { tabs = saved.tabs; active = saved.active || 0; } } catch(e){}
  function persist(){ try { localStorage.setItem(store, JSON.stringify({tabs: tabs, active: active})); } catch(e){} }
  function renderTabs(){
    var box = root.querySelector('[data-tabs]'); box.innerHTML = '';
    tabs.forEach(function(t, i){
      var b = document.createElement('button');
      b.textContent = t.name + (i === active ? '' : ''); b.setAttribute('aria-selected', i === active ? 'true' : 'false');
      b.onclick = function(){ tabs[active].sql = cm.getValue(); active = i; cm.setValue(tabs[active].sql); renderTabs(); persist(); };
      box.appendChild(b);
    });
  }
  cm.on('change', function(){ tabs[active].sql = cm.getValue(); persist(); });
  cm.setValue(tabs[active].sql); renderTabs();
  root.querySelector('[data-act="newtab"]').onclick = function(){
    tabs[active].sql = cm.getValue(); tabs.push({name: 'Query ' + (tabs.length + 1), sql: 'select 1;'});
    active = tabs.length - 1; cm.setValue(tabs[active].sql); renderTabs(); persist();
  };
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function modal(html){
    var m = root.querySelector('[data-modal]');
    m.innerHTML = '<div class="cp-modal__bg"><div class="cp-modal">' + html + '</div></div>';
    m.querySelector('.cp-modal__bg').addEventListener('click', function(e){ if (e.target.className === 'cp-modal__bg') m.innerHTML = ''; });
  }
  function closeModal(){ root.querySelector('[data-modal]').innerHTML = ''; }
  function meta(html){ root.querySelector('[data-meta]').innerHTML = html; }
  function showRows(body){
    var box = root.querySelector('[data-result]');
    if (body.status === 'confirm_required') {
      box.innerHTML = '<div class="cp-error"><strong>Destructive statement.</strong> ' + esc(body.error) +
        '<div style="margin-top:.5rem;display:flex;gap:.5rem"><input class="cp-field" style="margin:0" data-sluginput placeholder="Type project slug: ' + esc(slug) + '">' +
        '<button class="cp-btn is-danger" data-confirmdestructive>Confirm &amp; run</button></div></div>';
      box.querySelector('[data-confirmdestructive]').onclick = function(){
        run(true, box.querySelector('[data-sluginput]').value);
      };
      return;
    }
    if (body.status === 'blocked' || body.status === 'error' || body.status === 'timeout') {
      box.innerHTML = '<div class="cp-error"><strong>' + esc(body.status.toUpperCase()) + (body.category ? ' · ' + esc(body.category) : '') + '</strong><br>' + esc(body.error || body.blocked || 'Rejected.') + '</div>';
      meta('Rejected in ' + (body.duration_ms || 0) + ' ms.');
      return;
    }
    var rows = body.rows || [];
    meta('OK in ' + body.duration_ms + ' ms · ' +
      (body.category === 'write' ? esc(String(body.affected)) + ' row(s) affected' : rows.length + ' row(s)' + (body.truncated ? ' · truncated at 500' : '')));
    window.__cpLastRows = rows;
    if (!rows.length) { box.innerHTML = '<div class="cp-empty"><div class="cp-empty__title">Done — no rows</div></div>'; return; }
    var cols = body.columns || Object.keys(rows[0]);
    var h = '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>' +
      cols.map(function(c){ return '<th>' + esc(c) + '</th>'; }).join('') + '</tr></thead><tbody>' +
      rows.slice(0, 200).map(function(r){
        return '<tr>' + cols.map(function(c){
          var v = r[c]; var s = (v !== null && typeof v === 'object') ? JSON.stringify(v) : String(v == null ? '' : v);
          return '<td title="' + esc(s).slice(0, 500) + '">' + esc(s).slice(0, 200) + '</td>';
        }).join('') + '</tr>';
      }).join('') + '</tbody></table></div>' +
      '<div style="margin-top:.5rem;display:flex;gap:.5rem"><button class="cp-btn" data-jsonview>JSON view</button>' +
      '<button class="cp-btn" data-copy>Copy</button><button class="cp-btn" data-dl>Download CSV</button></div><div data-altview style="margin-top:.5rem"></div>';
    box.innerHTML = h;
    box.querySelector('[data-jsonview]').onclick = function(){
      box.querySelector('[data-altview]').innerHTML = '<div class="cp-code">' + esc(JSON.stringify(window.__cpLastRows.slice(0, 200), null, 2)).slice(0, 20000) + '</div>';
    };
    box.querySelector('[data-copy]').onclick = function(){
      navigator.clipboard.writeText(JSON.stringify(window.__cpLastRows)).then(function(){ meta('Copied to clipboard.'); });
    };
    box.querySelector('[data-dl]').onclick = function(){
      var rows2 = window.__cpLastRows; if (!rows2.length) return;
      var cols2 = Object.keys(rows2[0]);
      var csv = cols2.join(',') + '\n' + rows2.map(function(r){
        return cols2.map(function(c){ var v = r[c]; v = (v !== null && typeof v === 'object') ? JSON.stringify(v) : String(v == null ? '' : v); return '"' + v.replace(/"/g, '""') + '"'; }).join(',');
      }).join('\n');
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([csv], {type: 'text/csv'}));
      a.download = slug + '-query.csv'; a.click();
    };
  }
  var pendingWrite = false;
  function run(write, confirmSlug){
    var sql = cm.getValue();
    tabs[active].sql = sql; persist();
    var btn = root.querySelector('[data-act="run"]'); btn.disabled = true;
    meta('Running…');
    api(root.dataset.run, {method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({sql: sql, write: !!write, confirm_slug: confirmSlug || null})})
      .then(function(res){ showRows(res.body); loadHistory(); })
      .catch(function(e){ meta('Request failed.'); })
      .finally(function(){ btn.disabled = false; });
  }
  root.querySelector('[data-act="run"]').onclick = function(){ run(pendingWrite); };
  root.querySelector('[data-act="explain"]').onclick = function(){
    var sql = cm.getValue().replace(/^\s*explain\s+/i, '');
    cm.setValue('EXPLAIN ' + sql); run(false);
  };
  root.querySelector('[data-act="format"]').onclick = function(){
    var sql = cm.getValue();
    var kws = ['SELECT','FROM','WHERE','AND','OR','JOIN','LEFT JOIN','RIGHT JOIN','INNER JOIN','GROUP BY','ORDER BY','HAVING','LIMIT','OFFSET','INSERT INTO','VALUES','UPDATE','SET','DELETE FROM','WITH','UNION','EXPLAIN','RETURNING'];
    kws.forEach(function(k){ var re = new RegExp('\\b' + k.replace(/ /g, '\\s+') + '\\b', 'gi'); sql = sql.replace(re, '\n' + k); });
    cm.setValue(sql.trim() + '\n');
  };
  root.querySelector('[data-act="save"]').onclick = function(){
    var name = prompt('Save query as:'); if (!name) return;
    api(root.dataset.save, {method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({name: name, sql: cm.getValue()})}).then(function(){ loadSaved(); });
  };
  function togglePanel(sel){
    var p = root.querySelector(sel); var show = p.style.display === 'none';
    p.style.display = show ? '' : 'none'; if (show && sel === '[data-panel-history]') loadHistory(); if (show && sel === '[data-panel-saved]') loadSaved();
  }
  root.querySelector('[data-act="toggle-history"]').onclick = function(){ togglePanel('[data-panel-history]'); };
  root.querySelector('[data-act="toggle-saved"]').onclick = function(){ togglePanel('[data-panel-saved]'); };
  function loadHistory(){
    api(root.dataset.history).then(function(res){
      var box = root.querySelector('[data-historylist]'); box.innerHTML = '';
      (res.body || []).forEach(function(h){
        var d = document.createElement('div'); d.className = 'cp-list__item';
        d.innerHTML = '<span class="cp-badge ' + (h.status === 'ok' ? 'is-success' : (h.status === 'blocked' ? 'is-warning' : 'is-danger')) + '">' +
          esc(h.status) + '</span> <span style="opacity:.7">' + esc(h.category) + ' · ' + h.duration_ms + 'ms · ' + esc(h.created_at || '') + '</span>' +
          ' <button class="cp-btn" style="padding:.15rem .5rem;font-size:.7rem" data-rerun>Rerun</button><code>' + esc(h.redacted_sql || '') + '</code>';
        d.querySelector('[data-rerun]').onclick = function(){ cm.setValue(h.redacted_sql || ''); meta('Loaded redacted history (literals were ? — re-enter values).'); };
        box.appendChild(d);
      });
      if (!box.children.length) box.innerHTML = '<div class="cp-empty__hint">No executions yet.</div>';
    });
  }
  function loadSaved(){
    api(root.dataset.saved).then(function(res){
      var box = root.querySelector('[data-savedlist]'); box.innerHTML = '';
      (res.body || []).forEach(function(q){
        var d = document.createElement('div'); d.className = 'cp-list__item';
        d.innerHTML = '<strong>' + esc(q.name) + '</strong> <button class="cp-btn" style="padding:.15rem .5rem;font-size:.7rem" data-load>Load</button>' +
          ' <button class="cp-btn is-danger" style="padding:.15rem .5rem;font-size:.7rem" data-del>Delete</button><code>' + esc((q.sql || '').slice(0, 500)) + '</code>';
        d.querySelector('[data-load]').onclick = function(){ tabs[active].sql = q.sql; cm.setValue(q.sql); };
        d.querySelector('[data-del]').onclick = function(){
          api(root.dataset.unsave.replace('__ID__', q.id), {method: 'DELETE'}).then(function(){ loadSaved(); });
        };
        box.appendChild(d);
      });
      if (!box.children.length) box.innerHTML = '<div class="cp-empty__hint">No saved queries.</div>';
    });
  }
  function refreshWrite(){
    api(root.dataset.writestatus).then(function(res){
      var on = !!(res.body && res.body.active);
      pendingWrite = on;
      var b = root.querySelector('[data-writestate]');
      b.textContent = on ? 'WRITE MODE' : 'READ ONLY';
      b.className = 'cp-badge ' + (on ? 'is-danger' : 'is-warning');
    });
  }
  root.querySelector('[data-act="writemode"]').onclick = function(){
    if (!canWrite) { alert('Missing permission: sql.execute_write'); return; }
    modal('<h3>Enable write mode?</h3><p>Writes run on <strong>' + esc(slug) + '</strong> only, expire after 10 minutes, and every statement is audit logged. Destructive statements additionally require typing the project slug.</p>' +
      '<input type="password" class="cp-field" data-pw placeholder="Confirm your password">' +
      '<div style="display:flex;gap:.5rem;justify-content:flex-end"><button class="cp-btn" data-cancel>Cancel</button>' +
      '<button class="cp-btn is-danger" data-enable>Enable for 10 min</button></div>');
    root.querySelector('[data-cancel]').onclick = closeModal;
    root.querySelector('[data-enable]').onclick = function(){
      var pw = root.querySelector('[data-pw]').value;
      api(root.dataset.writemode, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({password: pw})})
        .then(function(res){ closeModal(); if (res.status === 200) { refreshWrite(); meta('Write mode active for 10 minutes.'); } else { meta('Write mode denied.'); } });
    };
  };
  refreshWrite();
})();
</script>
HTML;
        $replace = [
            '__RUN__' => route('control-plane.sql-run', ['project' => $p->id]),
            '__HISTORY__' => route('control-plane.sql-history', ['project' => $p->id]),
            '__SAVED__' => route('control-plane.sql-saved', ['project' => $p->id]),
            '__SAVE__' => route('control-plane.sql-save', ['project' => $p->id]),
            '__UNSAVE__' => route('control-plane.sql-unsave', ['project' => $p->id, 'id' => '__ID__']),
            '__WRITEMODE__' => route('control-plane.sql-write', ['project' => $p->id]),
            '__WRITESTATUS__' => route('control-plane.sql-write-status', ['project' => $p->id]),
            '__SLUG__' => $p->slug,
            '__CSRF__' => csrf_token(),
            '__CANWRITE__' => $canWrite ? '1' : '0',
            '__WMSTYLE__' => $canWrite ? '' : 'display:none',
        ];

        return str_replace(array_keys($replace), array_values($replace), $html);
    }
}
