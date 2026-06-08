(function () {
  'use strict';

  var REST_BASE = '';
  var NONCE = '';
  var PDF_URL = '';
  var CTA_URL = '';

  function sanitizeAsciiValue(value) {
    return String(value || '')
      .replace(/\uFEFF|\u200B|\u200C|\u200D|\u2060/g, '')
      .trim()
      .replace(/[^\x20-\x7E]/g, '');
  }

  function readWidgetConfig(root) {
    var cfg = typeof TopinstalLeadWidget !== 'undefined' ? TopinstalLeadWidget : {};
    if (!root || !root.dataset) {
      return cfg;
    }
    if (root.dataset.restBase) {
      cfg.restBase = sanitizeAsciiValue(root.dataset.restBase);
    }
    if (root.dataset.nonce) {
      cfg.nonce = sanitizeAsciiValue(root.dataset.nonce);
    }
    if (root.dataset.pdfUrl) {
      cfg.pdfUrl = root.dataset.pdfUrl;
    }
    if (root.dataset.ctaUrl) {
      cfg.ctaUrl = root.dataset.ctaUrl;
    }
    if (cfg.restBase) {
      cfg.restBase = sanitizeAsciiValue(cfg.restBase);
    }
    if (cfg.nonce) {
      cfg.nonce = sanitizeAsciiValue(cfg.nonce);
    }
    return cfg;
  }

  var BUILDING_OPTIONS = [
    { value: 'wolnostojacy', label: 'Dom wolnostojący' },
    { value: 'blizniak', label: 'Bliźniak' },
    { value: 'szeregowiec', label: 'Szeregowiec' },
    { value: 'inny', label: 'Wielorodzinny' },
  ];

  var STANDARD_OPTIONS = [
    { value: 'przed_2000', label: 'Przed 2000' },
    { value: '2000_2010', label: '2000–2010' },
    { value: 'po_2010', label: 'Po 2010' },
    { value: 'w_budowie', label: 'Nowy / w budowie' },
  ];

  var EMITTER_OPTIONS = [
    { value: 'podlogowka', label: 'Ogrzewanie podłogowe' },
    { value: 'grzejniki', label: 'Grzejniki' },
    { value: 'mieszane', label: 'Układ mieszany' },
  ];

  var INSULATION_QUICK_OPTIONS = [
    { value: 'słabe', label: 'słabo / brak' },
    { value: 'przeciętne', label: 'przeciętnie' },
    { value: 'dobre', label: 'dobrze' },
    { value: 'bardzo dobre', label: 'bardzo dobrze' },
  ];

  var QUICK_CHIPS_BY_FIELD = {
    insulation_level: INSULATION_QUICK_OPTIONS,
    radiators_is_ht: [
      { value: 'tak, stal lub żeliwo', label: 'Tak, stal/żeliwo' },
      { value: 'nie, płytowe lub aluminiowe', label: 'Nie, płytowe/aluminiowe' },
    ],
    has_underfloor_actuators: [
      { value: 'tak', label: 'Tak' },
      { value: 'nie', label: 'Nie' },
    ],
    ventilation_type: [
      { value: 'naturalna', label: 'Naturalna' },
      { value: 'rekuperacja', label: 'Rekuperacja' },
    ],
    obecne_ogrzewanie: [
      { value: 'gaz', label: 'Gaz' },
      { value: 'wegiel', label: 'Węgiel' },
      { value: 'olej', label: 'Olej' },
      { value: 'prad', label: 'Prąd' },
      { value: 'inne', label: 'Inne' },
    ],
    on_corner: [
      { value: 'tak', label: 'Tak' },
      { value: 'nie', label: 'Nie' },
    ],
    dhw_persons: [
      { value: '2-3 osoby', label: '2–3 osoby' },
      { value: '4-5 osób', label: '4–5 osób' },
      { value: 'więcej niż 5', label: 'Więcej niż 5' },
    ],
    dhw_usage: [
      { value: 'raczej oszczędnie', label: 'Raczej oszczędnie' },
      { value: 'standardowo', label: 'Standardowo' },
      { value: 'korzystamy intensywnie', label: 'Korzystamy intensywnie' },
    ],
  };

  function renderQuickChips(field, options, onSelect) {
    var chips = document.createElement('div');
    chips.className = 'tilw-quick-chips';
    options.forEach(function (opt) {
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'tilw-quick-chip';
      chip.textContent = opt.label;
      chip.addEventListener('click', function () {
        onSelect(opt.value);
      });
      chips.appendChild(chip);
    });
    return chips;
  }

  var STEP_TOTAL = 5;

  var STEP_META = [
    { num: '01', label: 'Parametry budynku' },
    { num: '02', label: 'Doprecyzowanie' },
    { num: '03', label: 'Wstępny wynik' },
    { num: '04', label: 'Doprecyzowanie instalacji' },
    { num: '05', label: 'Wynik po doprecyzowaniu' },
  ];

  var PRE_RESULT_REQUIRED_FIELDS = {
    insulation_level: true,
    powierzchnia: true,
    postal_code: true,
    radiators_is_ht: true,
    has_underfloor_actuators: true,
    dhw_persons: true,
    dhw_usage: true,
    ventilation_type: true,
    obecne_ogrzewanie: true,
    on_corner: true,
  };

  function wrapStepPanel(inner) {
    var panel = document.createElement('div');
    panel.className = 'tilw-step-panel';
    if (inner) {
      panel.appendChild(inner);
    }
    requestAnimationFrame(function () {
      panel.classList.add('is-visible');
    });
    return panel;
  }

  function uuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
      return crypto.randomUUID();
    }
    return 'lw-' + Date.now() + '-' + Math.random().toString(16).slice(2);
  }

  function apiPost(path, body) {
    var url = sanitizeAsciiValue(REST_BASE + path);
    var nonce = sanitizeAsciiValue(NONCE);
    if (!url || !nonce) {
      return Promise.reject(new Error('Brak konfiguracji REST. Odśwież stronę lub wyczyść cache.'));
    }
    var headers = new Headers();
    headers.set('Content-Type', 'application/json');
    headers.set('X-WP-Nonce', nonce);
    return fetch(url, {
      method: 'POST',
      headers: headers,
      credentials: 'same-origin',
      body: JSON.stringify(body || {}),
    }).then(function (res) {
      return res.text().then(function (text) {
        var raw = String(text || '').replace(/^\uFEFF/, '').trim();
        var data = null;
        if (raw !== '') {
          try {
            data = JSON.parse(raw);
          } catch (parseErr) {
            var badJson = new Error('Nieprawidłowa odpowiedź serwera (JSON).');
            badJson.status = res.status;
            throw badJson;
          }
        }
        if (!res.ok) {
          var fail = new Error(
            (data && (data.message || data.code)) || 'Request failed (' + res.status + ')'
          );
          fail.status = res.status;
          fail.payload = data;
          throw fail;
        }
        return data || {};
      });
    });
  }

  function formatPln(n) {
    return new Intl.NumberFormat('pl-PL', {
      style: 'currency',
      currency: 'PLN',
      maximumFractionDigits: 0,
    }).format(n);
  }

  function parsePumpKw(model, modelRaw) {
    var s = String(modelRaw || model || '');
    var m = s.match(/\(([\d.,]+)\s*kW\)/i) || s.match(/([\d.,]+)\s*kW/i);
    if (m) {
      return String(m[1]).replace(',', '.');
    }
    return '';
  }

  function formatPumpDisplay(model, modelRaw, collected) {
    var raw = String(modelRaw || model || '').trim();
    var kw = parsePumpKw(model, modelRaw);
    var codeMatch = raw.match(/(?:PANASONIC\s+)?(KIT-[A-Z0-9]+)/i);
    var code = codeMatch ? codeMatch[1].toUpperCase() : '';
    var title = 'Panasonic Aquarea';
    if (/KIT-WC/i.test(raw)) {
      title += ' seria K';
    } else if (/KIT-WH/i.test(raw)) {
      title += ' seria H';
    } else if (/KIT-WZ/i.test(raw)) {
      title += ' seria Z';
    }
    var metaParts = [];
    if (kw) {
      metaParts.push(kw + ' kW');
    }
    metaParts.push('split');
    var areaNote = '';
    if (collected && collected.powierzchnia) {
      areaNote = 'Dobór dla ok. ' + collected.powierzchnia + ' m² ogrzewanej powierzchni';
    }
    return {
      title: title,
      meta: metaParts.join(' • '),
      code: code || raw.replace(/^PANASONIC\s+/i, '').trim(),
      areaNote: areaNote,
    };
  }

  function formatBufferSpec(display, pojemnosc) {
    var d = String(display || '').toUpperCase();
    if (d.indexOf('NIE WYMAGANY') !== -1) {
      return { value: 'Nie wymagany', note: 'Bufor CO' };
    }
    var m = d.match(/(\d+)\s*L/);
    if (m) {
      return { value: m[1] + ' L', note: 'Pojemność dopasowana' };
    }
    return { value: pojemnosc || '—', note: 'Bufor CO' };
  }

  function formatCwuSpec(display, pojemnosc) {
    var d = String(display || '');
    if (!d || d === '—') {
      return { value: '—', note: 'Zasobnik CWU' };
    }
    var m = d.match(/(\d+)\s*L/i);
    if (m) {
      return { value: m[1] + ' L', note: 'Stal nierdzewna' };
    }
    return { value: pojemnosc || d, note: 'Zasobnik CWU' };
  }

  function truncateReason(text, maxLen) {
    text = String(text || '').trim();
    maxLen = maxLen || 96;
    if (text.length <= maxLen) {
      return text;
    }
    return text.slice(0, maxLen - 1).trim() + '…';
  }

  function buildRecommendationReason(collected, data) {
    var assumptions = (data && data.assumptions) || [];
    if (assumptions.length && assumptions[0]) {
      return truncateReason(assumptions[0], 96);
    }
    collected = collected || {};
    var emitter = String(collected.emitter_type || '').toLowerCase();
    var ins = String(collected.insulation_level || '').toLowerCase();
    if (emitter === 'podlogowka') {
      return 'Dobór pod ogrzewanie podłogowe i niskotemperaturową pracę pompy.';
    }
    if (emitter === 'grzejniki' && collected.radiators_is_ht) {
      return 'Grzejniki HT — hydraulika do weryfikacji na miejscu.';
    }
    if (emitter === 'grzejniki' || emitter === 'mieszane') {
      return 'Dobór pod grzejniki — doprecyzuj hydraulikę przed projektem.';
    }
    if (ins === 'dobre' || ins === 'bardzo dobre' || ins === 'bardzo_dobre') {
      return 'Model dla budynku o dobrej izolacji.';
    }
    if (ins === 'słabe' || ins === 'slabe' || ins === 'przeciętne' || ins === 'przecietne') {
      return 'Przy tej izolacji warto rozważyć audyt powłoki przed montażem.';
    }
    return 'Dobór na podstawie parametrów budynku i szacunkowego OZC.';
  }

  function formatSpecsInline(bufSpec, cwuSpec) {
    var parts = [];
    parts.push('Bufor ' + (bufSpec.value || '—'));
    parts.push('CWU ' + (cwuSpec.value || '—'));
    return parts.join(' · ');
  }

  function formatPostalMask(raw) {
    var digits = String(raw || '')
      .replace(/\D/g, '')
      .slice(0, 5);
    if (digits.length <= 2) {
      return digits;
    }
    return digits.slice(0, 2) + '-' + digits.slice(2);
  }

  function isValidPostalCode(value) {
    return /^\d{2}-\d{3}$/.test(String(value || '').trim());
  }

  function attachPostalMaskInput(input) {
    input.placeholder = '00-000';
    input.inputMode = 'numeric';
    input.maxLength = 6;
    input.autocomplete = 'postal-code';
    input.addEventListener('input', function () {
      input.value = formatPostalMask(input.value);
    });
  }

  function closeAllMenus(root) {
    root.querySelectorAll('.tilw-select-menu.is-open').forEach(function (menu) {
      menu.classList.remove('is-open');
    });
  }

  function createDropdown(root, fieldKey, label, options, selectedValue, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'tilw-field';
    wrap.dataset.field = fieldKey;

    var lbl = document.createElement('span');
    lbl.className = 'tilw-label';
    lbl.textContent = label;

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'tilw-select-trigger';
    trigger.textContent = 'Wybierz…';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');

    var menu = document.createElement('div');
    menu.className = 'tilw-select-menu';
    menu.setAttribute('role', 'listbox');

    selectedValue = selectedValue || '';
    if (selectedValue) {
      wrap.classList.add('tilw-dd-has-val');
    }

    options.forEach(function (opt) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'tilw-select-option';
      btn.textContent = opt.label;
      btn.dataset.value = opt.value;
      btn.addEventListener('click', function () {
        selectedValue = opt.value;
        trigger.textContent = opt.label;
        wrap.classList.add('tilw-dd-has-val');
        menu.querySelectorAll('.tilw-select-option').forEach(function (el) {
          el.classList.toggle('is-selected', el.dataset.value === opt.value);
        });
        menu.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
        onChange(selectedValue);
      });
      menu.appendChild(btn);
      if (selectedValue === opt.value) {
        trigger.textContent = opt.label;
        btn.classList.add('is-selected');
      }
    });

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = menu.classList.contains('is-open');
      closeAllMenus(root);
      if (!open) {
        menu.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
      }
    });

    wrap.appendChild(lbl);
    wrap.appendChild(trigger);
    wrap.appendChild(menu);
    return wrap;
  }

  function Widget(root) {
    this.root = root;
    this.step = 1;
    this.sessionId = uuid();
    this.collected = { session_id: this.sessionId, calc_revision: Date.now() };
    this.messages = [];
    this.chatDone = false;
    this.chatIdleTimer = null;
    this.calculating = false;
    this.engagementId = '';
    this.result = null;
    this.refinementActive = false;
    this.refinementAsked = 0;
    this.refinementComplete = false;
    this.previousResult = null;
    this.pendingLabels = [];
    this.chatPendingField = '';
    this.chatHint = '';
    this.chatTurn = null;
    this.chatLoading = false;
    this.chatAnimating = false;
    this.chatInputError = '';
    this.chatIdleNudged = false;
    this.chatNudgeText = '';
    this.refinementIntroShown = false;
    this.step1AutoTimer = null;
    this.step1AutoPending = false;
    this.openMenuHandler = this.onDocClick.bind(this);
    document.addEventListener('click', this.openMenuHandler);
    this.render();
  }

  Widget.prototype.destroy = function () {
    document.removeEventListener('click', this.openMenuHandler);
    this.clearChatIdle();
    this.clearStep1Auto();
  };

  Widget.prototype.onDocClick = function () {
    closeAllMenus(this.root);
  };

  Widget.prototype.clearChatIdle = function () {
    if (this.chatIdleTimer) {
      clearTimeout(this.chatIdleTimer);
      this.chatIdleTimer = null;
    }
  };

  Widget.prototype.clearStep1Auto = function () {
    if (this.step1AutoTimer) {
      clearTimeout(this.step1AutoTimer);
      this.step1AutoTimer = null;
    }
    this.step1AutoPending = false;
  };

  Widget.prototype.beginStep1AutoAdvance = function () {
    var self = this;
    if (this.step !== 1 || !this.isStepAComplete() || this.step1AutoTimer) {
      return;
    }
    this.step1AutoPending = true;
    this.render();
    this.step1AutoTimer = setTimeout(function () {
      self.step1AutoPending = false;
      self.step1AutoTimer = null;
      if (self.step === 1 && self.isStepAComplete()) {
        self.startChat();
      }
    }, 1000);
  };

  Widget.prototype.onStepAFieldChange = function (fieldKey, value) {
    this.collected[fieldKey] = value;
    this.bumpCalcRevision();
    this.clearStep1Auto();
    this.render();
    if (this.isStepAComplete()) {
      this.beginStep1AutoAdvance();
    }
  };

  Widget.prototype.resetChatIdle = function () {
    var self = this;
    this.clearChatIdle();
    if (this.refinementActive) {
      return;
    }
    var last = this.messages.length ? this.messages[this.messages.length - 1] : null;
    if (!last || last.role !== 'assistant') {
      return;
    }
    this.chatIdleTimer = setTimeout(function () {
      if (
        self.step !== 2 ||
        self.chatDone ||
        self.refinementActive ||
        self.chatIdleNudged ||
        self.chatLoading ||
        self.chatAnimating
      ) {
        return;
      }
      self.chatIdleNudged = true;
      self.chatNudgeText =
        'Jeśli potrzebujesz chwili — bez pośpiechu. Gdy będziesz gotowy, odpowiedz na pytanie powyżej; orientacyjny wynik zobaczysz po ostatnim pytaniu lub przycisku „Pomiń pozostałe pytania”.';
      self.pushAssistant(self.chatNudgeText);
      self.render();
      self.resetChatIdle();
    }, 90000);
  };

  Widget.prototype.showChatTurn = function (assistantText, userText) {
    this.chatNudgeText = '';
    this.chatTurn = {
      assistant: String(assistantText || ''),
      user: userText ? String(userText) : null,
    };
    this.chatLoading = false;
    this.chatAnimating = false;
    this.render();
  };

  Widget.prototype.transitionToNextChat = function (afterDone) {
    var self = this;
    var qa = this.root.querySelector('.tilw-chat-qa');
    if (qa) {
      qa.classList.add('is-exiting');
    }
    setTimeout(function () {
      self.chatTurn = null;
      self.chatAnimating = false;
      if (typeof afterDone === 'function') {
        afterDone();
      }
    }, 340);
  };

  Widget.prototype.bumpCalcRevision = function () {
    this.collected.calc_revision = Date.now();
  };

  Widget.prototype.validateChatAnswer = function (text) {
    var trimmed = String(text || '').trim();
    if (!trimmed) {
      return 'Wpisz odpowiedź lub wybierz jedną z opcji powyżej.';
    }
    if (this.chatPendingField === 'postal_code' && !isValidPostalCode(trimmed)) {
      return 'Podaj kod pocztowy w formacie 00-000.';
    }
    if (this.chatPendingField === 'powierzchnia') {
      var area = parseInt(trimmed, 10);
      if (!area || area < 40 || area > 500) {
        return 'Podaj powierzchnię ogrzewaną między 40 a 500 m².';
      }
    }
    if (this.chatPendingField === 'insulation_level' && /^\d+\s*(m2|m²|mkw)?$/i.test(trimmed)) {
      return 'To wygląda jak powierzchnia w m². Wybierz poziom ocieplenia (słabe, przeciętne, dobre lub bardzo dobre).';
    }
    return '';
  };

  Widget.prototype.canSkipCurrentQuestion = function () {
    if (this.refinementActive || this.step === 4) {
      return true;
    }
    return !PRE_RESULT_REQUIRED_FIELDS[this.chatPendingField];
  };

  Widget.prototype.setStep = function (n) {
    if (n !== 1) {
      this.clearStep1Auto();
    }
    this.step = n;
    this.render();
  };

  Widget.prototype.isChatStep = function () {
    return this.step === 2 || this.step === 4;
  };

  Widget.prototype.isResultStep = function () {
    return this.step === 3 || this.step === 5;
  };

  Widget.prototype.padStepNum = function (n) {
    return n < 10 ? '0' + n : String(n);
  };

  Widget.prototype.isStepAComplete = function () {
    return (
      this.collected.typ_budynku &&
      this.collected.standard &&
      this.collected.emitter_type
    );
  };

  Widget.prototype.render = function () {
    var self = this;
    this.root.innerHTML = '';
    this.root.className = 'tilw-root';

    var card = document.createElement('div');
    card.className = 'tilw-card';
    if (this.isResultStep()) {
      card.classList.add('tilw-card--step-3', 'tilw-card--result');
    }

    var header = document.createElement('div');
    header.className = 'tilw-header';
    header.innerHTML =
      '<div class="tilw-header-l">' +
      '<div class="tilw-header-tag"><span class="tilw-pulse"></span>ORIENTACYJNY DOBÓR POMPY CIEPŁA</div>' +
      '<h2 class="tilw-header-title">SPRAWDŹ KOSZT TWOJEJ POMPY</h2>' +
      '<p class="tilw-header-sub">Krótki dobór techniczny — ok. 1 minuty</p>' +
      '</div>' +
      '<div class="tilw-header-r">Wynik<br>w ~60 s</div>';
    card.appendChild(header);

    var progress = document.createElement('div');
    progress.className = 'tilw-progress';
    var track = document.createElement('div');
    track.className = 'tilw-progress-track';
    var fill = document.createElement('div');
    fill.className = 'tilw-progress-fill';
    fill.style.width = Math.round((this.step / STEP_TOTAL) * 100) + '%';
    track.appendChild(fill);
    progress.appendChild(track);
    card.appendChild(progress);

    var body = document.createElement('div');
    body.className = 'tilw-body';

    var stepMeta = STEP_META[this.step - 1];
    var stepLbl = document.createElement('p');
    stepLbl.className = 'tilw-step-lbl';
    stepLbl.innerHTML = '<span>' + stepMeta.num + '</span> — ' + stepMeta.label;
    body.appendChild(stepLbl);

    if (this.step === 1) {
      body.appendChild(wrapStepPanel(this.renderStepA()));
    } else if (this.isChatStep()) {
      body.appendChild(wrapStepPanel(this.renderStepB()));
    } else {
      body.appendChild(wrapStepPanel(this.renderStepC()));
    }

    card.appendChild(body);
    this.root.appendChild(card);
  };

  Widget.prototype.renderStepA = function () {
    var self = this;
    var frag = document.createDocumentFragment();
    var grid = document.createElement('div');
    grid.className = 'tilw-grid tilw-grid--step-a';

    grid.appendChild(
      createDropdown(
        this.root,
        'typ_budynku',
        'Typ budynku',
        BUILDING_OPTIONS,
        this.collected.typ_budynku,
        function (v) {
          self.onStepAFieldChange('typ_budynku', v);
        }
      )
    );
    grid.appendChild(
      createDropdown(
        this.root,
        'standard',
        'Rok budowy',
        STANDARD_OPTIONS,
        this.collected.standard,
        function (v) {
          self.onStepAFieldChange('standard', v);
        }
      )
    );
    grid.appendChild(
      createDropdown(
        this.root,
        'emitter_type',
        'Typ ogrzewania w domu',
        EMITTER_OPTIONS,
        this.collected.emitter_type,
        function (v) {
          self.onStepAFieldChange('emitter_type', v);
        }
      )
    );

    frag.appendChild(grid);

    var autoStatus = document.createElement('p');
    autoStatus.className = 'tilw-step-a-auto';
    autoStatus.textContent = this.step1AutoPending ? 'Przechodzę do doprecyzowania…' : '';
    frag.appendChild(autoStatus);

    return frag;
  };

  Widget.prototype.startChat = function () {
    var self = this;
    this.clearStep1Auto();
    this.refinementActive = false;
    this.refinementAsked = 0;
    this.collected.refinement_active = false;
    this.chatIdleNudged = false;
    this.chatNudgeText = '';
    this.refinementIntroShown = false;
    this.chatInputError = '';
    this.setStep(2);
    this.messages = [];
    this.chatDone = false;
    this.chatPendingField = '';
    this.chatHint = '';
    this.chatTurn = null;
    this.chatLoading = true;
    this.requestChat();
    this.resetChatIdle();
  };

  Widget.prototype.startRefinement = function () {
    var self = this;
    this.previousResult = this.result;
    if (this.result && this.result.bufor_display) {
      this.collected.last_bufor_display = this.result.bufor_display;
    }
    this.collected.calc_revision = Date.now();
    this.refinementActive = true;
    this.refinementIntroShown = false;
    this.refinementAsked = 0;
    this.chatDone = false;
    this.collected.refinement_active = true;
    this.messages = [];
    this.chatPendingField = '';
    this.chatHint = '';
    this.chatIdleNudged = false;
    this.chatNudgeText = '';
    this.chatInputError = '';
    this.chatTurn = null;
    this.chatLoading = true;
    this.clearChatIdle();
    this.refinementComplete = false;
    this.setStep(4);
    this.requestChat();
  };

  Widget.prototype.chatPayload = function () {
    return {
      messages: this.messages,
      collected: this.collected,
      refinement_mode: this.refinementActive,
      refinement_asked: this.refinementAsked,
    };
  };

  Widget.prototype.applyChatMeta = function (data) {
    this.chatPendingField = (data && data.pending_field) || '';
    this.chatHint = (data && data.hint) || '';
    this.pendingLabels = (data && data.pending_labels) || [];
  };

  Widget.prototype.renderChatBubble = function (role, text) {
    var row = document.createElement('div');
    row.className = 'tilw-bbl ' + (role === 'user' ? 'is-me' : 'is-ai');
    if (role === 'assistant') {
      var av = document.createElement('div');
      av.className = 'tilw-av';
      av.textContent = 'OZC';
      row.appendChild(av);
    }
    var bt = document.createElement('div');
    bt.className = 'tilw-bt';
    bt.textContent = text;
    row.appendChild(bt);
    return row;
  };

  Widget.prototype.renderStepB = function () {
    var self = this;
    var frag = document.createDocumentFragment();

    var expertZone = document.createElement('div');
    expertZone.className = 'tilw-expert-zone';
    var expertLbl = document.createElement('p');
    expertLbl.className = 'tilw-expert-zone-label';
    expertLbl.textContent =
      this.step === 4 ? 'Doprecyzowanie instalacji' : 'Doprecyzowanie techniczne';
    expertZone.appendChild(expertLbl);

    var stage = document.createElement('div');
    stage.className = 'tilw-chat-stage';
    stage.id = 'tilw-chat-stage';

    if (this.chatLoading) {
      stage.classList.add('is-loading');
      var loading = document.createElement('div');
      loading.className = 'tilw-chat-stage-loading';
      loading.innerHTML =
        '<span class="tilw-chat-dots" aria-hidden="true"><span></span><span></span><span></span></span>';
      stage.appendChild(loading);
    } else if (this.chatTurn && this.chatTurn.assistant) {
      var qa = document.createElement('div');
      qa.className = 'tilw-chat-qa';
      if (this.chatTurn.user) {
        qa.classList.add('has-user');
      }
      if (this.chatAnimating) {
        qa.classList.add('is-answered');
      }
      qa.appendChild(this.renderChatBubble('assistant', this.chatTurn.assistant));
      if (this.chatTurn.user) {
        qa.appendChild(this.renderChatBubble('user', this.chatTurn.user));
      }
      if (this.chatNudgeText) {
        var nudgeBubble = this.renderChatBubble('assistant', this.chatNudgeText);
        nudgeBubble.classList.add('tilw-bbl-nudge');
        qa.appendChild(nudgeBubble);
      }
      stage.appendChild(qa);
      requestAnimationFrame(function () {
        qa.classList.add('is-visible');
      });
    }
    expertZone.appendChild(stage);

    if (!this.chatDone && !this.chatLoading && !this.chatAnimating) {
      var chipOptions = QUICK_CHIPS_BY_FIELD[this.chatPendingField];
      var chipOnly = chipOptions && chipOptions.length > 0;
      if (chipOnly) {
        expertZone.appendChild(
          renderQuickChips(this.chatPendingField, chipOptions, function (value) {
            self.onUserMessage(value);
          })
        );
      }

      if (this.chatInputError) {
        var errEl = document.createElement('p');
        errEl.className = 'tilw-chat-input-error';
        errEl.textContent = this.chatInputError;
        expertZone.appendChild(errEl);
      }

      if (!chipOnly) {
        var row = document.createElement('div');
        row.className = 'tilw-chat-input-row';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'tilw-chat-input';
        input.placeholder =
          this.chatPendingField === 'postal_code' ? '00-000' : 'Wpisz odpowiedź…';
        if (this.chatPendingField === 'postal_code') {
          attachPostalMaskInput(input);
        }
        if (this.chatPendingField === 'powierzchnia') {
          input.inputMode = 'numeric';
          input.placeholder = 'np. 120';
        }
        var send = document.createElement('button');
        send.type = 'button';
        send.className = 'tilw-ci-send';
        send.setAttribute('aria-label', 'Wyślij');
        send.textContent = '↑';
        var submit = function () {
          var text =
            self.chatPendingField === 'postal_code'
              ? formatPostalMask(input.value)
              : input.value.trim();
          var err = self.validateChatAnswer(text);
          if (err) {
            self.chatInputError = err;
            self.render();
            return;
          }
          input.value = '';
          self.onUserMessage(text);
        };
        send.addEventListener('click', submit);
        input.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') submit();
        });
        input.addEventListener('input', function () {
          if (self.chatInputError) {
            self.chatInputError = '';
          }
        });
        row.appendChild(input);
        row.appendChild(send);
        expertZone.appendChild(row);
      }

      if (this.canSkipCurrentQuestion()) {
        var skip = document.createElement('button');
        skip.type = 'button';
        skip.className = 'tilw-skip-q';
        skip.textContent = 'Pomiń to pytanie';
        skip.addEventListener('click', function () {
          self.skipCurrentQuestion();
        });
        expertZone.appendChild(skip);
      }
    }

    frag.appendChild(expertZone);

    if (!this.chatDone) {
      if (!this.refinementActive) {
        var skipNote = document.createElement('p');
        skipNote.className = 'tilw-skip-note';
        skipNote.textContent =
          'Możesz pominąć pozostałe pytania — wynik wtedy opiera się na założeniach.';
        frag.appendChild(skipNote);

        var skipAll = document.createElement('button');
        skipAll.type = 'button';
        skipAll.className = 'tilw-btn tilw-btn-line';
        skipAll.textContent = 'Pomiń pozostałe pytania — pokaż wynik';
        skipAll.addEventListener('click', function () {
          self.goToCalculate();
        });
        frag.appendChild(skipAll);
      }
    }

    var nav = document.createElement('div');
    nav.className = 'tilw-nav';
    var back = document.createElement('button');
    back.type = 'button';
    back.className = 'tilw-bk';
    if (this.step === 4) {
      back.textContent = 'Wróć do wyniku';
      back.addEventListener('click', function () {
        self.clearChatIdle();
        self.refinementActive = false;
        self.collected.refinement_active = false;
        self.setStep(3);
      });
    } else {
      back.textContent = 'Wróć do parametrów';
      back.addEventListener('click', function () {
        self.clearChatIdle();
        self.setStep(1);
      });
    }
    var sc = document.createElement('span');
    sc.className = 'tilw-sc';
    sc.textContent =
      self.padStepNum(self.step) + ' / ' + self.padStepNum(STEP_TOTAL);
    nav.appendChild(back);
    nav.appendChild(sc);
    frag.appendChild(nav);

    return frag;
  };

  Widget.prototype.pushAssistant = function (text) {
    this.messages.push({ role: 'assistant', content: text });
  };

  Widget.prototype.pushUser = function (text) {
    this.messages.push({ role: 'user', content: text });
  };

  Widget.prototype.onUserMessage = function (text) {
    var self = this;
    if (this.chatAnimating || this.chatLoading || this.chatDone) {
      return;
    }
    var err = this.validateChatAnswer(text);
    if (err) {
      this.chatInputError = err;
      this.render();
      return;
    }
    this.chatInputError = '';
    var normalized =
      this.chatPendingField === 'postal_code' ? formatPostalMask(text) : String(text).trim();
    var assistantText =
      this.chatTurn && this.chatTurn.assistant
        ? this.chatTurn.assistant
        : this.lastAssistantMessage() || '';
    this.chatTurn = { assistant: assistantText, user: normalized };
    this.chatAnimating = true;
    this.pushUser(normalized);
    this.resetChatIdle();
    this.render();

    setTimeout(function () {
      self.transitionToNextChat(function () {
        self.chatLoading = true;
        self.render();
        self.requestChat();
      });
    }, 480);
  };

  Widget.prototype.lastAssistantMessage = function () {
    for (var i = this.messages.length - 1; i >= 0; i--) {
      if (this.messages[i].role === 'assistant') {
        return this.messages[i].content;
      }
    }
    return '';
  };

  Widget.prototype.skipCurrentQuestion = function () {
    var self = this;
    if (this.chatAnimating || this.chatLoading) {
      return;
    }
    this.resetChatIdle();
    this.transitionToNextChat(function () {
      self.chatLoading = true;
      self.render();
      apiPost('/chat', Object.assign(self.chatPayload(), { skip_current: true }))
        .then(function (data) {
          if (data.collected && typeof data.collected === 'object') {
            Object.assign(self.collected, data.collected);
          }
          self.applyChatMeta(data);
          if (data.done) {
            self.finishChatRound();
            return;
          }
          if (data.message) {
            self.pushAssistant(data.message);
          }
          self.showChatTurn(data.message || self.lastAssistantMessage(), null);
          self.resetChatIdle();
        })
        .catch(function () {
          self.finishChatRound();
        });
    });
  };

  Widget.prototype.finishChatRound = function () {
    var self = this;
    this.chatDone = true;
    this.clearChatIdle();
    if (this.refinementActive) {
      this.refinementComplete = true;
      this.collected.refinement_complete = true;
      this.collected.refinement_active = false;
      this.refinementActive = false;
    }
    setTimeout(function () {
      self.goToCalculate();
    }, 600);
  };

  Widget.prototype.requestChat = function () {
    var self = this;
    apiPost('/chat', this.chatPayload())
      .then(function (data) {
        if (data.collected && typeof data.collected === 'object') {
          Object.assign(self.collected, data.collected);
        }
        self.applyChatMeta(data);
        if (data.message) {
          self.pushAssistant(data.message);
          if (self.refinementActive) {
            self.refinementAsked += 1;
          }
        }
        var displayMsg = data.message || self.lastAssistantMessage();
        if (self.refinementActive && !self.refinementIntroShown && displayMsg) {
          self.refinementIntroShown = true;
          displayMsg =
            'Uzupełnimy do 4 informacji — w tym o grzejnikach, jeśli dotyczą Twojej instalacji. ' +
            displayMsg;
          if (self.messages.length) {
            self.messages[self.messages.length - 1].content = displayMsg;
          }
        }
        if (data.done) {
          self.chatLoading = false;
          if (displayMsg) {
            self.showChatTurn(displayMsg, null);
            setTimeout(function () {
              self.finishChatRound();
            }, 900);
          } else {
            self.finishChatRound();
          }
          return;
        }
        self.showChatTurn(displayMsg, null);
        self.resetChatIdle();
      })
      .catch(function (err) {
        self.chatLoading = false;
        self.showError(self.formatApiError(err, 'Nie udało się uruchomić czatu. Sprawdź połączenie i odśwież stronę.'));
      });
  };

  Widget.prototype.formatApiError = function (err, fallback) {
    var msg = (err && err.message) || '';
    if (/65279|ByteString|FEFF/i.test(msg)) {
      return 'Błąd konfiguracji (nieprawidłowy token). Wgraj wtyczkę v0.3.6+ i wyczyść cache WP/Elementor.';
    }
    return msg || fallback;
  };

  Widget.prototype.goToCalculate = function () {
    var self = this;
    if (this.calculating) {
      return;
    }
    this.calculating = true;
    this.clearChatIdle();
    var resultStep = this.refinementComplete && this.result ? 5 : 3;
    this.setStep(resultStep);
    this.showLoading();

    if (this.refinementComplete) {
      this.collected.refinement_complete = true;
    }
    if (this.result && this.result.bufor_display) {
      this.collected.last_bufor_display = this.result.bufor_display;
    }
    apiPost('/calculate', { collected: this.collected })
      .then(function (data) {
        self.calculating = false;
        var prev = self.previousResult;
        self.result = data;
        if (data.bufor_display) {
          self.collected.last_bufor_display = data.bufor_display;
        }
        if (prev) {
          data._delta = self.buildResultDelta(prev, data);
          self.previousResult = null;
        }
        self.pendingLabels = data.pending_labels || [];
        if (data.engagement_id) {
          self.engagementId = data.engagement_id;
        }
        self.renderResult(data);
        if (data.engagement_id) {
          return;
        }
        return apiPost('/register', {
          collected: self.collected,
          result_summary: data,
          traceId: data.traceId || '',
        })
          .then(function (reg) {
            if (reg && reg.engagement_id) {
              self.engagementId = reg.engagement_id;
              self.renderResult(self.result);
            }
          })
          .catch(function () {
            /* rejestracja Node B jest best-effort — wynik zostaje na ekranie */
          });
      })
      .catch(function (err) {
        self.calculating = false;
        self.showError(self.formatApiError(err, 'Nie udało się policzyć wstępnego doboru.'));
      });
  };

  Widget.prototype.showLoading = function () {
    var body = this.root.querySelector('.tilw-body');
    if (!body) return;
    var msg =
      this.step === 5
        ? 'Aktualizuję dobór po doprecyzowaniu…'
        : 'Przygotowuję wstępny dobór…';
    body.innerHTML =
      '<div class="tilw-state"><div class="tilw-spinner"></div>' + msg + '</div>';
  };

  Widget.prototype.showError = function (msg) {
    var body = this.root.querySelector('.tilw-body');
    if (!body) return;
    body.innerHTML =
      '<div class="tilw-state tilw-error">' +
      msg +
      '</div><div class="tilw-actions"><button type="button" class="tilw-btn tilw-btn-ghost tilw-retry">Spróbuj ponownie</button></div>';
    var self = this;
    body.querySelector('.tilw-retry').addEventListener('click', function () {
      if (self.isChatStep() && !self.chatDone) {
        self.requestChat();
      } else {
        self.goToCalculate();
      }
    });
  };

  Widget.prototype.buildResultDelta = function (prev, next) {
    var parts = [];
    if (prev.model && next.model && prev.model !== next.model) {
      parts.push('pompa ' + prev.model + ' → ' + next.model);
    }
    var prevBuf = prev.bufor_display || prev.bufor_pojemnosc || '';
    var nextBuf = next.bufor_display || next.bufor_pojemnosc || '';
    if (prevBuf && nextBuf && prevBuf !== nextBuf) {
      parts.push('Bufor: ' + prevBuf + ' → ' + nextBuf);
    }
    if (!parts.length) {
      return '';
    }
    return 'Po uzupełnieniu: ' + parts.join(' · ');
  };

  Widget.prototype.submitLeadEmail = function (email) {
    var self = this;
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      return Promise.reject(new Error('Podaj poprawny adres e-mail.'));
    }
    self.collected.contact_email = email;
    return apiPost('/register', {
      collected: self.collected,
      result_summary: self.result || {},
      traceId: (self.result && self.result.traceId) || '',
    }).then(function (reg) {
      if (reg && reg.engagement_id) {
        self.engagementId = reg.engagement_id;
      }
      return reg;
    });
  };

  Widget.prototype.renderStepC = function () {
    var frag = document.createDocumentFragment();
    if (!this.result) {
      var loading = document.createElement('div');
      loading.className = 'tilw-state';
      loading.innerHTML = '<div class="tilw-spinner"></div>Ładowanie wyniku…';
      frag.appendChild(loading);
      return frag;
    }
    return this.buildResultFragment(this.result);
  };

  Widget.prototype.renderResult = function (data) {
    var body = this.root.querySelector('.tilw-body');
    if (!body) return;
    var stepLbl = body.querySelector('.tilw-step-lbl');
    body.innerHTML = '';
    if (stepLbl) {
      body.appendChild(stepLbl);
    } else {
      var stepMeta = STEP_META[this.step - 1] || STEP_META[2];
      stepLbl = document.createElement('p');
      stepLbl.className = 'tilw-step-lbl';
      stepLbl.innerHTML = '<span>' + stepMeta.num + '</span> — ' + stepMeta.label;
      body.appendChild(stepLbl);
    }
    body.appendChild(wrapStepPanel(this.buildResultFragment(data)));
  };

  Widget.prototype.buildResultFragment = function (data) {
    var self = this;
    var frag = document.createDocumentFragment();
    var pump = formatPumpDisplay(data.model, data.model_raw, this.collected);
    var bufSpec = formatBufferSpec(data.bufor_display, data.bufor_pojemnosc);
    var cwuSpec = formatCwuSpec(data.cwu_display, data.cwu_pojemnosc);
    var min = data.cena_min || 0;
    var max = data.cena_max || 0;
    var priceText =
      min > 0 && max > 0 ? formatPln(min) + ' – ' + formatPln(max) : 'Po audycie';

    var compact = document.createElement('div');
    compact.className = 'tilw-result-compact';

    var top = document.createElement('div');
    top.className = 'tilw-rc-top';
    top.innerHTML =
      '<div class="tilw-rc-pump">' +
      '<div class="tilw-pump-title">' +
      escapeHtml(pump.title) +
      '</div>' +
      '<div class="tilw-pump-meta">' +
      escapeHtml(pump.meta || '—') +
      (pump.code ? ' · <span class="tilw-pump-code">' + escapeHtml(pump.code) + '</span>' : '') +
      '</div>' +
      '</div>' +
      '<div class="tilw-rc-price">' +
      '<span class="tilw-tech-label">Szacunek</span>' +
      '<span class="tilw-price-value">' +
      escapeHtml(priceText) +
      '</span>' +
      '</div>';
    compact.appendChild(top);

    var specsLine = document.createElement('p');
    specsLine.className = 'tilw-rc-specs';
    specsLine.textContent = formatSpecsInline(bufSpec, cwuSpec);
    compact.appendChild(specsLine);

    if (data._delta) {
      var deltaEl = document.createElement('p');
      deltaEl.className = 'tilw-result-delta';
      deltaEl.textContent = truncateReason(data._delta, 120);
      compact.appendChild(deltaEl);
    }

    frag.appendChild(compact);

    var emailWrap = document.createElement('div');
    emailWrap.className = 'tilw-email-compact';
    var emailHint = document.createElement('p');
    emailHint.className = 'tilw-email-hint';
    emailHint.textContent =
      'Precyzyjną wycenę z przyjemnością prześlemy na Państwa e-mail.';
    var emailRow = document.createElement('div');
    emailRow.className = 'tilw-email-row';
    var emailInput = document.createElement('input');
    emailInput.type = 'email';
    emailInput.className = 'tilw-email-input';
    emailInput.placeholder = 'E-mail (opcjonalnie)';
    emailInput.autocomplete = 'email';
    var emailBtn = document.createElement('button');
    emailBtn.type = 'button';
    emailBtn.className = 'tilw-btn tilw-btn-line tilw-btn-compact';
    emailBtn.textContent = 'Wyślij';
    var emailStatus = document.createElement('span');
    emailStatus.className = 'tilw-email-status';
    emailBtn.addEventListener('click', function () {
      emailBtn.disabled = true;
      emailStatus.textContent = '';
      self
        .submitLeadEmail(emailInput.value.trim())
        .then(function (reg) {
          if (reg && reg.offer_delivered) {
            emailStatus.textContent = 'Oferta wysłana na e-mail';
          } else if (reg && reg.offer_error) {
            emailStatus.textContent = 'Zapisano — oferta w przygotowaniu';
          } else {
            emailStatus.textContent = 'Wysłano';
          }
          emailBtn.textContent = '✓';
        })
        .catch(function (err) {
          emailBtn.disabled = false;
          emailStatus.textContent = (err && err.message) || 'Błąd';
        });
    });
    emailRow.appendChild(emailInput);
    emailRow.appendChild(emailBtn);
    emailRow.appendChild(emailStatus);
    emailWrap.appendChild(emailHint);
    emailWrap.appendChild(emailRow);
    frag.appendChild(emailWrap);

    var nav = document.createElement('div');
    nav.className = 'tilw-nav';
    var back = document.createElement('button');
    back.type = 'button';
    back.className = 'tilw-bk';
    back.textContent = 'Nowy dobór';
    back.addEventListener('click', function () {
      self.clearChatIdle();
      self.clearStep1Auto();
      self.step = 1;
      self.collected = { session_id: self.sessionId, calc_revision: Date.now() };
      self.messages = [];
      self.chatDone = false;
      self.result = null;
      self.refinementActive = false;
      self.refinementComplete = false;
      self.refinementAsked = 0;
      self.previousResult = null;
      self.render();
    });
    var doneTag = document.createElement('span');
    doneTag.className = 'tilw-done-tag';
    doneTag.textContent = self.step === 5 ? 'Wynik zaktualizowany' : 'Wynik gotowy';
    nav.appendChild(back);
    nav.appendChild(doneTag);
    frag.appendChild(nav);
    return frag;
  };

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function boot() {
    var root = document.getElementById('topinstal-lead-widget-root');
    if (!root) {
      return;
    }

    var cfg = readWidgetConfig(root);
    REST_BASE = sanitizeAsciiValue((cfg.restBase || '').replace(/\/$/, ''));
    NONCE = sanitizeAsciiValue(cfg.nonce || '');
    PDF_URL = sanitizeAsciiValue(cfg.pdfUrl || '');
    CTA_URL = sanitizeAsciiValue(cfg.ctaUrl || '');

    if (!REST_BASE || !NONCE) {
      root.innerHTML =
        '<div class="tilw-card"><div class="tilw-body"><p class="tilw-state tilw-error">' +
        'Widget nie załadował skryptów. Użyj widżetu Shortcode (nie HTML) i wyczyść cache Elementora.' +
        '</p></div></div>';
      return;
    }

    new Widget(root);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
