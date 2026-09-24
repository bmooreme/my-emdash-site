/**
 * Obituary Submissions — front-end form enhancements.
 *
 * Features:
 *  - Live image preview for the featured photo.
 *  - Gallery preview with per-image removal, drag-to-reorder, and max-image guard.
 *  - "Processing your submission…" message revealed only on form submit.
 *  - Submit-button loading state to prevent double-submission.
 */
(function () {
  'use strict';

  var cfg = window.obituaryFormConfig || {
    maxGallery: 9,
    maxSizeMB: 8,
    i18n: {
      tooManyImages: 'Too many images selected.',
      invalidType: 'Invalid file type.',
      fileTooLarge: 'File is too large.',
      remove: 'Remove',
    },
  };

  var MAX_SIZE_BYTES = cfg.maxSizeMB * 1024 * 1024;
  var ALLOWED_TYPES  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

  function isAllowedType(file) { return ALLOWED_TYPES.indexOf(file.type) !== -1; }
  function isAllowedSize(file) { return file.size <= MAX_SIZE_BYTES; }

  // ---- Featured image preview ------------------------------------------------

  function initFeaturedPreview() {
    var input   = document.getElementById('obituary_featured_image');
    var preview = document.getElementById('obituary-featured-preview');
    if (!input || !preview) return;

    input.addEventListener('change', function () {
      preview.innerHTML = '';
      var file = input.files && input.files[0];
      if (!file) return;

      if (!isAllowedType(file)) { alert(cfg.i18n.invalidType); input.value = ''; return; }
      if (!isAllowedSize(file)) { alert(cfg.i18n.fileTooLarge); input.value = ''; return; }

      var reader = new FileReader();
      reader.onload = function (e) {
        var img = document.createElement('img');
        img.src = e.target.result;
        img.alt = file.name;
        preview.appendChild(img);
      };
      reader.readAsDataURL(file);
    });
  }

  // ---- Gallery preview with drag-to-reorder ---------------------------------
  //
  // We keep a parallel JS array of File objects and rebuild the DataTransfer
  // on every change (add / remove / reorder) so the <input> always reflects the
  // current ordered selection.

  function initGalleryPreview() {
    var input   = document.getElementById('obituary_gallery');
    var preview = document.getElementById('obituary-gallery-preview');
    if (!input || !preview) return;

    var selectedFiles = [];
    var dragSrcIndex  = -1;

    // Counter element injected below the file input.
    var counter = document.createElement('p');
    counter.className = 'obituary-gallery-counter';
    input.parentNode.insertBefore(counter, preview);

    function updateCounter() {
      var n = selectedFiles.length;
      counter.textContent = n + ' / ' + cfg.maxGallery + ' photos selected';
      counter.className = 'obituary-gallery-counter' + (n >= cfg.maxGallery ? ' at-limit' : '');
    }

    function syncInputFiles() {
      try {
        var dt = new DataTransfer();
        selectedFiles.forEach(function (f) { dt.items.add(f); });
        input.files = dt.files;
      } catch (e) { /* DataTransfer not supported; input stays as-is */ }
    }

    // Build (or rebuild) the entire preview grid from the current selectedFiles.
    function rebuildPreview() {
      preview.innerHTML = '';
      selectedFiles.forEach(function (file, idx) {
        preview.appendChild(createPreviewItem(file, idx));
      });
      updateCounter();
    }

    // Create one draggable thumbnail tile.
    function createPreviewItem(file, idx) {
      var wrap = document.createElement('div');
      wrap.className = 'obituary-preview-item';
      wrap.setAttribute('draggable', 'true');
      wrap.title = cfg.i18n.dragToReorder || 'Drag to reorder';

      // Thumbnail image.
      var img = document.createElement('img');
      img.alt = file.name;
      var reader = new FileReader();
      reader.onload = function (e) { img.src = e.target.result; };
      reader.readAsDataURL(file);

      // Remove button.
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'obituary-preview-remove';
      btn.setAttribute('aria-label', cfg.i18n.remove + ': ' + file.name);
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        selectedFiles.splice(idx, 1);
        syncInputFiles();
        rebuildPreview();
      });

      // Drag handle indicator.
      var handle = document.createElement('span');
      handle.className = 'obituary-drag-handle';
      handle.setAttribute('aria-hidden', 'true');

      // ---- Drag events ----

      wrap.addEventListener('dragstart', function (e) {
        dragSrcIndex = idx;
        wrap.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        // Firefox requires data to be set.
        e.dataTransfer.setData('text/plain', String(idx));
      });

      wrap.addEventListener('dragend', function () {
        wrap.classList.remove('is-dragging');
        // Clear any stale drag-over highlights.
        preview.querySelectorAll('.obituary-preview-item').forEach(function (el) {
          el.classList.remove('drag-over');
        });
      });

      wrap.addEventListener('dragover', function (e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (idx !== dragSrcIndex) {
          wrap.classList.add('drag-over');
        }
      });

      wrap.addEventListener('dragleave', function () {
        wrap.classList.remove('drag-over');
      });

      wrap.addEventListener('drop', function (e) {
        e.preventDefault();
        wrap.classList.remove('drag-over');
        if (dragSrcIndex === -1 || dragSrcIndex === idx) return;

        // Remove dragged item and reinsert at drop position.
        var moved = selectedFiles.splice(dragSrcIndex, 1)[0];
        selectedFiles.splice(idx, 0, moved);
        dragSrcIndex = -1;

        syncInputFiles();
        rebuildPreview();
      });

      wrap.appendChild(handle);
      wrap.appendChild(img);
      wrap.appendChild(btn);
      return wrap;
    }

    // Handle new files chosen via the file input.
    input.addEventListener('change', function () {
      var newFiles = Array.prototype.slice.call(input.files || []);
      var rejected = false;

      newFiles.forEach(function (file) {
        if (!isAllowedType(file)) { alert(cfg.i18n.invalidType); rejected = true; return; }
        if (!isAllowedSize(file)) { alert(cfg.i18n.fileTooLarge); rejected = true; return; }
        selectedFiles.push(file);
      });

      if (selectedFiles.length > cfg.maxGallery) {
        selectedFiles = selectedFiles.slice(0, cfg.maxGallery);
        if (!rejected) alert(cfg.i18n.tooManyImages);
      }

      syncInputFiles();
      rebuildPreview();
    });

    updateCounter();
  }

  // ---- Submit: show processing message, disable button ----------------------

  function initSubmitState() {
    var form        = document.getElementById('obituary-submission-form');
    var btn         = document.getElementById('obituary-submit-btn');
    var processingMsg = document.getElementById('obituary-processing-msg');

    if (!form || !btn) return;

    form.addEventListener('submit', function () {
      // Let the browser run native validation first; only act if the form is valid.
      setTimeout(function () {
        if (form.checkValidity && !form.checkValidity()) return;

        btn.disabled = true;
        btn.classList.add('is-loading');

        if (processingMsg) {
          processingMsg.hidden = false;
        }
      }, 0);
    });
  }

  // ---- Boot -----------------------------------------------------------------

  function init() {
    initFeaturedPreview();
    initGalleryPreview();
    initSubmitState();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
