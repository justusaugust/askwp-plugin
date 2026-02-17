(function () {
  'use strict';

  var container = document.getElementById('askwp-form-builder');
  var hiddenInput = document.getElementById('askwp_form_fields');
  if (!container || !hiddenInput) { return; }

  var fields = [];
  try {
    fields = JSON.parse(hiddenInput.value);
  } catch (e) {
    fields = [];
  }
  if (!Array.isArray(fields) || fields.length === 0) {
    fields = [
      { type: 'text', name: 'full_name', label: 'Full Name', required: true, maxlength: 120, step: 1 },
      { type: 'email', name: 'email', label: 'Email', required: true, maxlength: 120, step: 1 },
      { type: 'textarea', name: 'message', label: 'Message', required: true, maxlength: 2000, step: 1 }
    ];
  }

  var fieldTypes = [
    { value: 'text', label: 'Text' },
    { value: 'email', label: 'Email' },
    { value: 'phone', label: 'Phone' },
    { value: 'textarea', label: 'Textarea' },
    { value: 'select', label: 'Select' },
    { value: 'checkbox', label: 'Checkbox' }
  ];

  function sync() {
    hiddenInput.value = JSON.stringify(fields);
  }

  function render() {
    container.innerHTML = '';

    fields.forEach(function (field, index) {
      var card = document.createElement('div');
      card.className = 'askwp-fb-card';

      // Header.
      var header = document.createElement('div');
      header.className = 'askwp-fb-card-header';

      var title = document.createElement('span');
      title.className = 'askwp-fb-card-title';
      title.textContent = (field.label || field.name || 'Field') + ' (' + (field.type || 'text') + ')';

      var actions = document.createElement('span');
      actions.className = 'askwp-fb-card-actions';

      if (index > 0) {
        var upBtn = document.createElement('button');
        upBtn.type = 'button';
        upBtn.textContent = '\u2191';
        upBtn.title = 'Move up';
        upBtn.addEventListener('click', function () {
          var tmp = fields[index - 1];
          fields[index - 1] = fields[index];
          fields[index] = tmp;
          sync();
          render();
        });
        actions.appendChild(upBtn);
      }

      if (index < fields.length - 1) {
        var downBtn = document.createElement('button');
        downBtn.type = 'button';
        downBtn.textContent = '\u2193';
        downBtn.title = 'Move down';
        downBtn.addEventListener('click', function () {
          var tmp = fields[index + 1];
          fields[index + 1] = fields[index];
          fields[index] = tmp;
          sync();
          render();
        });
        actions.appendChild(downBtn);
      }

      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'askwp-fb-remove';
      removeBtn.textContent = 'Remove';
      removeBtn.addEventListener('click', function () {
        fields.splice(index, 1);
        sync();
        render();
      });
      actions.appendChild(removeBtn);

      header.appendChild(title);
      header.appendChild(actions);
      card.appendChild(header);

      // Row 1: type + name.
      var row1 = document.createElement('div');
      row1.className = 'askwp-fb-row';

      row1.appendChild(makeSelect('Type', 'type', field.type || 'text', fieldTypes, function (val) {
        field.type = val;
        title.textContent = (field.label || field.name || 'Field') + ' (' + val + ')';
        sync();
        // Re-render to show/hide options field.
        render();
      }));

      row1.appendChild(makeInput('Name', 'name', field.name || '', function (val) {
        field.name = val.replace(/[^a-z0-9_]/gi, '_').toLowerCase();
        sync();
      }));

      card.appendChild(row1);

      // Row 2: label + placeholder.
      var row2 = document.createElement('div');
      row2.className = 'askwp-fb-row';

      row2.appendChild(makeInput('Label', 'label', field.label || '', function (val) {
        field.label = val;
        title.textContent = (val || field.name || 'Field') + ' (' + (field.type || 'text') + ')';
        sync();
      }));

      row2.appendChild(makeInput('Placeholder', 'placeholder', field.placeholder || '', function (val) {
        field.placeholder = val;
        sync();
      }));

      card.appendChild(row2);

      // Row 3: required + maxlength + step.
      var row3 = document.createElement('div');
      row3.className = 'askwp-fb-row askwp-fb-row-3';

      row3.appendChild(makeSelect('Required', 'required', field.required ? 'yes' : 'no', [
        { value: 'yes', label: 'Yes' },
        { value: 'no', label: 'No' }
      ], function (val) {
        field.required = (val === 'yes');
        sync();
      }));

      row3.appendChild(makeInput('Max Length', 'maxlength', String(field.maxlength || 500), function (val) {
        field.maxlength = parseInt(val, 10) || 500;
        sync();
      }, 'number'));

      row3.appendChild(makeInput('Step', 'step', String(field.step || 1), function (val) {
        field.step = parseInt(val, 10) || 1;
        sync();
      }, 'number'));

      card.appendChild(row3);

      // Options (for select type).
      if (field.type === 'select') {
        var optRow = document.createElement('div');
        optRow.className = 'askwp-fb-field';

        var optLabel = document.createElement('label');
        optLabel.textContent = 'Options (comma-separated)';
        optRow.appendChild(optLabel);

        var optInput = document.createElement('input');
        optInput.type = 'text';
        optInput.value = Array.isArray(field.options) ? field.options.join(', ') : (field.options || '');
        optInput.addEventListener('input', function () {
          field.options = optInput.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
          sync();
        });
        optRow.appendChild(optInput);
        card.appendChild(optRow);
      }

      container.appendChild(card);
    });

    sync();
  }

  function makeInput(labelText, key, value, onChange, type) {
    var wrap = document.createElement('div');
    wrap.className = 'askwp-fb-field';

    var label = document.createElement('label');
    label.textContent = labelText;
    wrap.appendChild(label);

    var input = document.createElement('input');
    input.type = type || 'text';
    input.value = value;
    input.addEventListener('input', function () {
      onChange(input.value);
    });
    wrap.appendChild(input);

    return wrap;
  }

  function makeSelect(labelText, key, value, options, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'askwp-fb-field';

    var label = document.createElement('label');
    label.textContent = labelText;
    wrap.appendChild(label);

    var select = document.createElement('select');
    options.forEach(function (opt) {
      var option = document.createElement('option');
      option.value = opt.value;
      option.textContent = opt.label;
      if (opt.value === value) { option.selected = true; }
      select.appendChild(option);
    });
    select.addEventListener('change', function () {
      onChange(select.value);
    });
    wrap.appendChild(select);

    return wrap;
  }

  // Add field button.
  var addBtn = document.getElementById('askwp-fb-add');
  if (addBtn) {
    addBtn.addEventListener('click', function (e) {
      e.preventDefault();
      fields.push({
        type: 'text',
        name: 'field_' + (fields.length + 1),
        label: 'New Field',
        required: false,
        maxlength: 500,
        step: 1
      });
      sync();
      render();
    });
  }

  // Initial render.
  render();
})();
