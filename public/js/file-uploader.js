/*
 * Uploader file materi (tanpa library, tanpa build step).
 *
 * Mirip komponen "File Upload – multiple": tombol Browse + area seret-lepas, daftar file dengan
 * ukuran, progress bar, tombol Upload / Jeda / Batal per file, dan Upload semua / Jeda semua / Batalkan semua.
 *
 * File dikirim bertahap per potongan (chunk) ke /manage/uploads, sehingga file besar tidak terbentur
 * batas upload hosting dan jeda bisa dilanjutkan dari posisi terakhir. Setelah selesai, ID upload
 * dimasukkan ke form sebagai uploads[]; file baru dipindah ke materi saat form disimpan.
 * Saat tombol Simpan ditekan, file yang belum terunggah diunggah dulu secara otomatis.
 */
(function () {
    'use strict';

    var TYPES = {
        mp4: 'video', webm: 'video', mov: 'video',
        pdf: 'pdf',
        ppt: 'ppt', pptx: 'ppt'
    };
    var ICONS = { video: '🎬', pdf: '📄', ppt: '📊' };
    var LABELS = { video: 'Video', pdf: 'PDF', ppt: 'PPT' };

    function human(bytes) {
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1).replace('.', ',') + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
        return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;
        return e;
    }

    function errorMessage(xhrOrJson, fallback) {
        try {
            var data = typeof xhrOrJson === 'string' ? JSON.parse(xhrOrJson) : xhrOrJson;
            if (data && data.errors) {
                var first = Object.keys(data.errors)[0];
                if (first) return data.errors[first][0];
            }
            if (data && data.message) return data.message;
        } catch (e) { /* bukan JSON */ }
        return fallback;
    }

    function Uploader(root) {
        this.root = root;
        this.form = root.closest('form');
        this.startUrl = root.dataset.startUrl;
        this.baseUrl = root.dataset.baseUrl;
        this.maxBytes = JSON.parse(root.dataset.maxBytes);
        this.maxCount = parseInt(root.dataset.maxCount, 10);
        this.existing = JSON.parse(root.dataset.existing || '{}');
        this.csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;
        this.items = [];
        this.list = root.querySelector('[data-list]');
        this.footer = root.querySelector('[data-footer]');
        this.summary = root.querySelector('[data-summary]');
        this.inputs = root.querySelector('[data-inputs]');
        this.input = root.querySelector('input[type=file]');
        this.notice = root.querySelector('[data-notice]');
        this.submitting = false;
        this.bind();
    }

    Uploader.prototype.bind = function () {
        var self = this;
        var drop = this.root.querySelector('[data-drop]');

        this.root.querySelector('[data-browse]').addEventListener('click', function () { self.input.click(); });
        this.input.addEventListener('change', function () {
            self.add(self.input.files);
            self.input.value = '';
        });

        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
        });
        drop.addEventListener('drop', function (e) { self.add(e.dataTransfer.files); });

        this.root.querySelector('[data-upload-all]').addEventListener('click', function () { self.uploadAll(); });
        this.root.querySelector('[data-pause-all]').addEventListener('click', function () {
            self.items.forEach(function (it) { if (it.state === 'uploading') self.pause(it); });
        });
        this.root.querySelector('[data-cancel-all]').addEventListener('click', function () {
            self.items.slice().forEach(function (it) { if (it.state !== 'done') self.cancel(it); });
        });

        // File yang sudah tersimpan dan dicentang hapus tidak dihitung dalam batas jumlah.
        this.form.querySelectorAll('input[name="remove_files[]"]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var type = cb.dataset.type;
                self.existing[type] = (self.existing[type] || 0) + (cb.checked ? -1 : 1);
            });
        });

        // Simpan: unggah dulu file yang belum selesai, baru kirim form.
        this.form.addEventListener('submit', function (e) {
            if (self.submitting) return;
            var pending = self.items.filter(function (it) { return it.state !== 'done'; });
            if (!pending.length) return;
            e.preventDefault();
            self.setNotice('Mengunggah ' + pending.length + ' file sebelum menyimpan… jangan tutup halaman.', 'info');
            self.uploadAll().then(function () {
                var failed = self.items.filter(function (it) { return it.state !== 'done'; });
                if (failed.length) {
                    self.setNotice(failed.length + ' file belum terunggah. Perbaiki atau batalkan file tersebut, lalu tekan Simpan lagi.', 'error');
                    return;
                }
                self.submitting = true;
                self.setNotice('Menyimpan…', 'info');
                HTMLFormElement.prototype.submit.call(self.form);
            });
        });

        window.addEventListener('beforeunload', function (e) {
            if (!self.submitting && self.items.some(function (it) { return it.state === 'uploading'; })) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    };

    Uploader.prototype.setNotice = function (text, kind) {
        this.notice.textContent = text || '';
        this.notice.className = 'uploader-notice' + (kind ? ' ' + kind : '');
        this.notice.hidden = !text;
    };

    Uploader.prototype.countOf = function (type) {
        var n = this.existing[type] || 0;
        this.items.forEach(function (it) { if (it.type === type && it.state !== 'rejected') n++; });
        return n;
    };

    Uploader.prototype.add = function (files) {
        var self = this;
        Array.prototype.forEach.call(files, function (file) {
            var ext = (file.name.split('.').pop() || '').toLowerCase();
            var type = TYPES[ext];
            var item = { file: file, type: type, id: null, offset: 0, state: 'pending', xhr: null, error: '' };

            if (!type) {
                item.state = 'rejected';
                item.error = 'Jenis file tidak didukung (pakai MP4/WEBM/MOV, PDF, atau PPT/PPTX).';
            } else if (file.size > self.maxBytes[type]) {
                item.state = 'rejected';
                item.error = LABELS[type] + ' maksimal ' + human(self.maxBytes[type]) + '.';
            } else if (self.countOf(type) >= self.maxCount) {
                item.state = 'rejected';
                item.error = 'Maksimal ' + self.maxCount + ' file ' + LABELS[type] + ' per materi.';
            }
            self.items.push(item);
            self.render(item);
        });
        this.refresh();
    };

    Uploader.prototype.render = function (item) {
        var self = this;
        var li = el('li', 'uploader-item');
        var icon = el('span', 'uploader-icon', item.type ? ICONS[item.type] : '⚠️');
        var info = el('div', 'uploader-info');
        var name = el('p', 'uploader-name', item.file.name);
        var meta = el('span', 'uploader-meta');
        var bar = el('div', 'uploader-bar');
        var fill = el('span');
        bar.appendChild(fill);
        info.appendChild(name);
        info.appendChild(meta);
        info.appendChild(bar);

        var actions = el('div', 'uploader-actions');
        var btnUpload = el('button', 'btn small', 'Upload');
        var btnPause = el('button', 'btn small secondary', 'Jeda');
        var btnCancel = el('button', 'btn small secondary', 'Batal');
        [btnUpload, btnPause, btnCancel].forEach(function (b) { b.type = 'button'; actions.appendChild(b); });
        btnUpload.addEventListener('click', function () { self.upload(item); });
        btnPause.addEventListener('click', function () { self.pause(item); });
        btnCancel.addEventListener('click', function () { self.cancel(item); });

        li.appendChild(icon);
        li.appendChild(info);
        li.appendChild(actions);
        this.list.appendChild(li);
        item.ui = { li: li, meta: meta, fill: fill, bar: bar, upload: btnUpload, pause: btnPause, cancel: btnCancel };
        this.update(item);
    };

    Uploader.prototype.update = function (item) {
        var ui = item.ui;
        var size = item.file.size;
        var pct = size ? Math.min(100, Math.round(item.offset / size * 100)) : 0;
        var status = {
            pending: 'Siap diunggah',
            uploading: 'Mengunggah ' + pct + '%',
            paused: 'Dijeda di ' + pct + '%',
            done: 'Selesai',
            error: item.error,
            rejected: item.error
        }[item.state];

        ui.li.className = 'uploader-item ' + item.state;
        ui.meta.textContent = (item.type ? LABELS[item.type] + ' · ' : '') + human(size) + ' · ' + status;
        ui.fill.style.width = (item.state === 'done' ? 100 : pct) + '%';
        ui.bar.hidden = item.state === 'rejected';
        ui.upload.hidden = !(item.state === 'pending' || item.state === 'paused' || item.state === 'error');
        ui.upload.textContent = item.state === 'paused' ? 'Lanjut' : (item.state === 'error' ? 'Ulangi' : 'Upload');
        ui.pause.hidden = item.state !== 'uploading';
        ui.cancel.textContent = item.state === 'done' ? 'Hapus' : (item.state === 'rejected' ? 'Tutup' : 'Batal');
    };

    Uploader.prototype.refresh = function () {
        var visible = this.items.length > 0;
        this.footer.hidden = !visible;
        var done = this.items.filter(function (it) { return it.state === 'done'; }).length;
        var waiting = this.items.filter(function (it) { return ['pending', 'paused', 'error'].indexOf(it.state) >= 0; }).length;
        var active = this.items.filter(function (it) { return it.state === 'uploading'; }).length;
        this.summary.textContent = done + ' selesai' + (active ? ' · ' + active + ' sedang diunggah' : '') + (waiting ? ' · ' + waiting + ' menunggu' : '');
        this.root.querySelector('[data-upload-all]').disabled = !waiting;
        this.root.querySelector('[data-pause-all]').disabled = !active;

        // Masukkan ID upload yang selesai ke form.
        this.inputs.innerHTML = '';
        var self = this;
        this.items.forEach(function (it) {
            if (it.state === 'done' && it.id) {
                var input = el('input');
                input.type = 'hidden';
                input.name = 'uploads[]';
                input.value = it.id;
                self.inputs.appendChild(input);
            }
        });
    };

    Uploader.prototype.request = function (method, url, body, onProgress) {
        var self = this;
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open(method, url);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            if (self.csrf) xhr.setRequestHeader('X-CSRF-TOKEN', self.csrf);
            if (onProgress) xhr.upload.addEventListener('progress', onProgress);
            xhr.onload = function () { resolve(xhr); };
            xhr.onerror = function () { reject(new Error('Koneksi terputus. Coba lagi.')); };
            xhr.onabort = function () { reject(new Error('abort')); };
            xhr.send(body);
            resolve.xhr = xhr;
            self.currentXhr = xhr;
        });
    };

    Uploader.prototype.upload = function (item) {
        var self = this;
        if (['pending', 'paused', 'error'].indexOf(item.state) < 0) return Promise.resolve();
        item.state = 'uploading';
        item.error = '';
        this.update(item);
        this.refresh();

        var started = item.id ? Promise.resolve() : (function () {
            var body = new FormData();
            body.append('name', item.file.name);
            body.append('size', item.file.size);
            return self.request('POST', self.startUrl, body).then(function (xhr) {
                if (xhr.status !== 201) throw new Error(errorMessage(xhr.responseText, 'Gagal memulai unggahan (' + xhr.status + ').'));
                var data = JSON.parse(xhr.responseText);
                item.id = data.id;
                item.chunkSize = data.chunk_size;
                item.offset = data.received;
            });
        })();

        return started.then(function next() {
            if (item.state !== 'uploading') return;
            var end = Math.min(item.offset + (item.chunkSize || 5242880), item.file.size);
            var body = new FormData();
            body.append('offset', item.offset);
            body.append('chunk', item.file.slice(item.offset, end), 'chunk');
            var base = item.offset;

            var p = self.request('POST', self.baseUrl + '/' + item.id + '/chunk', body, function (e) {
                if (e.lengthComputable && item.state === 'uploading') {
                    item.offset = base + Math.min(e.loaded, end - base);
                    self.update(item);
                    item.offset = base;
                }
            });
            item.xhr = self.currentXhr;
            return p.then(function (xhr) {
                if (xhr.status !== 200 && xhr.status !== 409) {
                    throw new Error(errorMessage(xhr.responseText, 'Unggahan gagal (' + xhr.status + ').'));
                }
                var data = JSON.parse(xhr.responseText);
                item.offset = data.received;
                if (data.complete) {
                    item.state = 'done';
                    self.update(item);
                    self.refresh();
                    return;
                }
                self.update(item);
                return next();
            });
        }).catch(function (err) {
            if (err.message === 'abort') return; // dijeda atau dibatalkan
            item.state = 'error';
            item.error = err.message;
            self.update(item);
            self.refresh();
        });
    };

    Uploader.prototype.uploadAll = function () {
        var self = this;
        // Satu per satu supaya tidak membebani server/hosting.
        return this.items.reduce(function (chain, it) {
            return chain.then(function () {
                if (['pending', 'paused', 'error'].indexOf(it.state) >= 0) return self.upload(it);
            });
        }, Promise.resolve());
    };

    Uploader.prototype.pause = function (item) {
        if (item.state !== 'uploading') return;
        item.state = 'paused';
        if (item.xhr) item.xhr.abort();
        this.update(item);
        this.refresh();
    };

    Uploader.prototype.cancel = function (item) {
        var wasActive = item.state === 'uploading';
        item.state = 'cancelled';
        if (wasActive && item.xhr) item.xhr.abort();
        if (item.id) this.request('DELETE', this.baseUrl + '/' + item.id).catch(function () {});
        item.ui.li.remove();
        this.items.splice(this.items.indexOf(item), 1);
        this.refresh();
    };

    document.querySelectorAll('[data-uploader]').forEach(function (root) { new Uploader(root); });
})();
