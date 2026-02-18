(function () {
  'use strict';

  var data = window.ASKWP_USAGE_DATA || {};
  var log = Array.isArray(data.log) ? data.log : [];
  var pricing = data.pricing || {};

  // ── Helpers ──

  function formatNumber(n) {
    if (typeof n !== 'number' || isNaN(n)) { return '0'; }
    return n.toLocaleString('en-US');
  }

  function formatCost(n) {
    if (typeof n !== 'number' || isNaN(n)) { return '$0.00'; }
    return '$' + n.toFixed(4);
  }

  function dateKey(ts) {
    var d = new Date(ts * 1000);
    var m = ('0' + (d.getMonth() + 1)).slice(-2);
    var day = ('0' + d.getDate()).slice(-2);
    return d.getFullYear() + '-' + m + '-' + day;
  }

  function estimateCost(model, input, output) {
    var p = pricing[model];
    if (!p) { return null; }
    return ((input / 1000000) * p.input) + ((output / 1000000) * p.output);
  }

  // ── Aggregation ──

  function aggregateByDay(entries) {
    var map = {};
    entries.forEach(function (e) {
      var key = dateKey(e.ts);
      if (!map[key]) {
        map[key] = { date: key, input: 0, output: 0, total: 0, requests: 0 };
      }
      map[key].input += e.input || 0;
      map[key].output += e.output || 0;
      map[key].total += e.total || 0;
      map[key].requests += 1;
    });

    var days = Object.keys(map).sort();
    return days.map(function (k) { return map[k]; });
  }

  function aggregateByModel(entries) {
    var map = {};
    entries.forEach(function (e) {
      var m = e.model || 'unknown';
      if (!map[m]) {
        map[m] = { model: m, input: 0, output: 0, total: 0, requests: 0 };
      }
      map[m].input += e.input || 0;
      map[m].output += e.output || 0;
      map[m].total += e.total || 0;
      map[m].requests += 1;
    });

    return Object.keys(map).map(function (k) { return map[k]; })
      .sort(function (a, b) { return b.requests - a.requests; });
  }

  // ── Render: Summary ──

  function renderSummary(container) {
    var totalRequests = log.length;
    var totalTokens = 0;
    var monthTokens = 0;
    var totalCost = 0;
    var now = new Date();
    var monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
    var monthTs = Math.floor(monthStart.getTime() / 1000);

    log.forEach(function (e) {
      totalTokens += e.total || 0;
      var cost = estimateCost(e.model, e.input || 0, e.output || 0);
      if (cost !== null) { totalCost += cost; }
      if (e.ts >= monthTs) { monthTokens += e.total || 0; }
    });

    var cards = [
      { label: 'Total Requests', value: formatNumber(totalRequests) },
      { label: 'Total Tokens', value: formatNumber(totalTokens) },
      { label: 'Tokens This Month', value: formatNumber(monthTokens) },
      { label: 'Est. Total Cost', value: formatCost(totalCost) },
    ];

    container.innerHTML = '';
    cards.forEach(function (c) {
      var card = document.createElement('div');
      card.className = 'askwp-usage-card';
      card.innerHTML = '<div class="askwp-usage-card-value">' + c.value + '</div>'
        + '<div class="askwp-usage-card-label">' + c.label + '</div>';
      container.appendChild(card);
    });
  }

  // ── Render: Bar Chart ──

  function renderBarChart(container) {
    var daily = aggregateByDay(log);
    var last30 = daily.slice(-30);

    if (!last30.length) {
      container.innerHTML = '<p style="color:#6b7280;">No usage data yet.</p>';
      return;
    }

    var maxTotal = 1;
    last30.forEach(function (d) {
      if (d.total > maxTotal) { maxTotal = d.total; }
    });

    container.innerHTML = '';

    var chartInner = document.createElement('div');
    chartInner.className = 'askwp-chart-inner';

    last30.forEach(function (d) {
      var col = document.createElement('div');
      col.className = 'askwp-chart-col';

      var bar = document.createElement('div');
      bar.className = 'askwp-chart-bar';

      var inputH = Math.max(2, Math.round((d.input / maxTotal) * 120));
      var outputH = Math.max(1, Math.round((d.output / maxTotal) * 120));

      var inputSeg = document.createElement('div');
      inputSeg.className = 'askwp-chart-seg askwp-chart-seg-input';
      inputSeg.style.height = inputH + 'px';

      var outputSeg = document.createElement('div');
      outputSeg.className = 'askwp-chart-seg askwp-chart-seg-output';
      outputSeg.style.height = outputH + 'px';

      bar.appendChild(outputSeg);
      bar.appendChild(inputSeg);
      bar.title = d.date + '\nInput: ' + formatNumber(d.input)
        + '\nOutput: ' + formatNumber(d.output)
        + '\nRequests: ' + d.requests;

      var label = document.createElement('div');
      label.className = 'askwp-chart-label';
      label.textContent = d.date.slice(5);

      col.appendChild(bar);
      col.appendChild(label);
      chartInner.appendChild(col);
    });

    container.appendChild(chartInner);

    var legend = document.createElement('div');
    legend.className = 'askwp-chart-legend';
    legend.innerHTML = '<span class="askwp-chart-seg-input" style="display:inline-block;width:12px;height:12px;border-radius:2px;"></span> Input '
      + '<span class="askwp-chart-seg-output" style="display:inline-block;width:12px;height:12px;border-radius:2px;margin-left:12px;"></span> Output';
    container.appendChild(legend);
  }

  // ── Render: Model Table ──

  function renderModelTable(container) {
    var models = aggregateByModel(log);

    if (!models.length) {
      container.innerHTML = '<p style="color:#6b7280;">No usage data yet.</p>';
      return;
    }

    var html = '<table class="widefat askwp-usage-table">'
      + '<thead><tr><th>Model</th><th>Requests</th><th>Input Tokens</th><th>Output Tokens</th><th>Total Tokens</th><th>Est. Cost</th></tr></thead>'
      + '<tbody>';

    models.forEach(function (m) {
      var cost = estimateCost(m.model, m.input, m.output);
      html += '<tr>'
        + '<td><code>' + m.model + '</code></td>'
        + '<td>' + formatNumber(m.requests) + '</td>'
        + '<td>' + formatNumber(m.input) + '</td>'
        + '<td>' + formatNumber(m.output) + '</td>'
        + '<td>' + formatNumber(m.total) + '</td>'
        + '<td>' + (cost !== null ? formatCost(cost) : '<em>n/a</em>') + '</td>'
        + '</tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;
  }

  // ── Init ──

  function init() {
    var summary = document.getElementById('askwp-usage-summary');
    var chart = document.getElementById('askwp-usage-chart');
    var table = document.getElementById('askwp-usage-model-table');

    if (summary) { renderSummary(summary); }
    if (chart) { renderBarChart(chart); }
    if (table) { renderModelTable(table); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
