/* Library — control panel behaviour. Plain script, no build step. */
(function () {
  'use strict';

  /**
   * The collection picker in the asset edit sidebar.
   *
   * Saves on change rather than waiting for the asset's own Save button: the panel is not part of
   * the element's form, so an editor who ticks a collection and then navigates away would otherwise
   * lose it with no indication that anything was unsaved.
   */
  function initAssetPanel(panel) {
    if (panel.dataset.libraryInit === '1') {
      return;
    }
    panel.dataset.libraryInit = '1';

    var assetId = panel.dataset.assetId;
    var status = panel.querySelector('[data-library-status]');
    var boxes = panel.querySelectorAll('input[type="checkbox"][data-collection-id]');
    var pending = null;

    function collect() {
      var ids = [];
      boxes.forEach(function (box) {
        if (box.checked) {
          ids.push(box.dataset.collectionId);
        }
      });
      return ids;
    }

    function say(message, isError) {
      if (!status) {
        return;
      }
      status.textContent = message;
      status.classList.toggle('error', !!isError);
    }

    function save() {
      var ids = collect();
      say(Craft.t('library', 'Saving…'), false);

      // One in-flight request at a time. Ticking three boxes quickly used to race, and whichever
      // response landed last won — which was not necessarily the last state the editor chose.
      if (pending) {
        pending.abort();
      }

      pending = new AbortController();

      var data = { assetId: assetId, collectionIds: ids };

      Craft.sendActionRequest('POST', 'library/membership/save-for-asset', {
        data: data,
        signal: pending.signal,
      })
        .then(function () {
          pending = null;
          say(Craft.t('library', 'Saved'), false);
          window.setTimeout(function () {
            if (!pending) {
              say('', false);
            }
          }, 2000);
        })
        .catch(function (error) {
          if (error && error.name === 'CanceledError') {
            return;
          }
          pending = null;
          say(Craft.t('library', 'Couldn’t save. Your change was not kept.'), true);
        });
    }

    boxes.forEach(function (box) {
      box.addEventListener('change', save);
    });
  }

  /**
   * The hashing loop on the audit screen.
   *
   * Hashing reads every candidate file, so it runs a batch at a time from the browser rather than
   * in one request that a big library would time out. Stops on the server's `finished`, not on
   * `pending === 0`: an unreadable file leaves the pending count stuck above zero forever.
   */
  function initHashRunner(button) {
    if (button.dataset.libraryInit === '1') {
      return;
    }
    button.dataset.libraryInit = '1';

    var progress = document.querySelector('[data-library-hash-progress]');
    var total = parseInt(button.dataset.pending || '0', 10);
    var done = 0;
    var running = false;

    function report(text) {
      if (progress) {
        progress.textContent = text;
      }
    }

    function batch() {
      Craft.sendActionRequest('POST', 'library/audit/hash-batch', {})
        .then(function (response) {
          var data = response.data || {};
          done += data.hashed || 0;

          report(
            Craft.t('library', 'Hashed {done} of about {total}…', {
              done: done,
              total: Math.max(total, done),
            })
          );

          if (data.finished) {
            running = false;
            button.classList.remove('loading');
            button.disabled = false;
            report(Craft.t('library', 'Done. Reloading…'));
            window.location.reload();
            return;
          }

          batch();
        })
        .catch(function () {
          running = false;
          button.classList.remove('loading');
          button.disabled = false;
          report(Craft.t('library', 'Hashing stopped because of an error. Check the logs.'));
        });
    }

    button.addEventListener('click', function (event) {
      event.preventDefault();

      if (running) {
        return;
      }

      running = true;
      button.classList.add('loading');
      button.disabled = true;
      batch();
    });
  }


  /**
   * Wires up one of Library's bulk-action triggers.
   *
   * Two things about Craft's element index make the obvious approach fail, and both are the reason
   * this helper exists rather than a few lines inline in each template:
   *
   * 1. Garnish moves a `.menu` out of its container and reparents it onto <body> when the menu
   *    button is initialised. Anything that looks for the fields with `$trigger.find(...)` finds
   *    nothing from that point on. So the panel is found by a document-wide data attribute instead.
   *
   * 2. Craft binds its `activate` callback to *every* `button, .btn` inside the trigger — which
   *    includes the menu button itself, so merely opening the menu would fire the action. The
   *    trigger is therefore created without an `activate` callback, and the Apply button is bound
   *    here, delegated from the document so reparenting cannot break it.
   *
   * Craft also strips the `name` attribute off every input in a trigger after snapshotting its
   * initial value, so the fields are read by data attribute and passed to submitAction() by hand.
   */
  function actionTrigger(options) {
    const type = options.type;
    const triggerId = options.triggerId;

    const trigger = new Craft.ElementActionTrigger({
      type: type,
      triggerId: triggerId,
      bulk: true,
    });

    const panelSelector = '[data-library-panel="' + triggerId + '"]';
    const menuSelector = '[id="library-menu-' + triggerId + '"]';

    // The disclosure menu that belongs to *this* trigger. Garnish reparents it onto <body> and
    // never removes it when the trigger is destroyed, so after a source switch there can be several
    // panels with the same id on the page, and only the live trigger knows which one is current.
    function liveMenu() {
      return trigger.$trigger.find('[data-disclosure-trigger]').data('disclosureMenu') || null;
    }

    // Drops menus left behind by triggers Craft has already torn down: they carry duplicate ids
    // (which breaks label/for) and would otherwise be read instead of the current panel.
    function removeOrphans() {
      const live = liveMenu();
      $(menuSelector).each(function () {
        const menu = $(this).data('disclosureMenu');
        const orphaned = live
          ? menu !== live
          : menu && !document.body.contains(menu.$trigger[0]);
        if (orphaned) {
          $(this).remove();
        }
      });
    }

    removeOrphans();

    // Namespaced and unbound first: Craft re-renders the triggers (and re-runs this) on every view
    // update, and a plain .on() would stack one handler per source switch — so a single click on
    // Apply ran the action once for every time the editor had changed source.
    const eventName = 'click.library-' + triggerId;

    $(document)
      .off(eventName)
      .on(eventName, '[data-library-apply="' + triggerId + '"]', function (event) {
        event.preventDefault();

        removeOrphans();

        const menu = liveMenu();
        const $panel = menu ? menu.$container.find(panelSelector) : $(panelSelector).last();
        const params = options.gather($panel);

        if (params === false) {
          return;
        }

        // Close the menu before the index reloads underneath it.
        if (menu) {
          menu.hide();
        }

        trigger.elementIndex.submitAction(type, params);
      });

    return trigger;
  }

  window.Craft = window.Craft || {};
  Craft.LibraryActionTrigger = actionTrigger;

  function boot(root) {
    (root || document).querySelectorAll('[data-library-asset-panel]').forEach(initAssetPanel);
    (root || document).querySelectorAll('[data-library-hash-run]').forEach(initHashRunner);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      boot(document);
    });
  } else {
    boot(document);
  }

  // An asset opened from an index arrives after this script has run — as a full page in most
  // flows, but in a slideout in others. Guarded on Craft.Slideout existing: this file is loaded on
  // element index pages too, and a missing class here would throw before anything else ran.
  if (window.Garnish && window.Craft && Craft.Slideout) {
    Garnish.on(Craft.Slideout, 'open', function (event) {
      if (event.target && event.target.$container) {
        boot(event.target.$container[0]);
      }
    });
  }
})();
