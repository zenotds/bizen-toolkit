'use strict';
(function ($) {
  $(function () {
    const checkboxSelector = '#wpcf7_codemiror_dark';
    const textareaId = 'wpcf7-form';
    const $textarea = $('#' + textareaId);
    const editorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};

    const codemirrorGen = {
      indentUnit: 4,
      indentWithTabs: true,
      inputStyle: 'contenteditable',
      lineNumbers: true,
      lineWrapping: true,
      matchBrackets: true,
      styleActiveLine: true,
      continueComments: true,
      extraKeys: {
        'Ctrl-Space': 'autocomplete',
        'Ctrl-/': 'toggleComment',
        'Cmd-/': 'toggleComment',
        'Alt-F': 'findPersistent',
        'Ctrl-F': 'findPersistent',
        'Cmd-F': 'findPersistent',
        'Ctrl-D': function (cm) {
          cm.execCommand('duplicateLine');
        },
        'Cmd-D': function (cm) {
          cm.execCommand('duplicateLine');
        },
      },
      direction: 'ltr',
      gutters: ['CodeMirror-lint-markers', 'CodeMirror-linenumbers'],
      mode: 'htmlmixed',
      lint: true,
      autoCloseBrackets: true,
      autoCloseTags: true,
      matchTags: { bothTags: true },
      tabSize: 2,
    };

    let editorHTML = null;

    // Функція ініціалізації CodeMirror з підтримкою теми
    function initEditor(isDark) {
      const finalSettings = Object.assign({}, editorSettings, {
        codemirror: Object.assign({}, editorSettings.codemirror || {}, codemirrorGen, {
          theme: isDark ? 'material' : 'default',
        }),
      });

      return wp.codeEditor.initialize(textareaId, finalSettings);
    }

    // Ініціалізуємо редактор, якщо textarea існує
    if ($textarea.length) {
      const isDark = $(checkboxSelector).is(':checked');
      editorHTML = initEditor(isDark);

      editorHTML.codemirror.on('change', function () {
        document.getElementById(textareaId).value = editorHTML.codemirror.getValue();
      });

      $('#informationdiv_coder').insertAfter('#informationdiv').show();

      // Зміна теми при кліку на чекбокс
      $(checkboxSelector).on('change', function () {
        const newIsDark = $(this).is(':checked');
        const currentValue = editorHTML.codemirror.getValue();

        editorHTML.codemirror.toTextArea();
        editorHTML = initEditor(newIsDark);
        editorHTML.codemirror.setValue(currentValue);

        editorHTML.codemirror.on('change', function () {
          document.getElementById(textareaId).value = editorHTML.codemirror.getValue();
        });
      });

      document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-taggen="insert-tag"], .insert-tag');
        if (!btn) {
          return;
        }

        const dialog = btn.closest('dialog.tag-generator-dialog');
        if (!dialog) {
          return;
        }

        if (!editorHTML || !editorHTML.codemirror) {
          return;
        }

        // Знаходимо значення тега
        const tagInput = dialog.querySelector('[data-tag-part="tag"], .tag');
        const tagValue = tagInput?.value;

        if (!tagValue) {
          return;
        }

        e.preventDefault();
        e.stopPropagation();

        // Вставляємо в CodeMirror
        const cm = editorHTML.codemirror;
        const cursor = cm.getCursor();
        cm.replaceRange(tagValue, cursor);
        cm.focus();
        cm.setCursor({ line: cursor.line, ch: cursor.ch + tagValue.length });

        // Синхронізуємо з textarea
        document.getElementById(textareaId).value = cm.getValue();

        // Закриваємо діалог без значення (щоб CF7 не вставив ще раз)
        dialog.close('');
      }, true); // capture phase - важливо для перехоплення до CF7

      // Слухаємо подію close на всіх діалогах (делегування)
      document.addEventListener('close', function (e) {
        if (!e.target.matches('dialog.tag-generator-dialog')) {
          return;
        }

        if (!editorHTML || !editorHTML.codemirror) {
          return;
        }

        // Синхронізуємо CodeMirror з textarea
        const textareaValue = document.getElementById(textareaId).value;
        const cmValue = editorHTML.codemirror.getValue();
        if (textareaValue !== cmValue) {
          editorHTML.codemirror.setValue(textareaValue);
        }
      }, true);
    }

    function overrideTaggenInsert() {
      if (typeof wpcf7 === 'undefined' || !wpcf7.taggen) {
        return;
      }

      const originalInsert = wpcf7.taggen.insert;

      wpcf7.taggen.insert = function (tag) {
        if (editorHTML && editorHTML.codemirror) {
          const cm = editorHTML.codemirror;
          const cursor = cm.getCursor();
          cm.replaceRange(tag, cursor);
          cm.focus();
          cm.setCursor({ line: cursor.line, ch: cursor.ch + tag.length });
          document.getElementById(textareaId).value = cm.getValue();
          return;
        }
        originalInsert.apply(this, arguments);
      };
    }

    overrideTaggenInsert();
    $(document).on('wpcf7Ready', overrideTaggenInsert);

    // Redirect after submit toggle
    const $redirectCheckbox = $('#wpcf7_redirect_enabled');
    const $redirectWrap = $('#wpcf7-redirect-url-wrap');

    // Initial state on page load
    if ($redirectCheckbox.length && $redirectWrap.length) {
      if (!$redirectCheckbox.is(':checked')) {
        $redirectWrap.hide();
      }

      $redirectCheckbox.on('change', function () {
        if ($(this).is(':checked')) {
          $redirectWrap.slideDown(200);
        } else {
          $redirectWrap.slideUp(200);
          $('#wpcf7-redirect-url').val('');
        }
      });
    }

    // GA/GTM Event toggle
    const $gaCheckbox = $('#wpcf7_ga_event');
    const $gaWrap = $('#wpcf7-ga-event-wrap');

    if ($gaCheckbox.length && $gaWrap.length) {
      if (!$gaCheckbox.is(':checked')) {
        $gaWrap.hide();
      }

      $gaCheckbox.on('change', function () {
        if ($(this).is(':checked')) {
          $gaWrap.slideDown(200);
        } else {
          $gaWrap.slideUp(200);
          $('#wpcf7-ga-event-name').val('');
        }
      });
    }

    // Auto-hide message toggle
    const $autoHideCheckbox = $('#wpcf7_auto_hide_message');
    const $autoHideWrap = $('#wpcf7-auto-hide-wrap');

    if ($autoHideCheckbox.length && $autoHideWrap.length) {
      if (!$autoHideCheckbox.is(':checked')) {
        $autoHideWrap.hide();
      }

      $autoHideCheckbox.on('change', function () {
        if ($(this).is(':checked')) {
          $autoHideWrap.slideDown(200);
        } else {
          $autoHideWrap.slideUp(200);
        }
      });
    }
  });
})(jQuery);