'use strict';
(function ($) {
  $(function () {
    const checkboxSelector = '#wpcf7_codemiror_dark';
    const textareaId = 'wpcf7-form';
    const $textarea = $('#' + textareaId);
    // False when the user turned syntax highlighting off in their profile.
    const codeEditorEnabled =
      window.bizenCf7HtmlEditor && window.bizenCf7HtmlEditor.codeEditor && window.wp && wp.codeEditor;
    const editorSettings = codeEditorEnabled && wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};

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

    function initEditor(isDark) {
      const finalSettings = Object.assign({}, editorSettings, {
        codemirror: Object.assign({}, editorSettings.codemirror || {}, codemirrorGen, {
          theme: isDark ? 'material' : 'default',
        }),
      });

      const instance = wp.codeEditor.initialize(textareaId, finalSettings);
      const textarea = document.getElementById(textareaId);
      let dirty = false;

      // Keep the textarea current: CF7 posts it and checks it for unsaved changes.
      instance.codemirror.on('change', function () {
        textarea.value = instance.codemirror.getValue();
        dirty = true;
      });

      // CF7 runs its live config check on the textarea's change event,
      // which never fires while CodeMirror owns the input.
      instance.codemirror.on('blur', function () {
        if (dirty) {
          dirty = false;
          textarea.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });

      return instance;
    }

    if ($textarea.length && codeEditorEnabled) {
      editorHTML = initEditor($(checkboxSelector).is(':checked'));

      $(checkboxSelector).on('change', function () {
        const currentValue = editorHTML.codemirror.getValue();

        editorHTML.codemirror.toTextArea();
        editorHTML = initEditor($(this).is(':checked'));
        editorHTML.codemirror.setValue(currentValue);
      });

      // Tag generator: insert at the CodeMirror cursor. Capture phase, so this
      // runs before CF7's own click handler on the button.
      document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-taggen="insert-tag"], .insert-tag');
        if (!btn) {
          return;
        }

        const dialog = btn.closest('dialog.tag-generator-dialog');
        if (!dialog || !editorHTML || !editorHTML.codemirror) {
          return;
        }

        const tagInput = dialog.querySelector('[data-tag-part="tag"], .tag');
        const tagValue = tagInput?.value;

        if (!tagValue) {
          return;
        }

        e.preventDefault();
        e.stopPropagation();

        const cm = editorHTML.codemirror;
        const cursor = cm.getCursor();
        cm.replaceRange(tagValue, cursor);
        cm.focus();
        cm.setCursor({ line: cursor.line, ch: cursor.ch + tagValue.length });

        document.getElementById(textareaId).value = cm.getValue();

        // Close with an empty value so CF7 does not insert the tag a second time.
        dialog.close('');
      }, true);

      // If CF7 still wrote into the textarea, bring CodeMirror in line.
      document.addEventListener('close', function (e) {
        if (!e.target.matches('dialog.tag-generator-dialog')) {
          return;
        }

        if (!editorHTML || !editorHTML.codemirror) {
          return;
        }

        const textareaValue = document.getElementById(textareaId).value;
        if (textareaValue !== editorHTML.codemirror.getValue()) {
          editorHTML.codemirror.setValue(textareaValue);
        }
      }, true);
    }

    // Legacy tag generator (CF7 < 6.0).
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