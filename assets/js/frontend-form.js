/**
 * Public registration form behaviour: toggles conditional fields (payment
 * method sub-fields), live-recalculates the displayed total, and — for the
 * applicant photo + bank receipt uploads — client-side compresses/resizes
 * large smartphone photos before submission while validating format and size.
 *
 * Reads fee configuration from the ReunionRegConfig object, which PHP
 * injects via wp_localize_script() (see class-frontend-form.php).
 *
 * Compression strategy (keeps TxID/text and faces readable):
 *  - Allow JPG, PNG, WebP only; reject anything else immediately.
 *  - Helper limit shown to user: 2 MB. Files already under ~900 KB skip
 *    compression. Larger files (5-10 MB phone shots) are the target.
 *  - Resize so max(width, height) <= 1200 px, keep aspect ratio.
 *  - Compress via Canvas to JPEG @ 0.82 quality (ideal sharpness/size trade-off).
 *  - Replace the <input> file with the compressed blob via DataTransfer so
 *    the actual POST sends the optimized file. Falls back to original file if
 *    compression APIs unavailable — server handles the rest.
 */
(function () {
    var UPLOAD_MAX_BYTES = 2 * 1024 * 1024;
    var UPLOAD_TARGET_BYTES = 2 * 1024 * 1024;
    var UPLOAD_MAX_DIM = 1200;
    var UPLOAD_QUALITY = 0.82;
    var UPLOAD_ALLOWED_TYPES = { 'image/jpeg': 1, 'image/png': 1, 'image/webp': 1 };
    var UPLOAD_ALLOWED_LABEL = 'JPG, PNG, WebP';
    var UPLOAD_INPUT_IDS = ['reunion_applicant_photo', 'reunion_payment_receipt'];

    function escText(s) { return String(s == null ? '' : s); }

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function syncConditionalFields() {
        document.querySelectorAll('.reunion-conditional').forEach(function (el) {
            var depField = el.getAttribute('data-depends-field');
            var depValue = el.getAttribute('data-depends-value');
            var checkedInput = document.querySelector('input[name="' + depField + '"]:checked');
            var matches = !!(checkedInput && checkedInput.value === depValue);
            el.style.display = matches ? '' : 'none';
            var input = el.querySelector('[data-conditional-required="1"]');
            if (input) input.required = matches;
        });
    }

    function calcTotal() {
        if (typeof window.ReunionRegConfig === 'undefined') return;
        var fee = parseFloat(window.ReunionRegConfig.registrationFee) || 0;
        var guestFee = parseFloat(window.ReunionRegConfig.guestFee) || 0;
        var guestEl = document.getElementById('reunion_guest_count');
        var donEl = document.getElementById('reunion_donation');
        var out = document.getElementById('reunion_total_number');
        if (!out) return;
        var guests = guestEl ? Math.max(0, parseFloat(guestEl.value) || 0) : 0;
        var donation = donEl ? Math.max(0, parseFloat(donEl.value) || 0) : 0;
        out.textContent = (fee + guests * guestFee + donation).toLocaleString('en-US');
    }

    function fallbackCopy(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        done();
    }

    function copyText(text, btn) {
        var done = function () {
            if (!btn) return;
            btn.classList.add('is-copied');
            clearTimeout(btn._reunionCopyTimer);
            btn._reunionCopyTimer = setTimeout(function () { btn.classList.remove('is-copied'); }, 2000);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
        } else fallbackCopy(text, done);
    }

    function setFieldError(input, message) {
        var err = document.getElementById(input.id + '_error');
        var status = document.getElementById(input.id + '_status');
        if (status && message) { status.hidden = true; status.textContent = ''; }
        if (!err) return;
        if (!message) {
            err.hidden = true; err.textContent = '';
            input.removeAttribute('aria-invalid');
            input.setCustomValidity('');
            return;
        }
        err.textContent = escText(message);
        err.hidden = false;
        input.setAttribute('aria-invalid', 'true');
        input.setCustomValidity(escText(message));
    }

    function setFieldStatus(input, message, isBusy) {
        var el = document.getElementById(input.id + '_status');
        if (!el) return;
        if (!message) {
            el.hidden = true; el.textContent = '';
            el.removeAttribute('aria-busy');
            return;
        }
        el.textContent = escText(message);
        el.hidden = false;
        if (isBusy) el.setAttribute('aria-busy', 'true');
        else el.removeAttribute('aria-busy');
    }

    function setFilename(input, text, isError, isChosen) {
        var el = document.getElementById(input.id + '_filename');
        if (!el) return;
        el.textContent = escText(text);
        el.classList.toggle('is-error', !!isError);
        el.classList.toggle('is-chosen', !!isChosen);
    }

    function isAllowedImageFile(file) {
        if (!file) return false;
        var t = (file.type || '').toLowerCase();
        if (UPLOAD_ALLOWED_TYPES[t]) return true;
        var name = (file.name || '').toLowerCase();
        return /\.(jpe?g|png|webp)$/.test(name);
    }

    function canvasToBlob(canvas, mime, quality) {
        return new Promise(function (resolve, reject) {
            if (typeof canvas.toBlob === 'function') {
                canvas.toBlob(function (blob) {
                    if (blob) resolve(blob);
                    else reject(new Error('toBlob null'));
                }, mime, quality);
            } else {
                try {
                    var dataUrl = canvas.toDataURL(mime, quality);
                    var parts = dataUrl.split(',');
                    var bstr = atob(parts[1]);
                    var u8 = new Uint8Array(bstr.length);
                    for (var i = 0; i < bstr.length; i++) u8[i] = bstr.charCodeAt(i);
                    resolve(new Blob([u8], { type: mime }));
                } catch (e) { reject(e); }
            }
        });
    }

    function loadImageFromFile(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('decode failed')); };
            img.src = url;
        });
    }

    function compressedFileName(originalName) {
        var base = (originalName || 'photo').replace(/\.[^.]+$/, '') || 'photo';
        base = base.replace(/[^a-zA-Z0-9._-]+/g, '-').replace(/^-+|-+$/g, '') || 'photo';
        return base + '.jpg';
    }

    function replaceInputFile(input, blob, originalName) {
        var name = compressedFileName(originalName);
        var file;
        try { file = new File([blob], name, { type: blob.type || 'image/jpeg', lastModified: Date.now() }); }
        catch (e) { file = blob; try { file.name = name; } catch (_) {} }
        if (typeof DataTransfer !== 'undefined') {
            try {
                var dt = new DataTransfer();
                dt.items.add(file);
                input.files = dt.files;
                return true;
            } catch (e) {}
        }
        return false;
    }

    async function compressImageFile(file) {
        var img = await loadImageFromFile(file);
        var width = img.naturalWidth || img.width;
        var height = img.naturalHeight || img.height;
        if (!width || !height) return null;
        var maxSide = Math.max(width, height);
        var scale = maxSide > UPLOAD_MAX_DIM ? (UPLOAD_MAX_DIM / maxSide) : 1;
        if (scale >= 1 && file.size <= UPLOAD_TARGET_BYTES) {
            var isPng = /^image\/png$/i.test(file.type || '');
            if (!isPng) return null;
        }
        var w = Math.max(1, Math.round(width * scale));
        var h = Math.max(1, Math.round(height * scale));
        var canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        var ctx = canvas.getContext('2d');
        if (!ctx) return null;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, w, h);
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, w, h);
        var blob = await canvasToBlob(canvas, 'image/jpeg', UPLOAD_QUALITY);
        if (!blob || !blob.size) return null;
        if (blob.size >= file.size && scale >= 1) return null;
        return blob;
    }

    function wireOneUpload(input) {
        var form = input.closest('form');
        var submitBtn = form ? form.querySelector('[type="submit"]') : null;
        var pendingPromise = null;
        var isReceipt = input.id === 'reunion_payment_receipt';

        function isVisible() {
            var wrapper = input.closest('.reunion-conditional');
            if (!wrapper) return true;
            if (wrapper.style.display === 'none') return false;
            return wrapper.offsetParent !== null || getComputedStyle(wrapper).display !== 'none';
        }

        function setBusy(busy, text) {
            if (submitBtn) submitBtn.disabled = !!busy;
            input.setAttribute('aria-busy', busy ? 'true' : 'false');
            if (busy) setFieldStatus(input, text || 'Optimizing image...', true);
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0] ? input.files[0] : null;
            setFieldError(input, '');
            setFieldStatus(input, '', false);

            if (!file) {
                setFilename(input, 'No file chosen', false, false);
                return;
            }

            if (!isAllowedImageFile(file)) {
                setFieldError(input, 'অসমর্থিত ফরম্যাট। অনুগ্রহ করে ' + UPLOAD_ALLOWED_LABEL + ' ফাইল আপলোড করুন।');
                setFilename(input, file.name + ' — অসমর্থিত ফরম্যাট', true, false);
                input.value = '';
                return;
            }

            setFilename(input, file.name + ' (' + formatBytes(file.size) + ')', false, true);

            if (file.size < 900 * 1024 && file.size <= UPLOAD_TARGET_BYTES) {
                setFieldStatus(input, 'Ready — ' + formatBytes(file.size), false);
                return;
            }

            setBusy(true, 'Optimizing image...');
            var p = compressImageFile(file).then(function (blob) {
                if (p !== pendingPromise) return;
                if (blob && blob.size) {
                    var ok = replaceInputFile(input, blob, file.name);
                    if (ok) {
                        var outFile = input.files && input.files[0] ? input.files[0] : blob;
                        var saved = file.size - outFile.size;
                        var msg = 'Optimized — ' + formatBytes(outFile.size);
                        if (saved > 0) msg += ' (saved ' + formatBytes(saved) + ')';
                        setFieldStatus(input, msg, false);
                        setFilename(input, outFile.name + ' (' + formatBytes(outFile.size) + ')', false, true);
                    } else {
                        if (blob.size < file.size) {
                            setFieldStatus(input, 'Optimized ' + formatBytes(file.size) + ' → ' + formatBytes(blob.size) + ' (upload will use optimized image).', false);
                        } else setFieldStatus(input, '', false);
                    }
                    setFieldError(input, '');
                } else {
                    if (file.size > UPLOAD_MAX_BYTES) {
                        setFieldError(input, 'ফাইলটি অনেক বড় (' + formatBytes(file.size) + ')। সর্বোচ্চ ' + formatBytes(UPLOAD_MAX_BYTES) + ' অনুমোদিত। অনুগ্রহ করে ছোট ছবি বেছে নিন।');
                        setFilename(input, file.name + ' — অনেক বড়', true, false);
                        input.value = '';
                        setFieldStatus(input, '', false);
                    } else {
                        setFieldStatus(input, 'Ready — ' + formatBytes(file.size), false);
                    }
                }
            }).catch(function () {
                if (p !== pendingPromise) return;
                setFieldStatus(input, 'Ready — ' + formatBytes(file.size), false);
            }).then(function () {
                if (p === pendingPromise) { pendingPromise = null; setBusy(false); }
            });
            pendingPromise = p;
        });

        input.addEventListener('click', function () { setFieldError(input, ''); });

        if (form) {
            form.addEventListener('submit', function (e) {
                if (isReceipt && !isVisible()) return;
                var f = input.files && input.files[0] ? input.files[0] : null;
                if (input.hasAttribute('required') && isVisible() && !f) return;
                if (!f) return;
                if (!isAllowedImageFile(f)) {
                    e.preventDefault();
                    setFieldError(input, 'অসমর্থিত ফরম্যাট। অনুগ্রহ করে ' + UPLOAD_ALLOWED_LABEL + ' ফাইল আপলোড করুন।');
                    input.focus();
                    return;
                }
                if (f.size > UPLOAD_MAX_BYTES) {
                    e.preventDefault();
                    setFieldError(input, 'ফাইলটি অনেক বড় (' + formatBytes(f.size) + ')। সর্বোচ্চ ' + formatBytes(UPLOAD_MAX_BYTES) + ' অনুমোদিত।');
                    input.focus();
                    return;
                }
                if (pendingPromise) {
                    e.preventDefault();
                    setBusy(true, 'Optimizing image...');
                    pendingPromise.then(function () {
                        if (input.getAttribute('aria-invalid') === 'true') return;
                        setBusy(false);
                        if (typeof form.requestSubmit === 'function') form.requestSubmit(submitBtn || undefined);
                        else form.submit();
                    });
                }
            });
        }

        input._reunionPending = function () { return pendingPromise; };
        return input;
    }

    function wireUploadCompression() {
        var wired = [];
        UPLOAD_INPUT_IDS.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) wired.push(wireOneUpload(el));
        });
        if (!wired.length) return;
        var form = wired[0].closest('form');
        if (!form || form._reunionUploadWired) return;
        form._reunionUploadWired = true;
        form.addEventListener('submit', function (e) {
            var pending = null;
            wired.forEach(function (inp) {
                var p = inp._reunionPending && inp._reunionPending();
                if (p) pending = p;
            });
            if (!pending) return;
            var submitBtn = form.querySelector('[type="submit"]');
            e.preventDefault();
            wired.forEach(function (inp) {
                if (inp._reunionPending && inp._reunionPending()) {
                    inp.setAttribute('aria-busy', 'true');
                    setFieldStatus(inp, 'Optimizing image...', true);
                    if (submitBtn) submitBtn.disabled = true;
                }
            });
            pending.then(function () {
                var hasError = wired.some(function (inp) { return inp.getAttribute('aria-invalid') === 'true'; });
                if (hasError) {
                    if (submitBtn) submitBtn.disabled = false;
                    wired.forEach(function (inp) { inp.removeAttribute('aria-busy'); });
                    return;
                }
                wired.forEach(function (inp) { inp.removeAttribute('aria-busy'); });
                if (submitBtn) submitBtn.disabled = false;
                if (typeof form.requestSubmit === 'function') form.requestSubmit(submitBtn || undefined);
                else form.submit();
            });
        });
    }

    document.addEventListener('click', function (e) {
        var target = e.target.closest('[data-copy-value]');
        if (!target || !target.closest('.reunion-pay-box')) return;
        var val = target.getAttribute('data-copy-value');
        if (!val) return;
        var btn;
        if (target.classList.contains('reunion-copy-btn')) btn = target;
        else {
            var person = target.closest('.reunion-pay-method');
            btn = person ? person.querySelector('.reunion-copy-btn') : null;
            if (!btn) btn = target;
        }
        copyText(val, btn);
    });

    document.addEventListener('DOMContentLoaded', function () {
        syncConditionalFields();
        calcTotal();
        wireUploadCompression();
        document.querySelectorAll('input[name="payment_channel"]').forEach(function (radio) {
            radio.addEventListener('change', syncConditionalFields);
        });
        ['reunion_guest_count', 'reunion_donation'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('input', calcTotal);
        });
    });
})();
