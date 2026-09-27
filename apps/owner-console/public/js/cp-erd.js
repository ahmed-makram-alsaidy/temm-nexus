/* Phase 21A — interactive PostgreSQL ERD canvas (vanilla JS + SVG).
 *
 * Why vanilla instead of React Flow / Cytoscape: the Control Plane is a
 * server-rendered Laravel + Filament + Livewire stack with no React build
 * pipeline. Pulling in React Flow (React runtime + ~300KB) or Cytoscape for
 * one page would add a parallel SPA toolchain and fight Livewire DOM
 * diffing. A dependency-free SVG canvas (<30KB) covers the required
 * interactions (pan/zoom/drag/focus/export) and stays fast at 100+ tables
 * because edges/columns are filtered client-side before render.
 */
(function () {
  'use strict';

  var NODE_W = 240;
  var HEADER_H = 34;
  var ROW_H = 22;
  var COL_GAP_X = 90;
  var ROW_GAP_Y = 28;

  function $(sel, el) { return (el || document).querySelector(sel); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function init() {
    var root = document.getElementById('cp-erd');
    if (!root || root.dataset.ready) return;
    root.dataset.ready = '1';
    var cfg = JSON.parse(root.dataset.config || '{}');

    var S = {
      cfg: cfg,
      schema: cfg.schema || 'public',
      graph: null,
      serverLayout: null,
      pos: {},            // table -> {x, y}
      collapsed: {},      // table -> true
      focus: null,        // focused table name
      relatedOnly: false,
      hideIsolated: false,
      search: '',
      relFilter: '',
      selectedTable: null,
      selectedEdge: null,
      collapseAll: false,
      view: { x: 20, y: 20, k: 1 },
      saveTimer: null,
    };

    var els = {
      schemaSel: $('#cp-erd-schema', root),
      search: $('#cp-erd-search', root),
      relFilter: $('#cp-erd-relfilter', root),
      meta: $('#cp-erd-meta', root),
      banner: $('#cp-erd-banner', root),
      canvas: $('#cp-erd-canvas', root),
      svg: $('#cp-erd-svg', root),
      viewport: $('#cp-erd-viewport', root),
      tip: $('#cp-erd-tip', root),
      detail: $('#cp-erd-detail', root),
      empty: $('#cp-erd-empty', root),
    };

    /* ---------- data loading ---------- */

    function api(path, opts) {
      var headers = { 'Accept': 'application/json' };
      var token = document.querySelector('meta[name="csrf-token"]');
      if (token) headers['X-CSRF-TOKEN'] = token.getAttribute('content');
      return fetch(cfg.api + path, Object.assign({ headers: headers }, opts || {}))
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        });
    }

    function loadSchemas() {
      return api('/schemas').then(function (j) {
        els.schemaSel.innerHTML = '';
        (j.schemas || []).forEach(function (s) {
          var o = document.createElement('option');
          o.value = s; o.textContent = s;
          if (s === S.schema) o.selected = true;
          els.schemaSel.appendChild(o);
        });
        if (!j.schemas || !j.schemas.length) {
          var o = document.createElement('option');
          o.value = 'public'; o.textContent = 'public';
          els.schemaSel.appendChild(o);
        }
      }).catch(function () { /* selector keeps default; graph load reports errors */ });
    }

    function loadGraph() {
      setEmpty('Loading schema…');
      var t0 = performance.now();
      return api('?schema=' + encodeURIComponent(S.schema)).then(function (j) {
        S.graph = j.graph;
        S.serverLayout = j.layout || null;
        S.pos = {};
        S.collapsed = {};
        if (S.serverLayout) {
          Object.keys(S.serverLayout).forEach(function (t) {
            var p = S.serverLayout[t];
            S.pos[t] = { x: +p.x || 0, y: +p.y || 0 };
            if (p.collapsed) S.collapsed[t] = true;
          });
        }
        S.focus = null; S.selectedTable = null; S.selectedEdge = null;
        // Large schemas open with columns collapsed so the canvas stays usable.
        S.collapseAll = (S.graph.tables.length > 50) && !S.serverLayout;
        if (S.graph.tables.length > 50) {
          showBanner('Large schema (' + S.graph.tables.length + ' tables): columns start collapsed. ' +
            'Use search, focus mode, or hide isolated tables to narrow the view.');
        } else { showBanner(''); }
        autoLayout(false);
        render(performance.now() - t0);
        setEmpty('');
        if (!S.graph.tables.length) setEmpty('No tables in schema "' + S.schema + '".');
      }).catch(function (e) {
        setEmpty('Could not load schema graph (' + e.message + ').');
      });
    }

    /* ---------- layout ---------- */

    function nodeHeight(t) {
      if (S.collapsed[t.name] || S.collapseAll) return HEADER_H + ROW_H;
      return HEADER_H + 6 + t.columns.length * ROW_H;
    }

    // Layered layout: depth = longest FK chain to a root (tables nothing
    // references... i.e. referenced tables first). Cycles fall through.
    function autoLayout(overwrite) {
      var g = S.graph;
      if (!g) return;
      var depth = {};
      g.tables.forEach(function (t) { depth[t.name] = 0; });
      // edge: from_table (child) -> to_table (parent). Parent depth 0.
      for (var i = 0; i < g.tables.length + 2; i++) {
        var changed = false;
        g.edges.forEach(function (e) {
          if (!(e.from_table in depth) || !(e.to_table in depth)) return;
          if (depth[e.from_table] < depth[e.to_table] + 1) {
            depth[e.from_table] = depth[e.to_table] + 1;
            changed = true;
          }
        });
        if (!changed) break;
      }
      var cols = {};
      g.tables.forEach(function (t) {
        var d = depth[t.name] || 0;
        (cols[d] = cols[d] || []).push(t);
      });
      Object.keys(cols).forEach(function (d) {
        cols[d].sort(function (a, b) { return a.name < b.name ? -1 : 1; });
        var y = 10;
        cols[d].forEach(function (t) {
          if (overwrite || !S.pos[t.name]) {
            S.pos[t.name] = { x: 10 + (+d) * (NODE_W + COL_GAP_X), y: y };
          }
          y += nodeHeight(t) + ROW_GAP_Y;
        });
      });
    }

    /* ---------- visibility ---------- */

    function neighborsOf(name) {
      var set = {};
      S.graph.edges.forEach(function (e) {
        if (e.from_table === name) set[e.to_table] = 1;
        if (e.to_table === name) set[e.from_table] = 1;
      });
      return set;
    }

    function visibleTables() {
      var g = S.graph;
      var degree = {};
      g.edges.forEach(function (e) {
        degree[e.from_table] = (degree[e.from_table] || 0) + 1;
        degree[e.to_table] = (degree[e.to_table] || 0) + 1;
      });
      var focusSet = S.focus ? neighborsOf(S.focus) : null;
      if (S.focus) focusSet[S.focus] = 1;
      var q = S.search.trim().toLowerCase();
      return g.tables.filter(function (t) {
        if (S.relatedOnly && focusSet && !focusSet[t.name]) return false;
        if (S.hideIsolated && !degree[t.name]) return false;
        if (q && t.name.toLowerCase().indexOf(q) === -1) {
          // Non-matching tables stay visible but dimmed (context preserved).
        }
        return true;
      });
    }

    function visibleEdges(visSet) {
      var q = S.relFilter.trim().toLowerCase();
      return S.graph.edges.filter(function (e) {
        if (!visSet[e.from_table] || !visSet[e.to_table]) return false;
        if (q && (e.constraint + ' ' + e.from_table + ' ' + e.to_table).toLowerCase().indexOf(q) === -1) return false;
        return true;
      });
    }

    /* ---------- render ---------- */

    var nodeEls = {};
    var edgeEls = [];

    function rowY(t, idx) {
      var p = S.pos[t.name];
      if (S.collapsed[t.name] || S.collapseAll) return p.y + HEADER_H + ROW_H / 2;
      return p.y + HEADER_H + 6 + idx * ROW_H + ROW_H / 2;
    }

    function colIndex(t, col) {
      for (var i = 0; i < t.columns.length; i++) if (t.columns[i].name === col) return i;
      return -1;
    }

    function edgePath(e, byName) {
      var a = byName[e.from_table], b = byName[e.to_table];
      if (!a || !b) return '';
      var pa = S.pos[a.name], pb = S.pos[b.name];
      var y1 = rowY(a, Math.max(0, colIndex(a, e.from_column)));
      var y2 = rowY(b, Math.max(0, colIndex(b, e.to_column)));
      var x1 = pa.x + NODE_W, x2 = pb.x;
      if (x2 < x1) { x1 = pa.x; x2 = pb.x + NODE_W; }
      var dx = Math.max(40, Math.abs(x2 - x1) / 2);
      return 'M' + x1 + ' ' + y1 + ' C' + (x1 + (x2 >= x1 ? dx : -dx)) + ' ' + y1 + ' ' +
        (x2 - (x2 >= x1 ? dx : -dx)) + ' ' + y2 + ' ' + x2 + ' ' + y2;
    }

    function render(fetchMs) {
      var t0 = performance.now();
      var g = S.graph;
      nodeEls = {}; edgeEls = [];
      var vp = els.viewport;
      vp.innerHTML = '';
      if (!g) return;

      var tables = visibleTables();
      var visSet = {};
      tables.forEach(function (t) { visSet[t.name] = 1; });
      var edges = visibleEdges(visSet);
      var byName = {};
      g.tables.forEach(function (t) { byName[t.name] = t; });
      var q = S.search.trim().toLowerCase();

      var NS = 'http://www.w3.org/2000/svg';
      // Edges under nodes.
      var eg = document.createElementNS(NS, 'g');
      edges.forEach(function (e, i) {
        var d = edgePath(e, byName);
        if (!d) return;
        var hit = document.createElementNS(NS, 'path');
        hit.setAttribute('d', d);
        hit.setAttribute('class', 'erd-edge-hit');
        hit.dataset.i = i;
        var p = document.createElementNS(NS, 'path');
        p.setAttribute('d', d);
        var cls = 'erd-edge';
        if (S.selectedEdge === i) cls += ' erd-selected';
        p.setAttribute('class', cls);
        p.setAttribute('marker-end', 'url(#cp-erd-arrow)');
        p.dataset.i = i;
        eg.appendChild(hit); eg.appendChild(p);
        edgeEls.push({ e: e, i: i, hit: hit, el: p });
      });
      vp.appendChild(eg);

      var ng = document.createElementNS(NS, 'g');
      tables.forEach(function (t) {
        var p = S.pos[t.name] || (S.pos[t.name] = { x: 10, y: 10 });
        var h = nodeHeight(t);
        var node = document.createElementNS(NS, 'g');
        var cls = 'erd-node';
        if (q && t.name.toLowerCase().indexOf(q) !== -1) cls += ' erd-match';
        else if (q) cls += ' erd-dim';
        if (S.selectedTable === t.name) cls += ' erd-selected';
        if (S.focus === t.name) cls += ' erd-match';
        node.setAttribute('class', cls);
        node.setAttribute('transform', 'translate(' + p.x + ',' + p.y + ')');
        node.dataset.table = t.name;

        var html = '<rect class="erd-box" x="0" y="0" width="' + NODE_W + '" height="' + h +
          '" rx="8" stroke-width="1.2"/>' +
          '<rect class="erd-head" x="0" y="0" width="' + NODE_W + '" height="' + HEADER_H + '" rx="8"/>' +
          '<rect class="erd-head" x="0" y="' + (HEADER_H - 8) + '" width="' + NODE_W + '" height="8"/>' +
          '<text class="erd-title" x="12" y="22" font-size="13" font-family="monospace" font-weight="bold">' +
          esc(t.name.length > 26 ? t.name.slice(0, 25) + '…' : t.name) + '</text>' +
          '<text class="erd-collapse-btn" x="' + (NODE_W - 30) + '" y="22" font-size="13" font-family="monospace" data-table="' +
          esc(t.name) + '" style="cursor:pointer">' + ((S.collapsed[t.name] || S.collapseAll) ? '[+]' : '[–]') + '</text>';
        if (S.collapsed[t.name] || S.collapseAll) {
          html += '<text class="erd-sub" x="12" y="' + (HEADER_H + 17) + '" font-size="11" font-family="monospace">' +
            t.columns.length + ' columns…</text>';
        } else {
          t.columns.forEach(function (c, idx) {
            var y = HEADER_H + 6 + idx * ROW_H + 16;
            var icon = c.pk ? '🔑' : (c.fk ? '🔗' : '·');
            var cls = c.pk ? 'erd-type-pk' : (c.fk ? 'erd-type-fk' : 'erd-type');
            html += '<text x="10" y="' + y + '" font-size="11">' + icon + '</text>' +
              '<text class="erd-col" x="28" y="' + y + '" font-size="11" font-family="monospace">' +
              esc(c.name.length > 20 ? c.name.slice(0, 19) + '…' : c.name) + '</text>' +
              '<text class="' + cls + '" x="' + (NODE_W - 10) + '" y="' + y + '" font-size="10" font-family="monospace" text-anchor="end">' +
              esc(c.type.length > 18 ? c.type.slice(0, 17) + '…' : c.type) + (c.nullable ? '' : ' ⁿ') + '</text>';
          });
        }
        node.innerHTML = html;
        ng.appendChild(node);
        nodeEls[t.name] = node;
      });
      vp.appendChild(ng);

      applyView();
      bindNodes();
      bindEdges();
      renderDetail();
      var renderMs = Math.round(performance.now() - t0);
      els.meta.textContent = g.tables.length + ' tables · ' + g.edges.length + ' FKs · ' +
        'metadata ' + (g.timings ? g.timings.metadata_ms : '?') + 'ms · render ' + renderMs + 'ms' +
        (fetchMs != null ? ' · fetch ' + Math.round(fetchMs) + 'ms' : '');
    }

    function refreshEdges() {
      var byName = {};
      S.graph.tables.forEach(function (t) { byName[t.name] = t; });
      edgeEls.forEach(function (o) {
        o.el.setAttribute('d', edgePath(o.e, byName));
        o.hit.setAttribute('d', edgePath(o.e, byName));
      });
    }

    /* ---------- view (pan / zoom / fit) ---------- */

    function applyView() {
      els.viewport.setAttribute('transform',
        'translate(' + S.view.x + ',' + S.view.y + ') scale(' + S.view.k + ')');
    }

    function svgPoint(clientX, clientY) {
      var r = els.svg.getBoundingClientRect();
      return {
        x: (clientX - r.left - S.view.x) / S.view.k,
        y: (clientY - r.top - S.view.y) / S.view.k,
      };
    }

    function zoomAt(clientX, clientY, factor) {
      var r = els.svg.getBoundingClientRect();
      var mx = clientX - r.left, my = clientY - r.top;
      var k2 = Math.min(2.5, Math.max(0.2, S.view.k * factor));
      var s = k2 / S.view.k;
      S.view.x = mx - (mx - S.view.x) * s;
      S.view.y = my - (my - S.view.y) * s;
      S.view.k = k2;
      applyView();
    }

    function fit() {
      var tables = visibleTables();
      if (!tables.length) return;
      var x0 = 1e9, y0 = 1e9, x1 = -1e9, y1 = -1e9;
      tables.forEach(function (t) {
        var p = S.pos[t.name] || { x: 0, y: 0 };
        x0 = Math.min(x0, p.x); y0 = Math.min(y0, p.y);
        x1 = Math.max(x1, p.x + NODE_W); y1 = Math.max(y1, p.y + nodeHeight(t));
      });
      var r = els.svg.getBoundingClientRect();
      var k = Math.min(1.5, Math.min((r.width - 60) / Math.max(1, x1 - x0), (r.height - 60) / Math.max(1, y1 - y0)));
      k = Math.max(0.2, k);
      S.view.k = k;
      S.view.x = (r.width - (x1 - x0) * k) / 2 - x0 * k;
      S.view.y = (r.height - (y1 - y0) * k) / 2 - y0 * k;
      applyView();
    }

    function centerOn(name) {
      var t = null;
      S.graph.tables.forEach(function (x) { if (x.name === name) t = x; });
      if (!t) return;
      var p = S.pos[name] || { x: 0, y: 0 };
      var r = els.svg.getBoundingClientRect();
      S.view.k = Math.max(S.view.k, 0.9);
      S.view.x = r.width / 2 - (p.x + NODE_W / 2) * S.view.k;
      S.view.y = r.height / 2 - (p.y + nodeHeight(t) / 2) * S.view.k;
      applyView();
    }

    /* ---------- interactions ---------- */

    function bindNodes() {
      Object.keys(nodeEls).forEach(function (name) {
        var node = nodeEls[name];
        node.addEventListener('pointerdown', function (ev) {
          if (ev.target.classList && ev.target.classList.contains('erd-collapse-btn')) return;
          ev.stopPropagation();
          node.setPointerCapture(ev.pointerId);
          var start = svgPoint(ev.clientX, ev.clientY);
          var orig = { x: S.pos[name].x, y: S.pos[name].y };
          var moved = false;
          function onMove(e2) {
            var pt = svgPoint(e2.clientX, e2.clientY);
            S.pos[name].x = Math.round(orig.x + (pt.x - start.x));
            S.pos[name].y = Math.round(orig.y + (pt.y - start.y));
            node.setAttribute('transform', 'translate(' + S.pos[name].x + ',' + S.pos[name].y + ')');
            refreshEdges();
            moved = true;
          }
          function onUp() {
            node.removeEventListener('pointermove', onMove);
            node.removeEventListener('pointerup', onUp);
            if (moved) scheduleSave();
          }
          node.addEventListener('pointermove', onMove);
          node.addEventListener('pointerup', onUp);
        });
        node.addEventListener('click', function (ev) {
          if (ev.target.classList && ev.target.classList.contains('erd-collapse-btn')) {
            ev.stopPropagation();
            if (S.collapseAll) { S.collapseAll = false; S.collapsed = {}; }
            else S.collapsed[name] = !S.collapsed[name];
            render();
            scheduleSave();
            return;
          }
          ev.stopPropagation();
          S.selectedTable = (S.selectedTable === name) ? null : name;
          S.selectedEdge = null;
          render();
        });
        node.addEventListener('dblclick', function (ev) {
          ev.stopPropagation();
          window.location.href = S.cfg.schemaUrl.replace('__T__', encodeURIComponent(name));
        });
      });
    }

    function bindEdges() {
      edgeEls.forEach(function (o) {
        function showTip(ev) {
          els.tip.style.display = 'block';
          var r = els.canvas.getBoundingClientRect();
          els.tip.style.left = Math.min(r.width - 240, ev.clientX - r.left + 14) + 'px';
          els.tip.style.top = (ev.clientY - r.top + 12) + 'px';
          els.tip.innerHTML = '<code>' + esc(o.e.from_table + '.' + o.e.from_column) + '</code> → ' +
            '<code>' + esc(o.e.to_table + '.' + o.e.to_column) + '</code><br>' +
            '<span style="color:var(--cp-text-dim)">' + esc(o.e.constraint) +
            ' · ON UPDATE ' + esc(o.e.on_update) + ' · ON DELETE ' + esc(o.e.on_delete) + '</span>';
        }
        o.hit.addEventListener('pointerenter', showTip);
        o.hit.addEventListener('pointermove', showTip);
        o.hit.addEventListener('pointerleave', function () { els.tip.style.display = 'none'; });
        o.hit.addEventListener('click', function (ev) {
          ev.stopPropagation();
          S.selectedEdge = o.i; S.selectedTable = null;
          render();
        });
      });
    }

    // Background pan.
    (function () {
      var panning = false, sx = 0, sy = 0, ox = 0, oy = 0;
      els.canvas.addEventListener('pointerdown', function (ev) {
        if (ev.target.closest('.erd-node')) return;
        panning = true;
        sx = ev.clientX; sy = ev.clientY; ox = S.view.x; oy = S.view.y;
        els.canvas.setPointerCapture(ev.pointerId);
      });
      els.canvas.addEventListener('pointermove', function (ev) {
        if (!panning) return;
        S.view.x = ox + (ev.clientX - sx);
        S.view.y = oy + (ev.clientY - sy);
        applyView();
      });
      ['pointerup', 'pointercancel'].forEach(function (t) {
        els.canvas.addEventListener(t, function () { panning = false; });
      });
      els.svg.addEventListener('wheel', function (ev) {
        ev.preventDefault();
        zoomAt(ev.clientX, ev.clientY, ev.deltaY < 0 ? 1.1 : 1 / 1.1);
      }, { passive: false });
      els.canvas.addEventListener('click', function () {
        if (S.selectedTable || S.selectedEdge != null) {
          S.selectedTable = null; S.selectedEdge = null;
          render();
        }
      });
    })();

    /* ---------- detail panel ---------- */

    function renderDetail() {
      var d = els.detail;
      if (S.selectedEdge != null && S.graph) {
        var found = null;
        edgeEls.forEach(function (o) { if (o.i === S.selectedEdge) found = o.e; });
        if (found) {
          d.innerHTML = '<h3>Relationship</h3>' +
            '<p><code>' + esc(found.from_table + '.' + found.from_column) + '</code><br>→ ' +
            '<code>' + esc(found.to_table + '.' + found.to_column) + '</code></p>' +
            '<h4>Constraint</h4><p><code>' + esc(found.constraint) + '</code></p>' +
            '<h4>Actions</h4><p>ON UPDATE ' + esc(found.on_update) + '<br>ON DELETE ' + esc(found.on_delete) + '</p>' +
            '<div class="cp-erd__btnrow"><button class="cp-btn" data-act="open-from">Open ' + esc(found.from_table) + '</button></div>';
          bindDetailButtons(d, found.from_table);
          return;
        }
      }
      if (S.selectedTable) {
        var t = null;
        S.graph.tables.forEach(function (x) { if (x.name === S.selectedTable) t = x; });
        if (t) {
          var rows = t.columns.map(function (c) {
            var marks = (c.pk ? ' PK' : '') + (c.fk ? ' FK' : '') + (c.unique ? ' UQ' : '') + (c.nullable ? '' : ' NOT NULL');
            return '<tr><td><code>' + esc(c.name) + '</code></td><td><code>' +
              esc(c.type) + '</code></td><td>' + esc(marks.trim() || '—') + '</td></tr>';
          }).join('');
          var fks = S.graph.edges.filter(function (e) { return e.from_table === t.name || e.to_table === t.name; })
            .map(function (e) {
              return '<tr><td><code>' + esc(e.from_table + '.' + e.from_column) + '</code> → <code>' +
                esc(e.to_table + '.' + e.to_column) + '</code></td></tr>';
            }).join('');
          d.innerHTML = '<h3>' + esc(t.name) + '</h3>' +
            '<p style="color:var(--cp-text-dim)">≈' + (t.rows_estimate == null ? '—' : Number(t.rows_estimate).toLocaleString()) +
            ' rows · PK (' + esc(t.pk.join(', ') || '—') + ')</p>' +
            '<h4>Columns (' + t.columns.length + ')</h4>' +
            '<table><thead><tr><th>Name</th><th>Type</th><th>Keys</th></tr></thead><tbody>' + rows + '</tbody></table>' +
            '<h4>Relationships (' + (fks ? S.graph.edges.filter(function (e) { return e.from_table === t.name || e.to_table === t.name; }).length : 0) + ')</h4>' +
            (fks ? '<table><tbody>' + fks + '</tbody></table>' : '<p>None.</p>') +
            '<div class="cp-erd__btnrow">' +
            '<button class="cp-btn" data-act="records">Open in Table Editor</button>' +
            '<button class="cp-btn" data-act="schema">Open Schema</button>' +
            '<button class="cp-btn" data-act="focus">Focus related</button></div>';
          bindDetailButtons(d, t.name);
          return;
        }
      }
      if (S.focus) {
        var n = Object.keys(neighborsOf(S.focus)).length;
        d.innerHTML = '<h3>Focus: ' + esc(S.focus) + '</h3><p>' + n + ' directly related table(s).</p>' +
          '<div class="cp-erd__btnrow"><button class="cp-btn" data-act="unfocus">Clear focus</button></div>';
        bindDetailButtons(d, S.focus);
        return;
      }
      d.innerHTML = '<h3>ERD</h3><p style="color:var(--cp-text-dim)">Click a table for columns, keys and ' +
        'shortcuts. Hover a relationship line for constraint details; click it to pin them here. ' +
        'Double-click a table to open its Schema page.</p>' +
        (S.graph && S.graph.enums && S.graph.enums.length
          ? '<h4>Enums (' + S.graph.enums.length + ')</h4><p><code>' +
            esc(S.graph.enums.map(function (e) { return e.name; }).join(', ')) + '</code></p>' : '');
    }

    function bindDetailButtons(d, table) {
      $('[data-act="records"]', d) &&
        $('[data-act="records"]', d).addEventListener('click', function () {
          window.location.href = S.cfg.recordsUrl.replace('__T__', encodeURIComponent(table));
        });
      $('[data-act="schema"]', d) &&
        $('[data-act="schema"]', d).addEventListener('click', function () {
          window.location.href = S.cfg.schemaUrl.replace('__T__', encodeURIComponent(table));
        });
      $('[data-act="open-from"]', d) &&
        $('[data-act="open-from"]', d).addEventListener('click', function () {
          S.selectedTable = table; S.selectedEdge = null;
          render();
        });
      $('[data-act="focus"]', d) &&
        $('[data-act="focus"]', d).addEventListener('click', function () {
          S.focus = table; S.relatedOnly = true;
          var t = $('#cp-erd-related', root); if (t) t.checked = true;
          render(); fit();
        });
      $('[data-act="unfocus"]', d) &&
        $('[data-act="unfocus"]', d).addEventListener('click', function () {
          S.focus = null; render();
        });
    }

    /* ---------- persist layout ---------- */

    function scheduleSave() {
      clearTimeout(S.saveTimer);
      S.saveTimer = setTimeout(function () {
        var layout = {};
        Object.keys(S.pos).forEach(function (t) {
          layout[t] = { x: Math.round(S.pos[t].x), y: Math.round(S.pos[t].y), collapsed: !!S.collapsed[t] };
        });
        api('/layout', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ schema: S.schema, layout: layout }),
        }).catch(function () { /* layout persist is best-effort */ });
      }, 800);
    }

    /* ---------- export ---------- */

    // Standalone colors for SVG/PNG downloads (the live canvas uses the
    // theme-adaptive classes from cp-erd.css instead).
    var ERD_EXPORT_STYLE = '.erd-box{fill:#1a1a1f;stroke:#34343c}' +
      '.erd-head{fill:#27272e}.erd-title{fill:#f4f4f5}.erd-col{fill:#e4e4e7}' +
      '.erd-sub{fill:#71717a}.erd-type-pk{fill:#fbbf24}.erd-type-fk{fill:#a5b4fc}' +
      '.erd-type{fill:#71717a}.erd-collapse-btn{fill:#a1a1aa}' +
      '.erd-edge{fill:none;stroke:#818cf8;stroke-width:1.4;opacity:.65}' +
      'text{font-family:monospace}';

    function serializeSvg() {
      var clone = els.svg.cloneNode(true);
      clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
      var tables = visibleTables();
      var x0 = 1e9, y0 = 1e9, x1 = -1e9, y1 = -1e9;
      tables.forEach(function (t) {
        var p = S.pos[t.name] || { x: 0, y: 0 };
        x0 = Math.min(x0, p.x); y0 = Math.min(y0, p.y);
        x1 = Math.max(x1, p.x + NODE_W); y1 = Math.max(y1, p.y + nodeHeight(t));
      });
      if (!tables.length) { x0 = 0; y0 = 0; x1 = 800; y1 = 600; }
      var pad = 30;
      clone.setAttribute('viewBox', (x0 - pad) + ' ' + (y0 - pad) + ' ' + (x1 - x0 + pad * 2) + ' ' + (y1 - y0 + pad * 2));
      clone.setAttribute('width', Math.round(x1 - x0 + pad * 2));
      clone.setAttribute('height', Math.round(y1 - y0 + pad * 2));
      var bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
      bg.setAttribute('x', x0 - pad); bg.setAttribute('y', y0 - pad);
      bg.setAttribute('width', x1 - x0 + pad * 2); bg.setAttribute('height', y1 - y0 + pad * 2);
      bg.setAttribute('fill', '#0c0c10');
      // Viewport transform must not leak into the export: bake content coords.
      var inner = clone.querySelector('g');
      if (inner) inner.setAttribute('transform', '');
      var style = document.createElementNS('http://www.w3.org/2000/svg', 'style');
      style.textContent = ERD_EXPORT_STYLE;
      clone.insertBefore(style, clone.firstChild);
      clone.insertBefore(bg, clone.firstChild);
      return new XMLSerializer().serializeToString(clone);
    }

    function download(name, blob) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = name;
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    function exportSVG() {
      download('erd-' + S.schema + '.svg',
        new Blob([serializeSvg()], { type: 'image/svg+xml' }));
    }

    function exportPNG() {
      var img = new Image();
      var svgBlob = new Blob([serializeSvg()], { type: 'image/svg+xml;charset=utf-8' });
      var url = URL.createObjectURL(svgBlob);
      img.onload = function () {
        var c = document.createElement('canvas');
        c.width = img.width * 2 || 1600; c.height = img.height * 2 || 1200;
        var ctx = c.getContext('2d');
        ctx.fillStyle = '#0c0c10';
        ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { download('erd-' + S.schema + '.png', b); }, 'image/png');
      };
      img.src = url;
    }

    /* ---------- toolbar wiring ---------- */

    function setEmpty(msg) {
      els.empty.textContent = msg || '';
      els.empty.style.display = msg ? 'flex' : 'none';
    }

    function showBanner(msg) {
      els.banner.textContent = msg || '';
      els.banner.style.display = msg ? 'block' : 'none';
    }

    $('#cp-erd-fit', root).addEventListener('click', fit);
    $('#cp-erd-auto', root).addEventListener('click', function () {
      autoLayout(true); render(); scheduleSave();
    });
    $('#cp-erd-reset', root).addEventListener('click', function () {
      S.pos = {}; S.collapsed = {}; S.collapseAll = false; S.focus = null;
      autoLayout(true); render(); fit(); scheduleSave();
    });
    $('#cp-erd-svg-btn', root).addEventListener('click', exportSVG);
    $('#cp-erd-png-btn', root).addEventListener('click', exportPNG);
    $('#cp-erd-zoomin', root).addEventListener('click', function () {
      var r = els.svg.getBoundingClientRect();
      zoomAt(r.left + r.width / 2, r.top + r.height / 2, 1.2);
    });
    $('#cp-erd-zoomout', root).addEventListener('click', function () {
      var r = els.svg.getBoundingClientRect();
      zoomAt(r.left + r.width / 2, r.top + r.height / 2, 1 / 1.2);
    });

    els.schemaSel.addEventListener('change', function () {
      S.schema = els.schemaSel.value;
      loadGraph().then(fit);
    });
    var searchTimer = null;
    els.search.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        S.search = els.search.value;
        render();
        if (S.search.trim()) {
          var g = S.graph;
          for (var i = 0; i < g.tables.length; i++) {
            if (g.tables[i].name.toLowerCase().indexOf(S.search.trim().toLowerCase()) !== -1) {
              S.selectedTable = g.tables[i].name;
              render(); centerOn(g.tables[i].name);
              break;
            }
          }
        }
      }, 200);
    });
    els.relFilter.addEventListener('input', function () {
      S.relFilter = els.relFilter.value;
      render();
    });
    $('#cp-erd-isolated', root).addEventListener('change', function (ev) {
      S.hideIsolated = ev.target.checked; render();
    });
    $('#cp-erd-related', root).addEventListener('change', function (ev) {
      S.relatedOnly = ev.target.checked;
      if (S.relatedOnly && !S.focus && S.selectedTable) S.focus = S.selectedTable;
      render();
    });
    $('#cp-erd-collapse', root).addEventListener('change', function (ev) {
      S.collapseAll = ev.target.checked; render();
    });
    $('#cp-erd-clear-focus', root).addEventListener('click', function () {
      S.focus = null; S.relatedOnly = false;
      var t = $('#cp-erd-related', root); if (t) t.checked = false;
      render();
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && root.contains(document.activeElement)) {
        S.focus = null; S.selectedTable = null; S.selectedEdge = null;
        render();
      }
    });

    // Initial framing: true fit when the graph is small enough to stay
    // legible, otherwise a 1:1 top-left view (Fit remains one click away).
    function initialView() {
      var tables = visibleTables();
      if (!tables.length) return;
      var x0 = 1e9, y0 = 1e9, x1 = -1e9, y1 = -1e9;
      tables.forEach(function (t) {
        var p = S.pos[t.name] || { x: 0, y: 0 };
        x0 = Math.min(x0, p.x); y0 = Math.min(y0, p.y);
        x1 = Math.max(x1, p.x + NODE_W); y1 = Math.max(y1, p.y + nodeHeight(t));
      });
      var r = els.svg.getBoundingClientRect();
      var kFit = Math.min((r.width - 60) / Math.max(1, x1 - x0), (r.height - 60) / Math.max(1, y1 - y0));
      if (kFit >= 0.6) { fit(); return; }
      S.view.k = 1;
      S.view.x = 20 - x0;
      S.view.y = 20 - y0;
      applyView();
    }

    loadSchemas().then(function () { return loadGraph(); }).then(initialView);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else { init(); }
  // Filament/Livewire navigates without full reloads — re-init on updates.
  document.addEventListener('livewire:navigated', function () {
    var root = document.getElementById('cp-erd');
    if (root) delete root.dataset.ready;
    init();
  });
})();
